<?php

declare(strict_types=1);

/**
 * Load the same local contribution policy validated by profile-policy.sh.
 *
 * @return array{username:string,excluded_repositories:non-empty-list<non-empty-string>}
 * @throws RuntimeException When the local policy cannot be read.
 * @throws InvalidArgumentException When JSON or policy fields are invalid.
 */
function loadProfilePolicy(string $path): array
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '://') || !is_file($path)) {
        throw new RuntimeException('Unable to read profile policy.');
    }
    // Suppress filesystem diagnostics so failures disclose neither paths nor contents.
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read profile policy.');
    }
    try {
        $policy = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid profile policy.');
    }
    if (!$policy instanceof stdClass
        || ($policy->username ?? null) !== 'orsnaro'
        || !isset($policy->excluded_repositories)
        || !is_array($policy->excluded_repositories)
        || $policy->excluded_repositories === []
    ) {
        throw new InvalidArgumentException('Invalid profile policy.');
    }
    $seen = [];
    foreach ($policy->excluded_repositories as $repository) {
        if (!is_string($repository)
            || preg_match('/\Aorsnaro\/[A-Za-z0-9_.-]+\z/', $repository) !== 1
            || $repository === 'orsnaro/.'
            || $repository === 'orsnaro/..'
            || isset($seen[strtolower($repository)])
        ) {
            throw new InvalidArgumentException('Invalid profile policy.');
        }
        $seen[strtolower($repository)] = true;
    }

    return ['username' => $policy->username, 'excluded_repositories' => $policy->excluded_repositories];
}

/** @internal Check an exact Gregorian day in the supported 0001..9999 range. */
function isContributionDay(string $date): bool
{
    return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/**
 * Normalize an RFC3339 contribution timestamp to its UTC calendar day.
 *
 * @return non-empty-string An exact UTC Y-m-d day in 0001..9999.
 * @throws InvalidArgumentException When any timestamp or UTC date component is invalid.
 */
function contributionDate(string $occurredAt): string
{
    if (preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]+)?(Z|([+-])([0-9]{2}):([0-9]{2}))\z/', $occurredAt, $parts) !== 1
        || !isContributionDay($parts[1])
        || (int) $parts[2] > 23
        || (int) $parts[3] > 59
        || (int) $parts[4] > 59
        || ($parts[5] !== 'Z' && ((int) $parts[7] > 23 || (int) $parts[8] > 59))
    ) {
        throw new InvalidArgumentException('Invalid contribution timestamp.');
    }
    // Subsecond precision cannot change the date; avoid PHP's fractional-digit limit.
    $seconds = substr($occurredAt, 0, 19) . ($parts[5] === 'Z' ? '+00:00' : $parts[5]);
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $seconds);
    $errors = DateTimeImmutable::getLastErrors();
    if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Invalid contribution timestamp.');
    }
    $date = $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    if (!isContributionDay($date)) {
        throw new InvalidArgumentException('Invalid contribution timestamp.');
    }

    return $date;
}

/**
 * Validate and deduplicate identifiable records before applying either omission rule.
 * Filtered records still retain zero dates and identities for coverage/conflict checks.
 *
 * @param array<string,non-negative-int> $days
 * @param array<string,array{key:non-empty-string,repositoryId:non-empty-string,date:non-empty-string,weight:non-negative-int,restricted:bool}> $seen
 * @param array{key:string,repositoryId:string,date:string,weight:int,restricted:bool} $record
 * @param list<non-empty-string> $excludedIds
 * @throws InvalidArgumentException When a record, exclusion list, or existing day weight is invalid.
 * @throws UnexpectedValueException When one stable identity has conflicting fields.
 * @throws OverflowException When eligible daily weights exceed PHP_INT_MAX.
 */
function addContributionRecord(array &$days, array &$seen, array $record, array $excludedIds): void
{
    if (!isset($record['key'], $record['repositoryId'], $record['date'], $record['weight'], $record['restricted'])
        || !is_string($record['key']) || trim($record['key']) === ''
        || !is_string($record['repositoryId']) || trim($record['repositoryId']) === ''
        || !is_string($record['date']) || !isContributionDay($record['date'])
        || !is_int($record['weight']) || $record['weight'] < 0
        || !is_bool($record['restricted'])
        || !array_is_list($excludedIds)
    ) {
        throw new InvalidArgumentException('Invalid contribution record.');
    }
    foreach ($excludedIds as $repositoryId) {
        if (!is_string($repositoryId) || trim($repositoryId) === '') {
            throw new InvalidArgumentException('Invalid contribution record.');
        }
    }
    $date = $record['date'];
    if (array_key_exists($date, $days) && (!is_int($days[$date]) || $days[$date] < 0)) {
        throw new InvalidArgumentException('Invalid contribution day weight.');
    }
    // Canonical field order makes identity equality independent of API object ordering.
    $identity = [
        'key' => $record['key'],
        'repositoryId' => $record['repositoryId'],
        'date' => $date,
        'weight' => $record['weight'],
        'restricted' => $record['restricted'],
    ];
    if (array_key_exists($record['key'], $seen)) {
        if ($seen[$record['key']] !== $identity) {
            throw new UnexpectedValueException('Conflicting contribution identity.');
        }
        return;
    }
    $weight = $record['restricted'] || in_array($record['repositoryId'], $excludedIds, true)
        ? 0 : $record['weight'];
    $current = $days[$date] ?? 0;
    if ($weight > PHP_INT_MAX - $current) {
        throw new OverflowException('Contribution day weight overflow.');
    }
    // All validation succeeds before either by-reference accumulator changes.
    $seen[$record['key']] = $identity;
    $days[$date] = $current + $weight;
}

/**
 * Fill every UTC day from the earliest reported/start year through today.
 * Preserve upstream's positive-tomorrow bucket, but no later future activity.
 *
 * @param array<string,non-negative-int> $counts
 * @return array<string,non-negative-int> Sorted, gap-free history plus positive tomorrow only.
 * @throws InvalidArgumentException When a date/count, UTC now, or start year is invalid.
 */
function fillContributionDates(array $counts, int $startYear, DateTimeImmutable $now): array
{
    $utc = new DateTimeZone('UTC');
    $today = $now->setTimezone($utc)->setTime(0, 0, 0);
    $todayDate = $today->format('Y-m-d');
    if (!isContributionDay($todayDate)) {
        throw new InvalidArgumentException('Invalid contribution date.');
    }
    if ($startYear < 1 || $startYear > (int) $today->format('Y')) {
        throw new InvalidArgumentException('Invalid contribution start year.');
    }
    $earliestYear = $startYear;
    foreach ($counts as $date => $weight) {
        if (!is_string($date) || !isContributionDay($date)) {
            throw new InvalidArgumentException('Invalid contribution date.');
        }
        if (!is_int($weight) || $weight < 0) {
            throw new InvalidArgumentException('Invalid contribution day weight.');
        }
        $earliestYear = min($earliestYear, (int) substr($date, 0, 4));
    }
    $filled = [];
    $date = new DateTimeImmutable(sprintf('%04d-01-01', $earliestYear), $utc);
    while ($date <= $today) {
        $day = $date->format('Y-m-d');
        $filled[$day] = $counts[$day] ?? 0;
        $date = $date->modify('+1 day');
    }
    $tomorrow = $today->modify('+1 day')->format('Y-m-d');
    if (isset($counts[$tomorrow]) && $counts[$tomorrow] > 0) {
        $filled[$tomorrow] = $counts[$tomorrow];
    }

    return $filled;
}

/**
 * Decode only a complete HTTP/GraphQL success, without exposing response contents.
 *
 * @return array<string,mixed>
 * @throws RuntimeException When transport, JSON, or GraphQL reports failure.
 */
function parseGitHubResponse(int $status, string $body): array
{
    if ($status !== 200) {
        throw new RuntimeException('GitHub transport failed.');
    }
    try {
        $response = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Invalid GitHub response.');
    }
    if (!$response instanceof stdClass
        || !isset($response->data) || !$response->data instanceof stdClass
        || get_object_vars($response->data) === []
        || (property_exists($response, 'errors') && (!is_array($response->errors) || $response->errors !== []))
    ) {
        throw new RuntimeException('Invalid GitHub response.');
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR)['data'];
}

/**
 * Fixed-endpoint, bounded HTTPS transport. Never log bodies, headers, or cURL errors.
 *
 * @param array<string,mixed> $variables
 * @return array<string,mixed>
 */
function githubGraphql(string $token, string $query, array $variables): array
{
    if (preg_match('/\A[\x21-\x7e]+\z/', $token) !== 1 || trim($query) === '') {
        throw new InvalidArgumentException('Invalid GitHub request.');
    }
    try {
        $body = json_encode(['query' => $query, 'variables' => (object) $variables], JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid GitHub request.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('GitHub transport unavailable.');
    }
    $handle = null;
    try {
        $handle = @curl_init('https://api.github.com/graphql');
        if ($handle === false || !@curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'filtered-api-visible-streak',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ])) {
            throw new RuntimeException('GitHub transport failed.');
        }
        $response = @curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($response) || curl_errno($handle) !== 0) {
            throw new RuntimeException('GitHub transport failed.');
        }
    } catch (Throwable) {
        throw new RuntimeException('GitHub transport failed.');
    } finally {
        // PHP 8 CurlHandle lifetime owns cleanup; curl_close is deprecated in 8.5.
        unset($handle);
    }

    return parseGitHubResponse($status, $response);
}

/** @internal Validate an API count without coercion or clamping. */
function contributionApiCount(mixed $value): int
{
    if (!is_int($value) || $value < 0) {
        throw new UnexpectedValueException('Invalid contribution count.');
    }

    return $value;
}

/** @internal Sum unfiltered weights safely for completeness, including restricted records. */
function contributionWeightSum(array $records): int
{
    $total = 0;
    foreach ($records as $record) {
        $weight = contributionApiCount($record['weight'] ?? null);
        if ($weight > PHP_INT_MAX - $total) {
            throw new OverflowException('Contribution count overflow.');
        }
        $total += $weight;
    }

    return $total;
}

/** @internal Opaque IDs/cursors must be nonempty strings, not payload-like values. */
function isContributionIdentity(mixed $value): bool
{
    return is_string($value) && $value !== '' && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
}

/** @internal Reject invalid query inputs before invoking any request. */
function validateContributionUsername(string $username): void
{
    if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/', $username) !== 1) {
        throw new InvalidArgumentException('Invalid contribution username.');
    }
}

/**
 * @internal The injected boundary returns validated data; suppress arbitrary callback diagnostics.
 * @param callable(string,array<string,mixed>):array<string,mixed> $request
 * @return array<string,mixed>
 */
function requestContributionData(callable $request, string $query, array $variables): array
{
    try {
        $data = $request($query, $variables);
    } catch (Throwable) {
        throw new RuntimeException('GitHub request failed.');
    }
    if (!is_array($data) || !is_array($data['rateLimit'] ?? null)) {
        throw new UnexpectedValueException('Invalid GitHub rate limit.');
    }
    $remaining = contributionApiCount($data['rateLimit']['remaining'] ?? null);
    if ($remaining === 0) {
        throw new RuntimeException('GitHub rate limit exhausted.');
    }

    return $data;
}

/** @internal @return array{DateTimeImmutable,DateTimeImmutable} Inclusive UTC annual range. */
function contributionAnnualRange(int $year): array
{
    if ($year < 1 || $year > 9999) {
        throw new InvalidArgumentException('Invalid contribution year.');
    }
    $utc = new DateTimeZone('UTC');

    return [
        new DateTimeImmutable(sprintf('%04d-01-01T00:00:00Z', $year), $utc),
        new DateTimeImmutable(sprintf('%04d-12-31T23:59:59.999999Z', $year), $utc),
    ];
}

/** @internal Preserve fractional inclusive endpoints; all request variables are UTC. */
function contributionApiTime(DateTimeImmutable $time): string
{
    $utcTime = $time->setTimezone(new DateTimeZone('UTC'));

    return $utcTime->format($utcTime->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z');
}

/** @internal Parse UTC instants without PHP silently truncating unsupported fraction digits. */
function contributionInstant(string $timestamp): DateTimeImmutable
{
    contributionDate($timestamp);
    if (preg_match('/\.[0-9]{7}/', $timestamp) === 1) {
        throw new InvalidArgumentException('Unsupported contribution timestamp precision.');
    }

    return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
}

/** @internal GitHub may serialize collection metadata at whole-second precision only. */
function contributionEndpointMatches(string $timestamp, DateTimeImmutable $expected): bool
{
    $actual = contributionInstant($timestamp);

    // Explicit fractions remain exact; only omitted metadata fractions permit second-level equality.
    return str_contains($timestamp, '.') ? $actual == $expected : $actual->getTimestamp() === $expected->getTimestamp();
}

/** @internal @return array<string,mixed> Require the API to honor the whole requested range. */
function validateContributionCollection(array $data, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $collection = $data['user']['contributionsCollection'] ?? null;
    if (!is_array($collection) || !is_string($collection['startedAt'] ?? null) || !is_string($collection['endedAt'] ?? null)) {
        throw new UnexpectedValueException('Invalid contribution collection.');
    }
    if (!contributionEndpointMatches($collection['startedAt'], $from)
        || !contributionEndpointMatches($collection['endedAt'], $to)
    ) {
        throw new UnexpectedValueException('Contribution range mismatch.');
    }
    // Expected opaque aggregates are validated, but never converted into eligible weights.
    contributionApiCount($collection['restrictedContributionsCount'] ?? null);

    return $collection;
}

/** @internal @return array{bool,?string} Check complete page metadata, including explicit null cursors. */
function contributionPageInfo(array $connection): array
{
    $page = $connection['pageInfo'] ?? null;
    if (!is_array($page) || !is_bool($page['hasNextPage'] ?? null) || !array_key_exists('endCursor', $page)
        || ($page['endCursor'] !== null && !isContributionIdentity($page['endCursor']))
    ) {
        throw new UnexpectedValueException('Invalid contribution pagination.');
    }

    return [$page['hasNextPage'], $page['endCursor']];
}

/** @internal Validate both the timestamp and membership before UTC day normalization. */
function contributionRecordDay(mixed $timestamp, DateTimeImmutable $from, DateTimeImmutable $to): string
{
    if (!is_string($timestamp)) {
        throw new UnexpectedValueException('Invalid contribution record.');
    }
    $time = contributionInstant($timestamp);
    if ($time < $from || $time > $to) {
        throw new UnexpectedValueException('Contribution outside requested range.');
    }

    return $time->format('Y-m-d');
}

/**
 * Enumerate one independent annual connection, retaining annual latest-review semantics.
 *
 * @param callable(string,array<string,mixed>):array<string,mixed> $request
 * @return list<array{key:string,repositoryId:string,date:string,weight:int,restricted:bool}>
 */
function collectAnnualConnection(callable $request, string $username, int $year, string $kind): array
{
    validateContributionUsername($username);
    [$from, $to] = contributionAnnualRange($year);
    [$operation, $field, $arguments, $selection, $prefix] = match ($kind) {
        'issues' => ['AnnualIssues', 'issueContributions', ',excludeFirst:false,excludePopular:false', 'issue { id repository { id } }', 'issue'],
        'pullRequests' => ['AnnualPullRequests', 'pullRequestContributions', ',excludeFirst:false,excludePopular:false', 'pullRequest { id repository { id } }', 'pullRequest'],
        'reviews' => ['AnnualReviews', 'pullRequestReviewContributions', '', 'pullRequest { id } pullRequestReview { id } repository { id }', 'review'],
        'repositories' => ['AnnualRepositories', 'repositoryContributions', ',excludeFirst:false', 'repository { id }', 'repository'],
        default => throw new InvalidArgumentException('Invalid annual contribution kind.'),
    };
    $query = 'query ' . $operation . '($username:String!,$from:DateTime!,$to:DateTime!,$cursor:String) {
        rateLimit { remaining }
        user(login:$username) { contributionsCollection(from:$from,to:$to) {
            startedAt endedAt restrictedContributionsCount
            ' . $field . '(first:100,after:$cursor' . $arguments . ') {
                totalCount pageInfo { hasNextPage endCursor }
                nodes { occurredAt isRestricted ' . $selection . ' }
            }
        } }
    }';
    $cursor = null;
    $cursors = [];
    $total = null;
    $days = [];
    $seen = [];
    $reviewed = [];
    $reviewParents = [];
    do {
        $data = requestContributionData($request, $query, ['username' => $username,
            'from' => contributionApiTime($from), 'to' => contributionApiTime($to), 'cursor' => $cursor]);
        $collection = validateContributionCollection($data, $from, $to);
        $connection = $collection[$field] ?? null;
        if (!is_array($connection) || !is_array($connection['nodes'] ?? null) || !array_is_list($connection['nodes'])
            || count($connection['nodes']) > 100
        ) {
            throw new UnexpectedValueException('Invalid annual connection.');
        }
        $pageTotal = contributionApiCount($connection['totalCount'] ?? null);
        if ($total !== null && $total !== $pageTotal) {
            throw new UnexpectedValueException('Unstable annual total.');
        }
        $total = $pageTotal;
        [$next, $endCursor] = contributionPageInfo($connection);
        if (($connection['nodes'] !== [] && $endCursor === null)
            || ($endCursor !== null && isset($cursors[$endCursor]))
            || ($next && ($endCursor === null || $connection['nodes'] === []))
        ) {
            throw new UnexpectedValueException('Non-progressing contribution cursor.');
        }
        $before = count($seen);
        foreach ($connection['nodes'] as $node) {
            if (!is_array($node) || !is_bool($node['isRestricted'] ?? null)) {
                throw new UnexpectedValueException('Invalid contribution record.');
            }
            $repositoryId = match ($kind) {
                'issues' => $node['issue']['repository']['id'] ?? null,
                'pullRequests' => $node['pullRequest']['repository']['id'] ?? null,
                default => $node['repository']['id'] ?? null,
            };
            $id = match ($kind) {
                'issues' => $node['issue']['id'] ?? null,
                'pullRequests' => $node['pullRequest']['id'] ?? null,
                'reviews' => $node['pullRequestReview']['id'] ?? null,
                default => $repositoryId,
            };
            if (!isContributionIdentity($id) || !isContributionIdentity($repositoryId)) {
                throw new UnexpectedValueException('Invalid contribution record.');
            }
            if ($kind === 'reviews') {
                $pullRequestId = $node['pullRequest']['id'] ?? null;
                if (!isContributionIdentity($pullRequestId)) {
                    throw new UnexpectedValueException('Invalid contribution record.');
                }
                if ((isset($reviewed[$pullRequestId]) && $reviewed[$pullRequestId] !== $id)
                    || (isset($reviewParents[$id]) && $reviewParents[$id] !== $pullRequestId)
                ) {
                    throw new UnexpectedValueException('Inconsistent annual review identity.');
                }
                $reviewed[$pullRequestId] = $id;
                $reviewParents[$id] = $pullRequestId;
            }
            addContributionRecord($days, $seen, ['key' => $prefix . ':' . $id, 'repositoryId' => $repositoryId,
                'date' => contributionRecordDay($node['occurredAt'] ?? null, $from, $to),
                'weight' => 1, 'restricted' => $node['isRestricted']], []);
        }
        if (count($seen) > $total || ($next && count($seen) >= $total)) {
            throw new UnexpectedValueException('Incomplete annual connection.');
        }
        if ($next && count($seen) === $before) {
            throw new UnexpectedValueException('Non-progressing contribution cursor.');
        }
        if ($endCursor !== null) {
            $cursors[$endCursor] = true;
        }
        $cursor = $endCursor;
    } while ($next);
    if (count($seen) !== $total) {
        throw new UnexpectedValueException('Incomplete annual connection.');
    }

    return array_values($seen);
}

/**
 * Collect at most 31 UTC calendar days; replace truncated parents with validated children.
 *
 * @param callable(string,array<string,mixed>):array<string,mixed> $request
 * @return list<array{key:string,repositoryId:string,date:string,weight:int,restricted:bool}>
 */
function collectCommitWindow(callable $request, string $username, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    validateContributionUsername($username);
    $utc = new DateTimeZone('UTC');
    $from = $from->setTimezone($utc);
    $to = $to->setTimezone($utc);
    if (!isContributionDay($from->format('Y-m-d')) || !isContributionDay($to->format('Y-m-d')) || $from > $to
        || $from->setTime(0, 0)->diff($to->setTime(0, 0))->days >= 31
    ) {
        throw new InvalidArgumentException('Invalid commit window.');
    }
    $query = 'query CommitWindow($username:String!,$from:DateTime!,$to:DateTime!) {
        rateLimit { remaining }
        user(login:$username) { contributionsCollection(from:$from,to:$to) {
            startedAt endedAt restrictedContributionsCount
            totalCommitContributions totalRepositoriesWithContributedCommits
            commitContributionsByRepository(maxRepositories:100) {
                repository { id }
                contributions(first:100) {
                    totalCount pageInfo { hasNextPage endCursor }
                    nodes { occurredAt isRestricted commitCount repository { id } }
                }
            }
        } }
    }';
    $data = requestContributionData($request, $query, ['username' => $username,
        'from' => contributionApiTime($from), 'to' => contributionApiTime($to)]);
    $collection = validateContributionCollection($data, $from, $to);
    $total = contributionApiCount($collection['totalCommitContributions'] ?? null);
    $repositoryTotal = contributionApiCount($collection['totalRepositoriesWithContributedCommits'] ?? null);
    $groups = $collection['commitContributionsByRepository'] ?? null;
    if (!is_array($groups) || !array_is_list($groups) || count($groups) > 100 || count($groups) > $repositoryTotal) {
        throw new UnexpectedValueException('Invalid commit repository discovery.');
    }
    $repositories = [];
    $records = [];
    foreach ($groups as $group) {
        $repositoryId = $group['repository']['id'] ?? null;
        if (!isContributionIdentity($repositoryId) || isset($repositories[$repositoryId])) {
            throw new UnexpectedValueException('Invalid commit repository discovery.');
        }
        $repositories[$repositoryId] = true;
        $connection = $group['contributions'] ?? null;
        if (!is_array($connection) || !is_array($connection['nodes'] ?? null) || !array_is_list($connection['nodes'])
            || count($connection['nodes']) > 100
        ) {
            throw new UnexpectedValueException('Invalid commit connection.');
        }
        $connectionTotal = contributionApiCount($connection['totalCount'] ?? null);
        [$next, $endCursor] = contributionPageInfo($connection);
        if ($next || ($connection['nodes'] !== [] && $endCursor === null)) {
            throw new UnexpectedValueException('Incomplete commit connection.');
        }
        $groupRecords = [];
        foreach ($connection['nodes'] as $node) {
            if (!is_array($node) || !is_bool($node['isRestricted'] ?? null)) {
                throw new UnexpectedValueException('Invalid contribution record.');
            }
            if (($node['repository']['id'] ?? null) !== $repositoryId) {
                throw new UnexpectedValueException('Invalid commit repository discovery.');
            }
            $weight = contributionApiCount($node['commitCount'] ?? null);
            $date = contributionRecordDay($node['occurredAt'] ?? null, $from, $to);
            $key = 'commit:' . $repositoryId . ':' . $date;
            if (isset($groupRecords[$key])) {
                throw new UnexpectedValueException('Incomplete commit connection.');
            }
            $groupRecords[$key] = ['key' => $key, 'repositoryId' => $repositoryId, 'date' => $date,
                'weight' => $weight, 'restricted' => $node['isRestricted']];
        }
        if (contributionWeightSum($groupRecords) !== $connectionTotal || $connectionTotal === 0) {
            throw new UnexpectedValueException('Incomplete commit connection.');
        }
        $records = [...$records, ...array_values($groupRecords)];
    }
    if (count($groups) < $repositoryTotal) {
        $dayCount = (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->days + 1;
        if ($dayCount === 1) {
            throw new UnexpectedValueException('Incomplete single-day commit discovery.');
        }
        $rightFrom = $from->setTime(0, 0)->modify('+' . intdiv($dayCount, 2) . ' days');
        $leftTo = $rightFrom->modify('-1 day')->setTime(23, 59, 59, 999999);
        // No shared accumulator: a failed child cannot accept partial parent history.
        $children = [...collectCommitWindow($request, $username, $from, $leftTo),
            ...collectCommitWindow($request, $username, $rightFrom, $to)];
        $childByKey = [];
        $childRepositories = [];
        foreach ($children as $record) {
            if (isset($childByKey[$record['key']])) {
                throw new UnexpectedValueException('Conflicting contribution identity.');
            }
            $childByKey[$record['key']] = $record;
            $childRepositories[$record['repositoryId']] = true;
        }
        if (count($childRepositories) !== $repositoryTotal || contributionWeightSum($children) !== $total) {
            throw new UnexpectedValueException('Incomplete commit window.');
        }
        foreach ($records as $record) {
            if (($childByKey[$record['key']] ?? null) !== $record) {
                throw new UnexpectedValueException('Conflicting contribution identity.');
            }
        }

        return $children;
    }
    if (contributionWeightSum($records) !== $total) {
        throw new UnexpectedValueException('Incomplete commit window.');
    }

    return $records;
}

/** @internal Require a complete, ordered annual date grid, not an unfiltered metric substitute. */
function validateContributionCalendar(mixed $calendar, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    if (!is_array($calendar) || !is_array($calendar['weeks'] ?? null) || !array_is_list($calendar['weeks'])) {
        throw new UnexpectedValueException('Invalid contribution calendar.');
    }
    $total = contributionApiCount($calendar['totalContributions'] ?? null);
    $date = $from->setTime(0, 0);
    $weights = [];
    foreach ($calendar['weeks'] as $week) {
        $days = $week['contributionDays'] ?? null;
        if (!is_array($days) || !array_is_list($days) || $days === [] || count($days) > 7) {
            throw new UnexpectedValueException('Invalid contribution calendar.');
        }
        foreach ($days as $day) {
            if (!is_array($day) || !is_string($day['date'] ?? null) || !isContributionDay($day['date'])) {
                throw new UnexpectedValueException('Invalid contribution calendar.');
            }
            if ($date > $to || $day['date'] !== $date->format('Y-m-d')) {
                throw new UnexpectedValueException('Incomplete contribution calendar.');
            }
            $weights[] = ['weight' => contributionApiCount($day['contributionCount'] ?? null)];
            $date = $date->modify('+1 day');
        }
    }
    if ($date <= $to) {
        throw new UnexpectedValueException('Incomplete contribution calendar.');
    }
    if (contributionWeightSum($weights) !== $total) {
        throw new UnexpectedValueException('Inconsistent contribution calendar total.');
    }
}

/**
 * Collect every account/reported year, validating before applying exclusions and zero-fill.
 *
 * @param callable(string,array<string,mixed>):array<string,mixed> $request
 * @param array{username:string,excluded_repositories:list<string>} $policy
 * @return array{days:array<string,int>,startYear:int,excludedIds:list<string>}
 */
function collectProfileHistory(callable $request, array $policy, DateTimeImmutable $now): array
{
    $username = $policy['username'] ?? null;
    $paths = $policy['excluded_repositories'] ?? null;
    if (!is_string($username) || !is_array($paths) || !array_is_list($paths) || count($paths) < 1) {
        throw new InvalidArgumentException('Invalid profile policy.');
    }
    validateContributionUsername($username);
    $aliases = '';
    $uniquePaths = [];
    foreach ($paths as $index => $path) {
        if (!is_string($path) || preg_match('/\A' . preg_quote($username, '/') . '\/([A-Za-z0-9_.-]+)\z/', $path, $parts) !== 1
            || $parts[1] === '.' || $parts[1] === '..' || isset($uniquePaths[strtolower($path)])
        ) {
            throw new InvalidArgumentException('Invalid profile policy.');
        }
        $uniquePaths[strtolower($path)] = true;
        $aliases .= ' excluded' . $index . ':repository(owner:$username,name:' . json_encode($parts[1], JSON_THROW_ON_ERROR) . ') { id }';
    }
    $utcNow = $now->setTimezone(new DateTimeZone('UTC'));
    if (!isContributionDay($utcNow->format('Y-m-d'))) {
        throw new InvalidArgumentException('Invalid contribution date.');
    }
    // On December 31, the upstream positive-tomorrow bucket needs next year's collection.
    $lastYear = (int) $utcNow->modify('+1 day')->format('Y');
    $query = 'query ProfileIdentity($username:String!) {
        rateLimit { remaining }
        user(login:$username) { id login createdAt contributionsCollection { contributionYears } }
        ' . $aliases . '
    }';
    $identity = requestContributionData($request, $query, ['username' => $username]);
    $user = $identity['user'] ?? null;
    if (!is_array($user) || !isContributionIdentity($user['id'] ?? null)
        || ($user['login'] ?? null) !== $username || !is_string($user['createdAt'] ?? null)
    ) {
        throw new UnexpectedValueException('Invalid profile identity.');
    }
    $createdDay = contributionDate($user['createdAt']);
    if (contributionInstant($user['createdAt']) > $utcNow) {
        throw new UnexpectedValueException('Invalid profile identity.');
    }
    $years = $user['contributionsCollection']['contributionYears'] ?? null;
    if (!is_array($years) || !array_is_list($years)) {
        throw new UnexpectedValueException('Invalid contribution years.');
    }
    $startYear = (int) substr($createdDay, 0, 4);
    $seenYears = [];
    foreach ($years as $year) {
        if (!is_int($year) || $year < 1 || $year > $lastYear || isset($seenYears[$year])) {
            throw new UnexpectedValueException('Invalid contribution years.');
        }
        $seenYears[$year] = true;
        $startYear = min($startYear, $year);
    }
    $excludedIds = [];
    foreach (array_keys($paths) as $index) {
        $id = $identity['excluded' . $index]['id'] ?? null;
        if (!isContributionIdentity($id) || in_array($id, $excludedIds, true)) {
            throw new UnexpectedValueException('Unable to resolve excluded repositories.');
        }
        $excludedIds[] = $id;
    }
    $calendarQuery = 'query AnnualCalendar($username:String!,$from:DateTime!,$to:DateTime!) {
        rateLimit { remaining }
        user(login:$username) { contributionsCollection(from:$from,to:$to) {
            startedAt endedAt restrictedContributionsCount
            contributionCalendar { totalContributions weeks { contributionDays { date contributionCount } } }
        } }
    }';
    $days = [];
    $seen = [];
    for ($year = $startYear; $year <= $lastYear; $year++) {
        [$from, $to] = contributionAnnualRange($year);
        $calendarData = requestContributionData($request, $calendarQuery, ['username' => $username,
            'from' => contributionApiTime($from), 'to' => contributionApiTime($to)]);
        $collection = validateContributionCollection($calendarData, $from, $to);
        validateContributionCalendar($collection['contributionCalendar'] ?? null, $from, $to);
        foreach (['issues', 'pullRequests', 'reviews', 'repositories'] as $kind) {
            foreach (collectAnnualConnection($request, $username, $year, $kind) as $record) {
                addContributionRecord($days, $seen, $record, $excludedIds);
            }
        }
        $windowFrom = $from;
        while ($windowFrom <= $to) {
            $nextFrom = $windowFrom->modify('+31 days');
            $windowTo = min($to, $nextFrom->modify('-1 day')->setTime(23, 59, 59, 999999));
            foreach (collectCommitWindow($request, $username, $windowFrom, $windowTo) as $record) {
                addContributionRecord($days, $seen, $record, $excludedIds);
            }
            $windowFrom = $nextFrom;
        }
    }

    return ['days' => fillContributionDates($days, $startYear, $utcNow), 'startYear' => $startYear, 'excludedIds' => $excludedIds];
}

/**
 * @internal Accept only a well-formed, self-contained SVG, without entity expansion.
 * @throws RuntimeException When rendering is invalid or contains active/external content.
 */
function validatedStreakSvg(string $svg): DOMDocument
{
    if ($svg === '' || !class_exists(DOMDocument::class)
        || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) === 1
    ) {
        throw new RuntimeException('Invalid streak SVG.');
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        if (!$document->loadXML($svg, LIBXML_NONET) || $document->doctype !== null
            || $document->documentElement?->localName !== 'svg'
            || $document->documentElement?->namespaceURI !== 'http://www.w3.org/2000/svg'
        ) {
            throw new RuntimeException('Invalid streak SVG.');
        }
        $allowed = ['svg', 'g', 'rect', 'line', 'path', 'text', 'tspan', 'style', 'defs',
            'clipPath', 'mask', 'ellipse', 'circle', 'linearGradient', 'stop', 'desc'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($element->localName, $allowed, true)) {
                throw new RuntimeException('Invalid streak SVG.');
            }
            $values = $element->localName === 'style' ? [$element->textContent] : [];
            foreach ($element->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->localName), 'on')
                    || (in_array(strtolower($attribute->localName), ['href', 'src'], true) && !str_starts_with($attribute->value, '#'))
                ) {
                    throw new RuntimeException('Invalid streak SVG.');
                }
                $values[] = $attribute->value;
            }
            foreach ($values as $value) {
                if (preg_match('/@import|javascript:/i', $value) === 1) {
                    throw new RuntimeException('Invalid streak SVG.');
                }
                preg_match_all('/url\(([^)]*)\)/i', $value, $references);
                foreach ($references[1] as $reference) {
                    if (preg_match('/\A\s*(?:#[A-Za-z0-9_-]+|"#[A-Za-z0-9_-]+"|\'#[A-Za-z0-9_-]+\')\s*\z/', $reference) !== 1) {
                        throw new RuntimeException('Invalid streak SVG.');
                    }
                }
            }
        }
        $xpath = new DOMXPath($document);
        if ($xpath->query('//processing-instruction()')->length !== 0) {
            throw new RuntimeException('Invalid streak SVG.');
        }

        return $document;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

/**
 * Calculate and draw only filtered dates using the pinned renderer's pure functions.
 *
 * @param array<string,non-negative-int> $days
 * @param array<string,string> $options Original card options, not fetch/cache inputs.
 * @throws RuntimeException When the renderer, counts, options, or SVG are invalid.
 */
function renderFilteredStreak(array $days, string $rendererPath, array $options, DateTimeImmutable $generatedAt): string
{
    $includePath = get_include_path();
    $timezone = date_default_timezone_get();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new RuntimeException('Streak rendering failed.');
    });
    try {
        if ($rendererPath === '' || str_contains($rendererPath, "\0") || str_contains($rendererPath, '://')) {
            throw new RuntimeException('Streak rendering failed.');
        }
        $sourcePath = realpath($rendererPath . '/src');
        if ($sourcePath === false || !is_file($sourcePath . '/stats.php') || !is_file($sourcePath . '/card.php')) {
            throw new RuntimeException('Streak rendering failed.');
        }
        foreach ($options as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Streak rendering failed.');
            }
        }
        // A PNG request would invoke an external shell; this boundary is SVG-only.
        if (($options['type'] ?? 'svg') !== 'svg') {
            throw new RuntimeException('Streak rendering failed.');
        }
        $utcNow = $generatedAt->setTimezone(new DateTimeZone('UTC'));
        $dates = fillContributionDates($days, (int) $utcNow->format('Y'), $utcNow);
        $total = 0;
        foreach ($dates as $weight) {
            // Upstream number formatting accepts float, so reject lossy integer totals.
            if ($weight > 9007199254740991 - $total) {
                throw new RuntimeException('Streak rendering failed.');
            }
            $total += $weight;
        }
        set_include_path($sourcePath . PATH_SEPARATOR . $includePath);
        date_default_timezone_set('UTC');
        require_once $sourcePath . '/stats.php';
        require_once $sourcePath . '/card.php';
        $graphDays = [];
        foreach ($dates as $date => $count) {
            $graphDays[] = (object) ['date' => $date, 'contributionCount' => $count];
        }
        $graph = (object) ['data' => (object) ['user' => (object) ['contributionsCollection' => (object) [
            'contributionCalendar' => (object) ['weeks' => [(object) ['contributionDays' => $graphDays]]],
        ]]]];
        $stats = getContributionStats(getContributionDates([$graph], 'UTC', $utcNow));
        // The upstream stats card invents dates for empty history. Its message-card
        // path preserves styling without supplying a fabricated first/range date.
        $output = generateOutput($total === 0 ? 'No eligible contributions' : $stats, $options);
        if (($output['contentType'] ?? null) !== 'image/svg+xml' || !is_string($output['body'] ?? null)
            || ($output['status'] ?? 200) !== 200
        ) {
            throw new RuntimeException('Streak rendering failed.');
        }
        $document = validatedStreakSvg($output['body']);
        $description = $document->createElementNS('http://www.w3.org/2000/svg', 'desc');
        $description->appendChild($document->createTextNode(
            'API-visible contribution records; excludes orsnaro/PC-metrics, orsnaro/My-Configs-Cmder, orsnaro/orsnaro, orsnaro/stars_techno and orsnaro/Graduation-Projects-Archive; '
            . 'omits discussions and opaque restricted activity. Generated '
            . $utcNow->format('Y-m-d\TH:i:s\Z') . '; total=' . $stats['totalContributions']
            . '; current=' . $stats['currentStreak']['length'] . '; longest=' . $stats['longestStreak']['length'] . '.',
        ));
        $document->documentElement->insertBefore($description, $document->documentElement->firstChild);
        $svg = $document->saveXML($document->documentElement);
        if ($svg === false) {
            throw new RuntimeException('Streak rendering failed.');
        }
        validatedStreakSvg($svg);

        return $svg . "\n";
    } catch (Throwable) {
        throw new RuntimeException('Streak rendering failed.');
    } finally {
        set_include_path($includePath);
        date_default_timezone_set($timezone);
        restore_error_handler();
    }
}

/**
 * Validate before writing; atomically replace using a complete same-directory file.
 *
 * @throws RuntimeException When validation or any filesystem operation fails.
 */
function writeFilteredStreak(string $svg, string $path): void
{
    validatedStreakSvg($svg);
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
        throw new RuntimeException('Streak write failed.');
    }
    $directory = realpath(dirname($path));
    if ($directory === false || !is_dir($directory)) {
        throw new RuntimeException('Streak write failed.');
    }
    $temporary = null;
    try {
        $temporary = @tempnam($directory, '.filtered-streak-');
        // tempnam may silently fall back to the system temp directory; never accept it.
        if ($temporary === false || dirname($temporary) !== $directory
            || @file_put_contents($temporary, $svg) !== strlen($svg)
            || !@chmod($temporary, 0644)
            || !@rename($temporary, $directory . '/' . basename($path))
        ) {
            throw new RuntimeException('Streak write failed.');
        }
        $temporary = null;
    } catch (Throwable) {
        throw new RuntimeException('Streak write failed.');
    } finally {
        if (is_string($temporary) && is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

/**
 * Collect, render, then publish; no destination is touched before complete success.
 *
 * @param array<string,mixed> $env Required workflow environment only.
 * @param null|callable(string,array<string,mixed>):array<string,mixed> $request
 * @throws RuntimeException|InvalidArgumentException When any required stage fails.
 */
function runFilteredStreak(array $env, ?callable $request = null, ?DateTimeImmutable $now = null): void
{
    foreach (['METRICS_TOKEN', 'PROFILE_STATS_POLICY', 'STREAK_RENDERER_PATH', 'STREAK_PATH', 'STREAK_OPTIONS'] as $key) {
        if (!is_string($env[$key] ?? null) || trim($env[$key]) === '') {
            throw new InvalidArgumentException('Invalid streak environment.');
        }
    }
    $policy = loadProfilePolicy($env['PROFILE_STATS_POLICY']);
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $token = $env['METRICS_TOKEN'];
    $request ??= static fn(string $query, array $variables): array => githubGraphql($token, $query, $variables);
    $history = collectProfileHistory($request, $policy, $now);
    parse_str($env['STREAK_OPTIONS'], $options);
    $svg = renderFilteredStreak($history['days'], $env['STREAK_RENDERER_PATH'], $options, $now);
    writeFilteredStreak($svg, $env['STREAK_PATH']);
}

// Loading these typed boundaries from fixtures must never query or publish anything.
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && @realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new RuntimeException('Filtered streak generation failed.');
    });
    try {
        runFilteredStreak([
            'METRICS_TOKEN' => getenv('METRICS_TOKEN'),
            'PROFILE_STATS_POLICY' => getenv('PROFILE_STATS_POLICY'),
            'STREAK_RENDERER_PATH' => getenv('STREAK_RENDERER_PATH'),
            'STREAK_PATH' => getenv('STREAK_PATH'),
            'STREAK_OPTIONS' => getenv('STREAK_OPTIONS'),
        ]);
    } catch (Throwable) {
        fwrite(STDERR, "Filtered streak generation failed.\n");
        exit(1);
    } finally {
        restore_error_handler();
    }
}
