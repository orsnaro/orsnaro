# Filtered Contribution Streak Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development (the user's preferred method) to implement this plan task-by-task. Steps use checkbox syntax. Do not start implementation until the user approves this written plan.

**Goal:** Recalculate a clearly labeled API-visible contribution total/current/longest streak excluding `PC-metrics` and `My-Configs-Cmder`, and align existing commit/star/line exclusions.

**Architecture:** A shared JSON policy feeds the workflow filters and a typed PHP collector. The collector validates and filters identifiable GitHub contribution records, then passes their daily weights to the existing pinned upstream streak functions. Complete successful generation is required before replacing/publishing SVGs.

**Tech Stack:** PHP 8.3 with intl/curl/dom in Actions; existing core 2.2.1 and Axios 1.18.1 for the stats card; jq; pinned upstream PHP renderer; temporary Node/PHP fixtures only.

**Spec:** `docs/superpowers/specs/2026-10-14-filtered-contribution-streak-design.md` — approved by the user with “go”.

## Global Constraints

- Worktree `/tmp/opencode/profile-language-stats`; existing branch `feat/filtered-contribution-streak`, current documentation commit `4d7146d11`, base `b58f4270b`.
- Exactly two contribution exclusions: `orsnaro/PC-metrics` and `orsnaro/My-Configs-Cmder`. Preserve `cmder/bin` language eligibility and every existing language filter.
- Count API-visible qualifying commit weights, issue/PR creation, latest qualifying review per PR in an annual collection, and repository creations/forks; omit discussions and opaque restricted contributions.
- Use UTC API contribution dates, complete history/zero days, and the upstream today/tomorrow rules. Do not claim exact equivalence to the complete GitHub graph.
- Pin renderer revision `d855de1a23fe45bdda5ffbc28a6d4b80b0f107e1`, checkout `3d3c42e5aac5ba805825da76410c181273ba90b1`, and PHP setup `f3e473d116dcccaddc5834248c87452386958240`. Retain core 2.2.1, Axios 1.18.1, and original card options.
- Never retrieve secret values; use `METRICS_TOKEN` only in Actions. Diagnostics/artifacts must contain no other private repository identities or event details.
- No permanent test scripts or Python. Verification files/runtime live under `/tmp/opencode/filtered-streak-verify`; fixture output must never target tracked SVGs.
- No host package installation or service changes. PHP is absent and Docker has no daemon: use the signed, extracted temporary runtime below; record local PHP 8.5.11 separately from the required live PHP 8.3 check.
- API errors, incomplete/ambiguous records, cap truncation, bad cursors/dates/totals, or rendering/writing errors fail closed. No clamping, skipped historical years, fake totals, or unfiltered fallback.
- No automatic merge. Publish/test only with existing explicit authorization; if a harness write is denied, stop that write path. A PR is not ready until feature-branch SVG generation succeeds.

All command examples run from `/tmp/opencode/profile-language-stats`; use these absolute verification paths:

```bash
VERIFY=/tmp/opencode/filtered-streak-verify
PHP="$VERIFY/runtime/php"
NODE=/tmp/opencode/pc-metrics-tools/node-v24.21.0-linux-x64/bin/node
LINT=/tmp/opencode/private-profile-tools/actionlint
```

## Review Focus

- **False continuity:** ignored-only intervening days, zero days, and ignored-only today must not extend a streak; Task 2 pins these cases.
- **Unstable historical semantics:** annual review selection and boundary identity must not change when commit windows subdivide; Task 3 pins annual review queries and leap/year boundaries.
- **Hidden truncation/rate exhaustion:** weighted commit totals, array caps, cursor progress, and HTTP-200 GraphQL errors must reject incomplete histories; Task 3 pins each failure.
- **Privacy and stale artifacts:** unexpected exception text must not leak private payloads; failed writes must retain the previous SVG, and the first relabeled card must be freshly filtered; Tasks 4/5 pin these cases.
- **Runtime/policy drift:** local PHP 8.5.11 is not proof of PHP 8.3 compatibility; both workflows must use the same policy while language options remain unchanged; Tasks 1/4/5 pin policy and target-runtime checks.

---

### Task 1: Shared exclusion policy and existing count filters

**Files:** Create `.github/profile-stats.json`; modify `.github/workflows/readme_cards.yml` and `.github/workflows/metrics_action.yml`.

**Interfaces:** Policy schema is `{"username":"orsnaro","excluded_repositories":["orsnaro/PC-metrics","orsnaro/My-Configs-Cmder"]}`. Each workflow exposes a `policy` step with outputs `repositories` (full paths, CSV) and `repository_names` (bare names, CSV). Later tasks consume the same policy file.

- [ ] Write `/tmp/opencode/filtered-streak-verify/policy-counts.mjs`. Extend the prior real-core transport fixture: unfiltered 145 commits, PC-only exclusion 75, both exclusions 50; stars fixture has RealProject=10, PC-metrics=4, My-Configs-Cmder=7. Assert the actual workflow stats body renders 50 commits/10 stars and matches the core's literal-50-count rank/output. Retain all seven prior guard scenarios: complete counts, incomplete/missing/negative/fractional results, transport failure, and bypassed search; failed cases cannot replace the sentinel SVG. Assert exact policy entries, both workflow bindings, unchanged language options, guard, schedule, activity visibility, and streak options.

```js
assert.equal(commitSearchQuery, 'author:orsnaro -repo:orsnaro/PC-metrics -repo:orsnaro/My-Configs-Cmder');
assert.equal(renderOptions.exclude_repo, 'PC-metrics,My-Configs-Cmder');
assert.match(svg, /data-testid="commits"[^>]*>\s*50\s*</);
assert.match(svg, /data-testid="stars"[^>]*>\s*10\s*</);
assert.equal(svg, literalFilteredCountSvg);
```

- [ ] Run `/tmp/opencode/pc-metrics-tools/node-v24.21.0-linux-x64/bin/node /tmp/opencode/filtered-streak-verify/policy-counts.mjs`; expect the missing policy/two-repo filter assertions to FAIL before changes, not an import/runtime error.
- [ ] Add the policy and jq validation/output steps. Require the exact username, a nonempty unique list of safe owner/repository paths belonging to it, and no newline/output injection. The commit-search interceptor derives and appends all missing `-repo:` tokens; native stats `exclude_repo` uses bare policy names. Preserve incomplete-result guards and original options.
- [ ] Metrics checks out the current ref with the pinned checkout, reads the policy, binds `plugin_lines_skipped` to full paths, and sets `committer_branch: ${{ github.ref_name }}`. Do not change other plugins or language skips.
- [ ] Run the fixture including invalid policy cases, `/tmp/opencode/private-profile-tools/actionlint .github/workflows/readme_cards.yml .github/workflows/metrics_action.yml`, and `git diff --check`; all must PASS. Get spec/code review, then commit exactly the three files as `fix: share profile contribution exclusions`.

### Task 2: Typed record filtering and daily-history primitives

**Files:** Create `.github/scripts/filtered-streak.php`. Tests: temporary `aggregate.php` and `runtime/php` under the verification directory.

**Interfaces:** PHP `declare(strict_types=1)`; no top-level network/render work when the file is required. Define:

- `loadProfilePolicy(string $path): array` returning the Task 1 policy shape, with the same validation.
- `contributionDate(string $occurredAt): string` returning a strictly validated UTC `Y-m-d`.
- `addContributionRecord(array &$days, array &$seen, array $record, array $excludedIds): void`; record shape `array{key:string,repositoryId:string,date:string,weight:int,restricted:bool}`, days `array<string,int>`, seen `array<string,array>`. Validate before filtering; identical identities cannot add weight twice and conflicting identities fail.
- `fillContributionDates(array $counts, int $startYear, DateTimeImmutable $now): array`; returns sorted, gap-free day weights through UTC today, plus a positive tomorrow bucket only. Older reported years remain eligible; later future dates do not count.

- [ ] Provision a temporary runtime only after plan approval. Download `https://archive.archlinux.org/packages/p/php/php-8.5.11-1-x86_64.pkg.tar.zst` and its `.sig` into the verification directory; verify with `gpgv --keyring /usr/share/pacman/keyrings/archlinux.gpg <signature> <package>` before `bsdtar -xf <package> -C /tmp/opencode/filtered-streak-verify/runtime/root`. This exact official package provides php-intl and matches the available ICU 78 libraries. If verification/linking fails, stop and report; do not alter host packages.
- [ ] Create the temporary `runtime/php` shell wrapper: execute `runtime/root/usr/bin/php` with `-n`, `date.timezone=UTC`, `zend.assertions=1`, `assert.exception=1`, and its extracted extension directory; load intl/curl/dom modules when their `.so` files exist. Require `extension_loaded` for all three. Record the actual version, then use this wrapper for every local PHP check.
- [ ] Write `aggregate.php` with a guarded require, then assert ignored weights, mixed days, restricted records, duplicate/conflicting identities, invalid dates/counts/policy, leap/year zero dates, and tomorrow limits. Sample assertions:

```php
assert($days['2026-10-12'] === 3); // Eligible private weight 2 + public issue 1; ignore weights 70 + 25.
assert($days['2026-10-13'] === 0); // Only ignored repositories contributed.
assert(contributionDate('2026-10-14T01:30:00+03:00') === '2026-10-13');
assert(array_key_exists('2024-02-29', $filled));
assert(!array_key_exists('2026-10-16', $filled));
```

- [ ] Run `/tmp/opencode/filtered-streak-verify/runtime/php /tmp/opencode/filtered-streak-verify/aggregate.php`; expect missing new function assertions to FAIL before production functions exist.
- [ ] Implement the four exact interfaces, with typed parameters/returns and precise array PHPDoc. Reject malformed records even when their repository is excluded. Do not count opaque aggregate values or assume a zero day means missing API data.
- [ ] Run all aggregate cases and `runtime/php -l .github/scripts/filtered-streak.php`; expect PASS. Review, then commit only the PHP file as `feat: filter identifiable daily contributions`.

### Task 3: Complete GraphQL collection

**Files:** Modify `.github/scripts/filtered-streak.php`. Tests: temporary `collector.php`.

**Interfaces:** Add the following functions; the request callback signature is `callable(string $query, array $variables): array`, returning the validated GraphQL `data` object:

- `parseGitHubResponse(int $status, string $body): array` — rejects transport/JSON/GraphQL/partial failures without exposing response contents.
- `githubGraphql(string $token, string $query, array $variables): array` — fixed HTTPS GitHub endpoint, bearer header, finite timeouts, checked cURL status, no payload logging.
- `collectAnnualConnection(callable $request, string $username, int $year, string $kind): array` — kinds `issues`, `pullRequests`, `reviews`, `repositories`; returns normalized Task 2 records using independent pagination.
- `collectCommitWindow(callable $request, string $username, DateTimeImmutable $from, DateTimeImmutable $to): array` — returns normalized commit records before exclusion, recursively resolving truncated discovery.
- `collectProfileHistory(callable $request, array $policy, DateTimeImmutable $now): array` returning `array{days:array<string,int>,startYear:int,excludedIds:list<string>}` after complete validation/filtering.

- [ ] Write `collector.php` callback fixtures for named operations `ProfileIdentity`, `AnnualCalendar`, `AnnualIssues`, `AnnualPullRequests`, `AnnualReviews`, `AnnualRepositories`, and `CommitWindow`. Query variables use `username`, `from`, `to`, and `cursor` where relevant. Identity fixtures resolve excluded IDs and return createdAt/contributionYears; no private names or event content are included.
- [ ] Assert both exclusions across all five types, eligible private records, a two-page connection, weighted commit `totalCount` rather than node count, overflowing repository discovery that succeeds after subdivision, an unresolved single-day overflow, null/partial/bad-cursor/bad-count/rate-exhaustion failures, annual review ranges during commit subdivision, and account/year/leap-date coverage. Failed parent-window records must not be added before its subdivisions complete.
- [ ] Run `"$PHP" "$VERIFY/collector.php"`; expect missing collector interfaces to FAIL before implementation.
- [ ] Implement typed collection. Request only stable IDs, `isRestricted`, timestamps, counts, and pagination/completeness metadata. Use explicit annual ranges and supported `excludeFirst:false`/`excludePopular:false`; latest-review selection stays annual. Require stable totals/cursors, valid range membership, complete calendar structure, and zero-filled chronology after enumeration.
- [ ] Commit windows span at most 31 days; request `maxRepositories:100`, nested `first:100`, and reject incomplete nested pages. Compare outer length to `totalRepositoriesWithContributedCommits`, weighted connections to their commit `totalCount`, and window weight to `totalCommitContributions` before exclusions. Bisect truncated day ranges without shared endpoints; an unresolved one-day cap fails.
- [ ] Handle missing policy repository IDs, expected opaque aggregates, and GraphQL resource failures according to the spec: opaque data is outside the metric; incomplete identifiable data is an error, not zero. API limits/errors must not trigger a narrower implicit history or unfiltered fallback.
- [ ] Run all Task 2/3 cases and PHP syntax checks; expect PASS and no private fixture identity/token in diagnostics. Review, then commit the PHP collector changes as `feat: collect complete API-visible contribution history`.

### Task 4: Reuse renderer and wire safe workflow generation

**Files:** Modify `.github/scripts/filtered-streak.php` and `.github/workflows/readme_cards.yml`. Tests: temporary `render.php`, fixture SVGs, and the pinned upstream renderer checkout.

**Interfaces:** Add `renderFilteredStreak(array $days, string $rendererPath, array $options, DateTimeImmutable $generatedAt): string`, `writeFilteredStreak(string $svg, string $path): void`, and `runFilteredStreak(array $env, ?callable $request = null, ?DateTimeImmutable $now = null): void`. Required environment: `METRICS_TOKEN`, `PROFILE_STATS_POLICY`, `STREAK_RENDERER_PATH`, `STREAK_PATH`, `STREAK_OPTIONS`. The injectable request/time are for temporary fixtures; the actual CLI catches failures, prints a generic safe error, and exits nonzero.

- [ ] Download/extract the immutable pinned renderer archive from `https://codeload.github.com/DenverCoder1/github-readme-streak-stats/tar.gz/d855de1a23fe45bdda5ffbc28a6d4b80b0f107e1` into the verification directory. Require only its existing stats/card functions and relative resources; do not call generator/cache/unfiltered-fetch functions or install unnecessary Composer packages.
- [ ] Write rendering fixtures against actual upstream functions. For days `2026-10-10=2`, `11=0`, `12=3`, `13=0`, `14=1` at October 14 UTC, assert total=6, current=1, longest=1 (not an ignored-day bridge). For `12=3`, `13=2`, `14=0`, assert total=5/current=2/longest=2. Check first eligible date, empty eligible history, styles, tomorrow behavior, and no nonapproved identifiers in SVG.
- [ ] Put a sentinel last-good SVG at a temporary destination; exercise collector failure, invalid render output, and failed final writes. Assert its bytes remain unchanged. Exercise CLI error sanitization with a synthetic sensitive exception; its text must not appear in stderr/SVG.
- [ ] Run `"$PHP" "$VERIFY/render.php"`; the new rendering/writing/CLI contracts must FAIL before implementation.
- [ ] Feed filtered dates into pinned upstream date/stat/render helpers; no numeric post-editing. Produce a truthful empty-state SVG for zero eligible history. Validate the generated SVG and add only aggregate scope/generation metadata. Write to a same-directory temporary file and atomically replace the destination only after complete success; clean only the temporary file created by this operation.
- [ ] Replace the unfiltered streak action with pinned upstream checkout (`path: .profile-tools/streak-renderer`, `persist-credentials:false`), pinned PHP setup (PHP `8.3`, intl/curl/dom), target PHP syntax/extension verification, and the collector command with absolute environment paths. Copy the entire original `STREAK_OPTIONS` unchanged from the existing workflow. Preserve the all-cards-success commit gate and three-SVG-only staging.
- [ ] Run all temporary PHP/Node fixtures, actionlint, PHP syntax checks, `xmllint --nonet --noout` on fixture SVGs, and `git diff --check`; expect PASS. Record local PHP version honestly. Review, then commit only the two approved source files as `feat: render filtered contribution streak cards`.

### Task 5: Explain scope and verify the complete feature live

**Files:** Modify `README.md`; live-generated `profile/top-langs.svg`, `profile/streak.svg`, `profile/stats.svg`, and `github-metrics.svg` only after successful branch runs. Temporary verification/report files stay outside git.

**Interfaces:** README visible fine print states: “Streaks use UTC API-visible contributions, excluding PC-metrics and My-Configs-Cmder; discussions and unidentified private activity are not counted.” Existing language fine print and layout remain. SVG metadata records the successful generation time and scoped metric, not private daily activity.

- [ ] Write temporary README assertions for the exact scope text, local SVG links, one language widget, contact-only default expansion, stats-card placement, and the preserved `cmder/bin` statement. Run before editing; expect missing scope assertion FAIL.
- [ ] Add the concise scope note adjacent to the top cards without opening other sections or replacing the existing language note. Explain that failed refreshes retain the last successful image and that the metric follows API visibility rather than the complete GitHub graph.
- [ ] Run `"$NODE" "$VERIFY/policy-counts.mjs"`, `"$PHP" "$VERIFY/aggregate.php"`, `"$PHP" "$VERIFY/collector.php"`, `"$PHP" "$VERIFY/render.php"`, `"$NODE" "$VERIFY/readme-scope.mjs"`, `"$PHP" -l .github/scripts/filtered-streak.php`, `"$LINT" .github/workflows/readme_cards.yml .github/workflows/metrics_action.yml`, fixture SVG XML checks, and `git diff b58f4270b..HEAD --check`. Obtain whole-branch independent spec/code review; fix Critical/Important findings before proceeding. Commit only README and any reviewed source fixes.
- [ ] Under existing publication authorization, push the feature branch once and dispatch `readme_cards.yml` and `metrics_action.yml` explicitly on it. Create a draft PR if created before generation succeeds. If the harness denies a write, do not retry via another tool; give the user the commands and mark live validation pending.
- [ ] Inspect exact run SHAs and all generation/commit steps, including PHP **8.3** syntax/runtime success. Fetch the bot-generated assets by immutable commit SHA; verify four HTTP-200 valid SVGs, scope/timestamp metadata, noninflated filtered daily totals/streaks, and absence of private IDs/details. Confirm Metrics writes only the feature branch.
- [ ] Re-fetch the branch after bot updates, rerun local checks on its actual head, and verify that no temporary fixture/renderer directory was committed. Record local-versus-live runtime evidence and known API limitations. Only then mark the PR ready; report its URL and leave merging to the user.

## Self-review and handoff

- Spec coverage: all five contribution types, shared filters, preserved languages/style/layout, privacy, completeness, today/history semantics, empty/error states, runtime limits, and live publication have owning tasks above.
- Types: normalized record/day/policy shapes and request callback signatures are shared exactly by Tasks 2–4; no undefined test-only production interfaces.
- Review Focus: each of the five listed risks has explicit owning tests rather than relying on the final reviewer to infer it.
- Proportion: implementation bodies are left to the engineer; only pinned interfaces, exact values, commands, algorithm decisions, and assertions are prescribed.
- Execution remains gated on the user's written-plan approval. Preferred method: subagent-driven, with fresh task implementation and spec/code review, followed by whole-branch review.
