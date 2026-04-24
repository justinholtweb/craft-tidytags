# Changelog

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
