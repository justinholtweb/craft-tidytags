# Upgrading Tidy Tags from Craft 5 to Craft 6

Tidy Tags 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. Craft 6 has no Tags, so Tidy Tags 6 works on **entry sections**: the channel sections your
tag groups become. Your settings and permissions carry over.

> **Beta.** Tidy Tags 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> before relying on it in production. Tidy Tags 5 remains the stable release for Craft 5.

## Before you start

| | Tidy Tags 5.x | Tidy Tags 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.0.0+ | 6.0.0-beta.1+ |
| PHP | 8.2+ | 8.5+ |
| Sources | Tag groups and tag-like entry sections | Tag-like entry sections |
| Install command | `php craft plugin/install tidytags` | `php artisan craft:plugin:install tidytags` |
| Console commands | `php craft tidytags/duplicates` | `php artisan tidytags:duplicates` (old names still work) |

1. **Update to Tidy Tags 5.5 first**, while the site is still on Craft 5:

   ```bash
   composer require justinholtweb/craft-tidytags:^5.5
   php craft up
   ```

   Tidy Tags 6 won't install over anything older than 5.5.0.

2. **Clean up your tag groups, then convert them yourself.** Craft 6 may convert tag groups to
   entry sections during its upgrade; doing it yourself on Craft 5 lets you choose the section and
   entry type, and means duplicates and unused tags don't come along.

   ```bash
   php craft tidytags/entrify-check          # what each group needs
   php craft tidytags/duplicates --source=group:topics
   php craft tidytags/unused --source=group:topics --delete --apply
   php craft entrify/tags topics
   ```

3. **Point Tidy Tags at the new sections.** In **Settings → Tidy Tags**, tick each new section
   under *Tag-like sections*, and under *Allow rename, merge and delete* if you want to keep merging
   and pruning it. Do this on Craft 5, so the settings are already in project config.

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), and require
Tidy Tags 6 at the same time as Craft:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-tidytags:^6.0@beta" -W
php artisan craft:up
```

Drop the `@beta` flags once Craft 6 and Tidy Tags 6 have stable releases.

## What carries over

- **Settings.** Same keys in project config: tag-like and writable sections, match strategy, affix
  and qualifier tokens, per-section field configuration and page sizes.
- **Config file.** Move `config/tidytags.php` to `config/craft/tidytags.php`. The keys are the same.
- **Permissions.** Same names (`accessPlugin-tidytags`, `tidytags-manageTags`,
  `tidytags-deleteTags`), so user and group permissions carry over.
- **Console command names.** `php craft tidytags/duplicates`, `tidytags/merge` and
  `tidytags/unused` still work. The new names are `tidytags:duplicates` and so on.

## What changed

- **No tag groups.** Settings that referred to tag groups (per-source field configuration keyed by
  a tag group's UID) no longer apply. Configure the section the group became instead.
- **`tidytags/entrify-check` is gone.** It only makes sense while tag groups exist; run it on 5.x.
- **Exit codes.** Bad options and items that can't be merged exit `2` (Symfony's *invalid*) instead
  of Yii's `64` and `65`. Failures still exit `1`.
- **Control panel endpoints.** The actions are CP routes now, under your CP trigger:
  `admin/tidytags/tags/merge` and so on, instead of `actions/tidytags/tags/merge`. If you called
  them from your own code, update the URLs. They take the same parameters.
- **"Did you mean?"** runs on the title field of entries in tag-like sections, on the entry's edit
  page. In Craft 6 it doesn't run in the slideout an Entries field opens for *New entry* yet.
- **Service API.** The deprecated `Tags::renameTag()`, `deleteTag()`, `deleteTags()`, `mergeTags()`
  and `deleteUnusedTags()` are gone; the service is `JustinHoltWeb\TidyTags\Services\Items` with
  `rename()`, `delete()`, `merge()` and the rest. `DuplicateDetector::findSimilar()` no longer takes
  a tag group ID.
