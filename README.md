# Tidy Tags

A tag manager for Craft CMS 5 with multi-site support and duplicate detection.

Tidy Tags gives you a control panel section for auditing, cleaning, and merging tags across every site in your Craft install. It also watches tag fields while editors are working and warns them when a new tag looks like an existing one, so your taxonomy stays tidy.

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

Navigate to **Tidy Tags** in the CP sidebar. The dashboard lists every tag group in the install with:

- The group name and handle
- Total tag count (unique elements across all sites)
- Per-site tag counts

Click **Manage** on a row to open that group's tag list.

### Group view

The group view shows every tag in a group with per-site columns so you can see where a tag is translated and where it isn't. From here you can:

- **Filter** by site or search by title
- **Rename** a single tag (all sites or one site)
- **Merge** two or more tags — relations are re-pointed to the target tag, duplicates are de-duped, and source tags are deleted in a single transaction
- **Delete** one or more tags in bulk

All actions are multi-site aware. Choosing "All sites" in the filter shows every tag once (keyed to the primary site) with its translations in sibling columns; choosing a specific site scopes everything to that site.

### Duplicate scanner

The **Duplicates** tab scans every tag group and clusters near-duplicates using a Levenshtein threshold (default 2, configurable 1–6). For each cluster you can pick which tag to keep and which to merge in, then confirm with one click.

Useful for cleaning up mistakes like:

- `marketing` / `Marketing` / `marketting`
- `javascript` / `JavaScript` / `java script`
- `New York` / `new york` / `new-york`

### "Did you mean?" warnings for editors

Tidy Tags ships a small JS asset bundle that loads on every control panel page. When an editor starts typing a new tag into a tag field, the plugin debounces the input, asks the `tidytags/tags/check-duplicate` action for similar tags in the same group, and shows a non-blocking amber notice below the field:

> Did you mean: **JavaScript**, **Javascripts**? (similar tags already exist)

The warning is informational — it never blocks the editor from creating a new tag — but it nudges people toward reusing existing taxonomy.

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

Tidy Tags has no config file at this time. The duplicate similarity threshold is a query parameter on the Duplicates page (`?threshold=N`), and the "did you mean" endpoint accepts `title`, `groupId`, and `siteId` parameters if you want to call it from your own code.

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
