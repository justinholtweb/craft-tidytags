# Changelog

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
