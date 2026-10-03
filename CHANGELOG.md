# Changelog

## 5.3.0 - 2026-10-03

### Added

- **Pagination on the Manage screen.** The group view now loads one page of items at a time instead of the whole source. A 17,000-tag group used to exhaust PHP's memory limit before the page rendered ([#1](https://github.com/justinholtweb/craft-tidytags/issues/1)). Paging keeps the search and site filter, and actions return you to the page you were on.
- **Pagination on the Duplicates screen.** Clusters are listed a page at a time, with a heading wherever the source changes, and only the clusters on the current page are loaded as elements.
- **`pageSize` and `duplicatesPageSize` settings.** Items per Manage page (100 by default, 1–1000) and clusters per Duplicates page (25 by default, 1–500). Set them in `config/tidytags.php`.
- **`tidytags-manageTags` and `tidytags-deleteTags` permissions.** Rename and swap need *manage*, delete needs *delete*, and merge needs both. Actions the user can't run are hidden on the Manage and Duplicates screens.
- The usage list says when it has been cut off at its 200-item limit.
- `DuplicateDetector::getDuplicatesPage()`, `Sources::countElementsInSource()`, `Sources::isSourceElement()`, `Tags::canRepointRelations()`, `TitleMatcher::strictKey()` and `TitleMatcher::compareFuzzyNormalized()`. `Sources::getElementsInSource()` takes optional `$limit` and `$offset` arguments.

### Changed

- **Duplicate scans scale to large vocabularies.** Scans used to load every element and compare every title with every other, so a 17,000-item source took minutes and could run out of memory. They now work on IDs and titles, find candidate pairs through an index (strict titles grouped by key, fuzzy titles through a pigeonhole segment index), and only load the elements they return. Results are identical to the old pairwise comparison; a property test checks this against brute force on random vocabularies. A 17,000-title source now clusters in well under a second.
- Scan results are cached until a tag or entry is saved or deleted, or the match settings change, so moving between pages doesn't rescan.
- The editor "did you mean?" lookup compares against the cached titles and only loads the elements it returns, instead of loading every element on each pause in typing.
- The Duplicates screen's similarity threshold is capped at 6.
- **Upgrade note:** non-admin users with only `accessPlugin-tidytags` can no longer rename, merge, delete, or swap. To keep those workflows, grant the new permissions in **Settings → Users**.
- Merge and swap now invalidate element and template caches after rewriting relations. Running `clear-caches/all` afterwards is no longer needed.
- **Control panel styling follows Craft's theme.** Every inline style and hard-coded colour has moved into the plugin stylesheet, built on Craft's CP colour tokens, so the screens and the editor warning work in dark mode and high contrast. Selects, checkboxes, radios and text inputs use Craft's `_includes/forms` macros, which gives them proper labels and focus styles.

### Security

- Merge, delete, rename, and swap only required plugin access, so any user who could open Tidy Tags could run them. Each now requires its own permission.
- Swap re-pointed relations between any two elements, including entries in sections the user couldn't edit. It now only accepts tags and entries in configured tag-like sections. Merge and swap also return 403 unless the user can save every element whose relations they rewrite.
- The usage lookup (`tidytags/tags/usages`) returned titles for any element ID. It now only answers for Tidy Tags source elements, and leaves out usages the user can't view.

## 5.2.0 - 2026-09-30

### Added

- **Selectable match strategy.** A new `matchStrategy` setting chooses how titles are compared. `fuzzy` (the default, and the only behaviour before this release) keeps normalized equality plus Levenshtein distance. `strict` compares titles after stripping affixes from the start and end, uses no edit distance at all, and rejects a pair outright when one title carries a qualifier token the other lacks.
- **`TitleMatcher` service.** Title comparison now lives in its own service (`Plugin::$plugin->titleMatcher`) rather than inside `DuplicateDetector`, so the Duplicates dashboard, the cross-source scan and the editor "did you mean?" warning all agree on what counts as a duplicate. Public API: `compare()`, `compareFuzzy()`, `compareStrict()`, `normalize()`, `coreKey()`, `qualifiers()`, `tokens()`, `getStrategy()`.
- **`affixTokens` setting.** Words stripped from the start and end of a title under the strict strategy — `fc`, `afc`, `cf`, `sc` and friends by default. Stripping is positional, so an affix in the middle of a title is left alone and `FIFA Club World Cup` is not reduced to `FIFA World Cup`.
- **`qualifierTokens` setting.** Words that make two otherwise-matching titles different things — `women`, `ladies`, `ii`, `reserves`, `u21`, `one`, `two` and similar. Under the strict strategy, an asymmetry here vetoes the match, so `Arsenal` is never reported as a duplicate of `Arsenal Women`, nor `County Championship` of `County Championship One`.
- **Matching settings UI.** **Settings → Plugins → Tidy Tags** gains a *Matching* section with the strategy picker and, under strict, editable token lists. Both lists accept a newline- or comma-separated paste, and both can still be driven from `config/tidytags.php` as arrays.

### Why strict exists

Edit distance suits the vocabulary most sites have: free-text tags typed by editors, where duplicates are misspellings. It is the wrong tool for a controlled vocabulary of proper nouns — clubs, competitions, places, people — because those names sit close together in edit space. Measured against a real 2,633-title corpus of teams, competitions and tags, the fuzzy strategy's six near-matches were *all* false positives (`Essex`/`Sussex`, `Durham`/`Fulham`, `Tampa`/`Samoa`, `Top 10`/`Top 14`), while it missed every genuine variant, because those differ by a whole affix word rather than a typo — `FC Bayern Munich`/`Bayern Munich` is three edits apart. On the same corpus the strict strategy found the four real variants and none of the false positives.

Neither strategy is better in general, which is why this is a setting rather than a change of default.

### Changed

- `DuplicateDetector` delegates all title comparison to `TitleMatcher`. Method signatures, the `defaultThreshold` property and every returned item key are unchanged, and the fuzzy strategy produces identical results to 5.1.0.
- `DuplicateDetector::findSimilar()` still reports a `distance` for each match under both strategies. Under strict, an exact match reports `0` and an affix-only match reports `1` (`TitleMatcher::DISTANCE_AFFIX`), so callers that sort by distance keep putting unambiguous matches first.
- The `threshold` argument to `findDuplicates()`, `findAllDuplicates()`, `findCrossSourceDuplicates()` and `findSimilar()` is ignored under the strict strategy, which has no distance to threshold.
- An unrecognised `matchStrategy` value falls back to `fuzzy` rather than matching nothing, so a typo in `config/tidytags.php` degrades to previous behaviour.

### Fixed

- `Tags::renameTag()` returned `true` when the given tag ID matched nothing, so the control panel announced "Tag renamed." after a no-op. It now reports failure when the ID resolves to no tag in any site.

### Development

- Added a [Codeception](https://codeception.com) unit suite that runs the plugin's services against a real, freshly installed Craft 5 database, with a DDEV config to run it. 156 tests / 323 assertions. See **Development** in the README.

## 5.1.0 - 2026-05-02

### Added

- **Per-source field configuration.** Each source (tag group or tag-like entry section) can now be assigned a *differentiator field* and a list of *display fields* under **Settings → Plugins → Tidy Tags**. Differentiator values are used by the duplicate scanner and the "did you mean?" warning to keep deliberately same-named items apart (e.g. an `England` Team with `sport: Football` is no longer flagged against an `England` Team with `sport: Cricket`). Display field values appear next to each item so reviewers can tell similar items apart at a glance.
- **Cross-source duplicate detection.** A new **Across sources** tab on the Duplicates page pools every source together and surfaces clusters that span more than one source — the case where an editor has created a Tag for something already maintained as a Team or Competition.
- **Item links and source badges.** Cluster items now link directly to their entry/tag edit screen and show the source they came from with a Tag/Entry badge, so jumping to fix a duplicate is one click.
- **Inline usages browser.** Each cluster item has a **Show usages** button that lists every entry, asset, etc. that holds a relation to it, with edit links — useful for previewing what would move in a swap or merge, or for cleaning up by hand.
- **Cross-type relation swap.** A new `tidytags/tags/swap` action re-points relations from any element to any other element without deleting the source. It powers the new cross-source clusters UI and the read-only entry-source clusters, and is safe across element types because it only touches the `{{%relations}}` table. Tag → tag merges still use the existing delete-after-swap path.
- **Editor "did you mean?" warning now spans configured entry sections.** Typing a new tag warns you if a Team or Competition with the same name already exists, with a direct link to the existing entry. Honors the differentiator field so a new `England (Rugby)` doesn't get flagged against an existing `England (Football)`.

### Changed

- `DuplicateDetector::findSimilar()` and `findDuplicates()` now return enriched item dicts that include `cpEditUrl`, `displayValues`, `differentiator`, and source metadata. Existing keys (`id`, `title`, `siteId`) are preserved.
- The Duplicates page is split into **Within source** and **Across sources** tabs, scoped by the new `scope` query parameter.
- Settings model gains `sourceFieldConfig`, keyed by source UID and overlayable from `config/tidytags.php`.

### Action endpoints

| Action | Method | Params |
| --- | --- | --- |
| `tidytags/tags/usages` | GET | `elementId` |
| `tidytags/tags/swap` | POST | `targetId`, `sourceIds[]` |

## 5.0.1 - 2026-04-24

### Added

- Support for [entrified](https://craftcms.com/blog/entrification) tag sections. Channel sections produced by `php craft entrify/tags` can be opted in under **Settings → Plugins → Tidy Tags** and appear on the dashboard and in the duplicate scanner alongside native tag groups, with an **Entries** badge.
- Per-site counts and title browsing for entry-backed sources.
- Cross-site duplicate-cluster detection for entry-backed sources (read-only — review clusters in their section).
- `tagLikeSectionUids` setting, overlayable from `config/tidytags.php` for per-environment configuration.
- New `Sources` service and `Source` model that unify tag groups and tag-like entry sections behind a single interface.

### Changed

- Dashboard, group view, and duplicate scanner now operate on unified sources instead of tag groups directly.
- Rename, merge, delete, and the "did you mean?" editor warning remain tag-only by design; entry-backed sources are intentionally read-only in Tidy Tags.

## 5.0.0 - 2026-04-10

- Initial release.
