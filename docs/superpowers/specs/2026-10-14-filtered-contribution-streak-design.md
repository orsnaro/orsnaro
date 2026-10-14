# Filtered API-visible contribution streak

## Purpose and approved scope

Replace the streak card's unfiltered data with a repository-filtered daily history. Recalculate **total contributions, current streak, and longest streak**; do not edit only the displayed total.

The user approved the explicitly scoped **API-visible contribution** metric after research established that GitHub does not expose a complete repository-attributed ledger for every contribution type. This metric is **not an exact reproduction of the full GitHub contribution graph**.

Exclude these repositories from contribution-based streak statistics and the existing commit/star/changed-line filters:

- `orsnaro/PC-metrics`
- `orsnaro/My-Configs-Cmder`
- `orsnaro/orsnaro` (profile repository; profile content, not a project)
- `orsnaro/stars_techno`
- `orsnaro/Graduation-Projects-Archive`
- `orsnaro/Notes_Drafts`
- `orsnaro/crypts`
- `orsnaro/test`
- `orsnaro/notion_msTodo`
- `orsnaro/Reality-SimSuit-Grad-project-`

Other accessible public/private repositories remain eligible. Preserve the existing `cmder/bin` language eligibility, other language filters, README layout, private-access guard, LeetCode/activity widgets, daily card schedule, and card styling.

## Metric contract

Count these identifiable contribution records returned by GitHub's contribution API:

| Type | Daily weight | Repository identity |
|---|---:|---|
| Qualifying commit contribution | `commitCount` | Contribution repository ID |
| Issue creation contribution | 1 | Issue repository ID |
| Pull request creation contribution | 1 | Pull request repository ID |
| Pull request review contribution | 1 | Review contribution repository ID |
| Repository creation contribution, including forks | 1 | Created repository ID |

- Resolve the two approved repository paths to IDs and exclude by ID, not by guessed name matching. Failure to resolve either excluded repository prevents publication.
- Discussions/answers and opaque restricted contributions are not included. Do not assign unidentified activity to an eligible repository.
- Review records follow GitHub's contribution API semantics: the most recent submitted review for a PR within the requested annual collection, not an exhaustive review-event ledger. Keep annual ranges for these records so subdivision does not silently change that definition.
- Use UTC dates of API-reported `occurredAt` values, including GitHub's per-day commit contribution entries. Do not infer days from raw Git history, PR creation timestamps fetched elsewhere, runner-local time, or Cairo conversions. This explicitly scoped date convention is not a promise of exact GitHub-calendar bucket equivalence.
- Account history includes every reported contribution year, the account-creation year through the current year, and zero dates between the earliest relevant year and today. Use upstream handling for a positive tomorrow bucket; ignore later future dates.
- An excluded-only day cannot extend or bridge a streak. A mixed day retains its eligible contribution weights. Preserve the upstream grace rule: an empty today does not reset a streak that reached yesterday, but cannot add another day.
- Sum filtered daily weights for the total. Obtain first eligible date and both streak ranges/lengths from the filtered history. Do not reuse the old unfiltered ranges or totals.
- This total need not equal the stats card's all-commits search total: the metrics have different qualifying rules and contribution types.

## Architecture and files

1. **`.github/profile-stats.json`** — one approved repository exclusion list. Its policy applies to the streak and commit/star/line counts, not to language-card eligibility.
2. **`.github/scripts/filtered-streak.php`** — a typed PHP collector/orchestrator with separable fetch, validation, aggregation, and rendering boundaries. Read the existing secret only from the workflow environment; query repository IDs and counts/timestamps, not private names, URLs, titles, bodies, or messages.
3. **`.github/workflows/readme_cards.yml`** — resolve policy inputs, prepare the pinned existing streak renderer and PHP runtime, invoke the filtered collector, and extend the existing commit-search/star exclusions to both repositories. Keep core 2.2.1, existing card options, guard, and scoped SVG commit behavior.
4. **`.github/workflows/metrics_action.yml`** — read the same policy for `plugin_lines_skipped`. Explicitly target the dispatched branch when testing, so a feature-branch run cannot publish to `main`.
5. **`README.md`** — add a concise visible scope note: streaks use API-visible contribution records, exclude both repositories, and omit discussion/opaque restricted activity. Preserve the language exception and existing section expansion states.

Reuse upstream calculation/rendering functions from the currently pinned `DenverCoder1/github-readme-streak-stats` revision `d855de1a23fe45bdda5ffbc28a6d4b80b0f107e1`. Do not call its unfiltered fetch/cache path, fork its drawing implementation, or post-edit numeric labels. Preserve colors, size, number formatting, date formatting, and animation settings. The existing pinned PHP setup action can provide `intl`/`curl`; no permanent Python helpers or test scripts are introduced.

## Collection and completeness

- Validate token/user access using the existing workflow guard; also check the collector's returned user identity and excluded repository IDs.
- Use explicit annual collection ranges for the calendar date grid and the four non-commit record types. Set `excludeFirst:false` and `excludePopular:false` where supported.
- Fully paginate each global issue/PR/review/repository-creation connection independently with `first:100`. Require cursor progress and `hasNextPage:false` before accepting its complete result. Deduplicate by stable underlying record ID; duplicates must not conceal a completeness mismatch.
- Commit repository groups are an ordinary array, not an outer cursor connection. Request `maxRepositories:100` without assuming that 100 is a documented hard maximum. Compare the returned group count with `totalRepositoriesWithContributedCommits`.
- Collect commits in windows of at most 31 calendar days. Each repository then has at most a small number of day nodes; request `first:100` and require its connection to be complete. Never reuse one nested connection's cursor for other repositories.
- If repository discovery is truncated, subdivide the commit window, down to a single day. If it is still incomplete, fail rather than treating a missing repository as having zero contributions.
- Validate each commit connection's **weighted sum** against its `totalCount`, not its number of day nodes. Validate discovered window totals against `totalCommitContributions` before applying exclusions.
- Validate other connection totals against the fully enumerated records. Use only totals with the same queried flags/range; reject ambiguous or inconsistent results instead of guessing how restricted data contributes to a total.
- Treat expected opaque aggregate counts as outside this metric. Do not use them to fill missing identifiable records or to repair a failed completeness check.
- Reject HTTP failures, GraphQL errors even in HTTP-200 responses, missing required fields, unexpected null records, invalid counts/dates, repeated/non-progressing cursors, incomplete windows, and inconsistent totals. Do not clamp negative values or silently fall back to unfiltered statistics.
- Dates/windows must not cause duplicate boundary contributions. Validate coverage and stable identities across annual and commit-window boundaries.

## Privacy, output, and failure behavior

- Keep contribution IDs, cursors, repository IDs, and daily intermediate history in memory or runner-temporary storage only. Never commit or upload them as artifacts.
- Publish only aggregate SVG statistics and a scope/generation timestamp in SVG metadata or description. Runtime diagnostics must not dump API payloads, token values, or private repository/event details.
- Write a replacement `profile/streak.svg` only after the whole collection, aggregation, and render succeeds. A valid zero-eligible-history result gets a truthful empty state, not fabricated contribution dates.
- Preserve the workflow rule that SVG commits occur only after every card succeeds. Failure retains the previous published SVGs, with no fabricated new total or unfiltered fallback.
- API visibility, GitHub's refresh delay, and historical attribution changes remain limitations; explicitly scoped output must not be described as the complete GitHub graph.

## Verification and publication

No existing profile test suite or permanent test scripts are present. Use temporary transport fixtures against the actual collector and pinned upstream functions, observing a regression fail before implementation and pass afterward.

Required cases:

- Both excluded repositories removed from every supported contribution type; eligible private/public repository IDs retained without outputting their details.
- Weighted commit counts, mixed days, excluded-only gaps, today grace, and both current/longest streak recomputation.
- Year transitions, leap days, zero days, account-history coverage, and stable boundary deduplication.
- Annual review semantics remain stable when commit windows are subdivided.
- Complete pagination, repository-cap subdivision, and failure at an unresolved single-day cap.
- Missing/invalid responses, HTTP/GraphQL errors, inconsistent totals, and non-progressing cursors prevent replacing the last good SVG.
- Empty eligible history is truthful. Renderer style/options and language filters remain preserved.
- Both repositories reach the stats search/star filters and lines plugin through the same policy; unrelated GraphQL requests are untouched.
- Run actionlint, PHP syntax checks, all temporary fixture cases, SVG XML validation, whitespace checks, and independent spec/code review.

Keep work on `feat/filtered-contribution-streak`, based on fresh `origin/main` at `b58f4270b`. Test the live workflow on the feature branch before presenting the PR as ready; fetch and validate the generated SVGs at the exact bot commit. Use a draft PR if publication precedes live verification. Do not merge automatically or use alternative write paths after a harness permission denial.

## Sources and version-sensitive limitations

Checked October 14, 2026:

- [GitHub contribution types, visibility, dates, and eligibility](https://docs.github.com/en/account-and-profile/reference/profile-contributions-reference)
- [Current official GraphQL schema](https://docs.github.com/public/fpt/schema.docs.graphql)
- [User contribution fields](https://docs.github.com/en/graphql/reference/users)
- [Connection pagination](https://docs.github.com/en/graphql/guides/using-pagination-in-the-graphql-api)
- [GraphQL resource limits](https://docs.github.com/en/graphql/overview/rate-limits-and-query-limits-for-the-graphql-api)
- [Pinned upstream calculation/date behavior](https://github.com/DenverCoder1/github-readme-streak-stats/blob/d855de1a23fe45bdda5ffbc28a6d4b80b0f107e1/src/stats.php)
- [Pinned upstream renderer](https://github.com/DenverCoder1/github-readme-streak-stats/blob/d855de1a23fe45bdda5ffbc28a6d4b80b0f107e1/src/card.php)
