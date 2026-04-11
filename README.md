# Tidy Tags

A tag manager for Craft CMS 5 with multi-site support, duplicate detection, and optional read-only support for entry-backed tags.

Tidy Tags gives you a control panel section for auditing, cleaning, and merging tags across every site in your Craft install. It also watches tag fields while editors are working and warns them when a new tag looks like an existing one, so your taxonomy stays tidy.

If you've [entrified](https://craftcms.com/blog/entrification) your tags with `php craft entrify/tags`, you can point Tidy Tags at the resulting channel sections and they'll show up on the dashboard and in the duplicate scanner alongside native tag groups. Entry-backed sources are intentionally read-only — see [Tag-like entry sections](#tag-like-entry-sections) below.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

Until this plugin is published to the Craft Plugin Store, install it as a path repository.

1. Clone or place this repo next to your Craft project, e.g. `../craft-tidytags`.
2. In your project's `composer.json`, add the path repository:

    ```json
    {
        "repositories": [
            { "type": "path", "url": "../craft-tidytags" }
        ],
        "require": {
            "justinholtweb/craft-tidytags": "@dev"
        }
    }
    ```

3. Install the package:

    ```sh
    composer require justinholtweb/craft-tidytags
    ```

4. In the Craft control panel, go to **Settings → Plugins** and install **Tidy Tags**.

## Features

### Dashboard

Navigate to **Tidy Tags** in the CP sidebar. The dashboard lists every source in the install with:

- The source name, handle, and a **Tags** or **Entries** badge
- Total count (unique elements across all sites)
- Per-site counts

Click **Manage** on a row to open that source's list. "Sources" includes every native tag group plus any channel sections you've designated as tag-like (see below).

### Group view

The group view shows every tag in a group with per-site columns so you can see where a tag is translated and where it isn't. From here you can:

- **Filter** by site or search by title
- **Rename** a single tag (all sites or one site)
- **Merge** two or more tags — relations are re-pointed to the target tag, duplicates are de-duped, and source tags are deleted in a single transaction
- **Delete** one or more tags in bulk

All actions are multi-site aware. Choosing "All sites" in the filter shows every tag once (keyed to the primary site) with its translations in sibling columns; choosing a specific site scopes everything to that site.

### Duplicate scanner

The **Duplicates** tab scans every source and clusters near-duplicates using a Levenshtein threshold (default 2, configurable 1–6). For each cluster from a tag group you can pick which tag to keep and which to merge in, then confirm with one click. Clusters from entry-backed sources are shown but not merge-able — review them in their section.

Useful for cleaning up mistakes like:

- `marketing` / `Marketing` / `marketting`
- `javascript` / `JavaScript` / `java script`
- `New York` / `new york` / `new-york`

### "Did you mean?" warnings for editors

Tidy Tags ships a small JS asset bundle that loads on every control panel page. When an editor starts typing a new tag into a tag field, the plugin debounces the input, asks the `tidytags/tags/check-duplicate` action for similar tags in the same group, and shows a non-blocking amber notice below the field:

> Did you mean: **JavaScript**, **Javascripts**? (similar tags already exist)

The warning is informational — it never blocks the editor from creating a new tag — but it nudges people toward reusing existing taxonomy.

### Tag-like entry sections

Craft's `php craft entrify/tags` command converts a tag group into a channel section, turning each tag into an entry. Once entrified, your "tags" are regular entries with URLs, bodies, drafts, authors, and revisions.

Tidy Tags can surface these sections alongside native tag groups so the dashboard and duplicate scanner aren't suddenly empty after entrification. To opt a section in:

1. Go to **Settings → Plugins → Tidy Tags**.
2. Check each channel section you want surfaced under **Tag-like sections**.
3. Save. The section will now appear on the Tidy Tags dashboard with an **Entries** badge.

**Entry-backed sources are read-only.** Rename, merge, and delete are tag-only in Tidy Tags because entries carry URLs, bodies, drafts, and authorship that can't be safely mutated through a tag-sized interface — and Craft already has better UIs for editing entries. You still get:

- Dashboard counts (total + per-site)
- Per-site title browsing and search
- Duplicate-cluster detection across every site

The "did you mean?" editor warning is also intentionally tag-only — it hooks Craft's Tags field and does not fire on entries fields.

### Configuration file

The plugin settings screen is the primary place to manage tag-like sections. Every field on the settings model can also be driven from `config/tidytags.php`, which is overlaid on top of the saved settings at request time — handy for per-environment configuration:

```php
<?php
// config/tidytags.php
return [
    'tagLikeSectionUids' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b', // articleTags
        'c4e1be83-7c18-47e3-b6aa-2b5d1b9a94d2', // productCategories
    ],
];
```

Use section UIDs (not IDs or handles) so the config is stable across environments. You can find a section's UID in the URL of its settings page, or via `php craft entries/sections`.

## Permissions

Tidy Tags uses Craft's default plugin access permission: `accessPlugin-tidytags`. Grant it to any user group that should be able to view or manage tags through the dashboard.

## How merging works

When you merge tags, Tidy Tags:

1. Starts a database transaction.
2. Reads every row in `{{%relations}}` whose `targetId` is one of the source tags.
3. For each such row, checks whether a relation with the same `fieldId`, `sourceId`, and `sourceSiteId` already points at the target tag. If so, the source row is deleted (it would otherwise become a duplicate); if not, it's updated to point at the target.
4. Deletes each source tag element (all sites) via `Craft::$app->elements->deleteElement()`.
5. Commits the transaction.

This preserves every entry's relationship to the merged tag while cleaning up redundant rows.

After large merges you may want to run:

```sh
php craft clear-caches/all
```

to refresh Craft's element and template caches.

## Configuration

Plugin-wide settings (primarily the list of tag-like entry sections) live on the **Settings → Plugins → Tidy Tags** screen and can be overlaid from `config/tidytags.php`; see [Configuration file](#configuration-file) above. The duplicate similarity threshold is a query parameter on the Duplicates page (`?threshold=N`), and the "did you mean" endpoint accepts `title`, `groupId`, and `siteId` parameters if you want to call it from your own code.

## Action endpoints

| Action | Method | Params |
| --- | --- | --- |
| `tidytags/tags/check-duplicate` | GET | `title`, `groupId`, `siteId` |
| `tidytags/tags/rename` | POST | `tagId`, `title`, `siteId` |
| `tidytags/tags/delete` | POST | `tagId` or `tagIds[]` |
| `tidytags/tags/merge` | POST | `targetId`, `sourceIds[]` |

All mutating endpoints require the `accessPlugin-tidytags` permission and a CSRF token.

## License

This plugin is released under [the Craft License](LICENSE.md).
