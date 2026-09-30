# Tidy Tags

A tag manager for Craft CMS 5 with multi-site support, cross-source duplicate detection, and optional read-only support for entry-backed tags.

Tidy Tags gives you a control panel section for auditing, cleaning, and merging tags across every site in your Craft install. It compares tags against each other *and* against tag-like entry sections (Teams, Competitions, Topics — anything you've entrified or that should stay aligned), shows usages inline, and watches tag fields while editors are working so they're nudged toward existing taxonomy before they create yet another `England` tag.

If you've [entrified](https://craftcms.com/blog/entrification) your tags with `php craft entrify/tags`, you can point Tidy Tags at the resulting channel sections and they'll show up on the dashboard, in the duplicate scanner, and in the editor "did you mean?" warning alongside native tag groups. Entry-backed sources stay read-only for destructive actions but support relation swap — see [Tag-like entry sections](#tag-like-entry-sections) below.

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

Click **Manage** on a row to open that source's list. "Sources" includes every native tag group plus any channel sections you've designated as tag-like.

### Group view

The group view shows every tag in a group with per-site columns so you can see where a tag is translated and where it isn't. From here you can:

- **Filter** by site or search by title
- **Rename** a single tag (all sites or one site)
- **Merge** two or more tags — relations are re-pointed to the target tag, duplicates are de-duped, and source tags are deleted in a single transaction
- **Delete** one or more tags in bulk

All actions are multi-site aware. Choosing "All sites" in the filter shows every tag once (keyed to the primary site) with its translations in sibling columns; choosing a specific site scopes everything to that site.

### Matching strategies

Everything in Tidy Tags that asks "are these two titles the same thing?" — the duplicate scanner, the cross-source scan, the editor warning — goes through one service, so they always agree. How it answers is set by **Match strategy** under **Settings → Plugins → Tidy Tags**.

| | `fuzzy` (default) | `strict` |
| --- | --- | --- |
| Case, whitespace, punctuation | ignored | ignored |
| Typos | matched, within a Levenshtein threshold | not matched |
| Leading/trailing affixes (`FC`, `AFC`, `CF`…) | not matched | matched |
| Qualifiers (`Women`, `II`, `U21`, `One`…) | ignored | veto the match |

**Pick `fuzzy`** if your tags are free text typed by editors. The duplicates you have are misspellings, and that is what edit distance is for: `Manchestor` gets flagged against `Manchester`.

**Pick `strict`** if your tags are a controlled vocabulary of proper nouns — clubs, competitions, places, people. Edit distance is the wrong tool for these, because such names sit close together in edit space while the variation that actually occurs is a whole affix word.

Measured against a real 2,633-title corpus of teams, competitions and tags:

```
fuzzy    Essex   ~ Sussex      Durham ~ Fulham      Tampa ~ Samoa     <- all false positives
         Top 10  ~ Top 14
         ...and missed FC Bayern Munich ~ Bayern Munich (3 edits apart)

strict   FC Bayern Munich ~ Bayern Munich        Wrexham    ~ Wrexham AFC
         Real Madrid      ~ Real Madrid CF       Barrow AFC ~ Barrow
         ...and none of the above false positives
```

Neither is better in general, which is why it is a setting and not a change of default. Upgrading to 5.2.0 changes nothing until you switch it.

#### Affix tokens

Under `strict`, these words are stripped from a title before comparing — but only from the **start and end**. An affix in the middle is left alone, so `FIFA Club World Cup` is not quietly reduced to `FIFA World Cup` and reported as a duplicate of a separate competition.

The default list covers the club prefixes and suffixes common in association football and rugby. Replace it with whatever your domain uses.

#### Qualifier tokens

Under `strict`, these are words that make two otherwise-matching titles *different things*. When one title carries one and the other does not, the pair is rejected outright:

- `Arsenal` is not a duplicate of `Arsenal Women`
- `County Championship` is not a duplicate of `County Championship One`
- `Bayern Munich` is not a duplicate of `Bayern Munich II`

Note how this differs from a differentiator field, deliberately. A missing differentiator value means *unknown*, so the cluster is still surfaced for review. A qualifier word in the title is a positive statement about which thing this is, so it blocks the match.

This list is the accuracy-critical setting in `strict` mode, and it is domain-specific — a cricket, rugby or futsal vocabulary needs different words from a football one. Expect to curate it against your own titles rather than relying on the default.

### Duplicate scanner

The **Duplicates** tab has two scopes:

- **Within source** — clusters near-duplicates inside a single tag group or section. For tag-group clusters you can pick a target and merge in one click. Entry-backed clusters offer **Swap relations** instead, which re-points relations from the duplicate entries onto the chosen target without deleting anything.
- **Across sources** — pools every source together and surfaces clusters that span more than one source. Useful when an editor has created a Tag for something already maintained as a Team or Competition. Always uses **Swap relations**, never auto-deletes.

Each cluster item:

- Links to its tag/entry edit screen
- Shows the source it came from with a **Tag** or **Entry** badge
- Shows configured display field values (e.g. `sport: Football`) so you can tell same-named items apart
- Has a **Show usages** button that lists every entry holding a relation to it, with edit links

Under the `fuzzy` strategy the Levenshtein threshold is configurable per-scan (default 2, range 1–6). The `strict` strategy has no distance to threshold, so the control has no effect there.

#### Differentiator and display fields

Under **Settings → Plugins → Tidy Tags** you can pick, per source:

- **Differentiator field** — when set, two items with the same normalized title but different differentiator values are *not* clustered as duplicates. This is how `England (Football)` and `England (Cricket)` stay distinct from each other while a stray `England` tag still gets flagged against both.
- **Display fields** — values appear next to each item in clusters and the editor warning, so reviewers can disambiguate at a glance.

When one item has a differentiator value and the other doesn't (e.g. a Tag with no field vs. a Team with `sport: Football`), the cluster is still shown — Tidy Tags treats "missing" as "unknown, surface for review" rather than "definitely different".

### "Did you mean?" warnings for editors

Tidy Tags ships a small JS asset bundle that loads on every control panel page. When an editor starts typing a new tag into a tag field, the plugin debounces the input, asks the `tidytags/tags/check-duplicate` action for similar items, and shows a non-blocking amber notice below the field listing matches across:

- The tag group the field is bound to
- Every configured tag-like entry section

Each match shows the differentiator value (if configured) and links directly to the existing item, so an editor about to create `England` in a tag field is told that `England (Football)` already exists as a Team and given a one-click way to open it. The warning is informational — it never blocks the editor — but it nudges people toward reusing existing taxonomy instead of duplicating.

### Tag-like entry sections

Craft's `php craft entrify/tags` command converts a tag group into a channel section, turning each tag into an entry. Once entrified, your "tags" are regular entries with URLs, bodies, drafts, authors, and revisions.

Tidy Tags can surface these sections alongside native tag groups so the dashboard and scanners aren't suddenly empty after entrification. To opt a section in:

1. Go to **Settings → Plugins → Tidy Tags**.
2. Check each channel section you want surfaced under **Tag-like sections**.
3. Save.

**Entry-backed sources are read-only for destructive actions.** Rename, merge, and delete are tag-only because entries carry URLs, bodies, drafts, and authorship that can't be safely mutated through a tag-sized interface — and Craft already has better UIs for editing entries. You still get:

- Dashboard counts (total + per-site)
- Per-site title browsing and search
- Within-source and cross-source duplicate clusters
- **Swap relations** — the safe primitive that re-points relations from one entry to another without touching the entries themselves
- Inclusion in the editor "did you mean?" warning when the editor is in a tag field

### Configuration file

The plugin settings screen is the primary place to manage tag-like sections and per-source field config. Every field on the settings model can also be driven from `config/tidytags.php`, which is overlaid on top of the saved settings at request time — handy for per-environment configuration:

```php
<?php
// config/tidytags.php
return [
    // 'fuzzy' (default) or 'strict' — see Matching strategies above.
    'matchStrategy' => 'strict',

    // Only used by the strict strategy. Arrays here, or a newline/comma
    // separated string, or leave them out to keep the defaults.
    'affixTokens'     => ['fc', 'afc', 'cf', 'sc', 'ac'],
    'qualifierTokens' => ['women', 'ladies', 'ii', 'reserves', 'u21', 'one', 'two'],

    'tagLikeSectionUids' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b', // teams
        'c4e1be83-7c18-47e3-b6aa-2b5d1b9a94d2', // competitions
    ],
    'sourceFieldConfig' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b' => [
            'differentiator' => 'sport',
            'display'        => ['sport', 'country'],
        ],
        'c4e1be83-7c18-47e3-b6aa-2b5d1b9a94d2' => [
            'differentiator' => 'sport',
            'display'        => ['sport'],
        ],
    ],
];
```

Use section UIDs (not IDs or handles) so the config is stable across environments. You can find a section's UID in the URL of its settings page, or via `php craft entries/sections`. Tag-group UIDs come from `php craft tags/groups` or the URL of the tag group's settings page.

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

## How swap works

**Swap** is the element-type-agnostic version of merge. It runs the same relation re-pointing logic (steps 1–3 above) inside a transaction but **never deletes the source elements**. Use it when:

- You're consolidating across sources (Tag → Team, Team → Competition, etc.)
- You're cleaning up entry-backed duplicates and want to verify by hand before deleting

After a swap, the source entries are orphaned (no relations point at them) but still present. Delete them through Craft's normal entry UI once you're satisfied.

After large merges or swaps you may want to run:

```sh
php craft clear-caches/all
```

to refresh Craft's element and template caches.

## Configuration

Plugin-wide settings (match strategy, affix and qualifier tokens, tag-like sections, and per-source field config) live on the **Settings → Plugins → Tidy Tags** screen and can be overlaid from `config/tidytags.php`; see [Configuration file](#configuration-file) above. The duplicate similarity threshold is a query parameter on the Duplicates page (`?threshold=N`), and the "did you mean" endpoint accepts `title`, `groupId`, and `siteId` parameters if you want to call it from your own code.

## Action endpoints

| Action | Method | Params |
| --- | --- | --- |
| `tidytags/tags/check-duplicate` | GET | `title`, `groupId`, `siteId` |
| `tidytags/tags/usages` | GET | `elementId` |
| `tidytags/tags/rename` | POST | `tagId`, `title`, `siteId` |
| `tidytags/tags/delete` | POST | `tagId` or `tagIds[]` |
| `tidytags/tags/merge` | POST | `targetId`, `sourceIds[]` |
| `tidytags/tags/swap` | POST | `targetId`, `sourceIds[]` |

All mutating endpoints require the `accessPlugin-tidytags` permission and a CSRF token.

## Development

The repo ships a [DDEV](https://ddev.com) config and a Codeception suite that runs the plugin's services against a real, freshly installed Craft 5 database.

```sh
ddev start
ddev composer install
ddev exec vendor/bin/codecept run unit
```

The suite installs Craft into the DDEV database from scratch on every run and wraps each test in a transaction, so it needs no seeded project config and leaves nothing behind. Test-only Craft config lives in `tests/_craft/`, and `tests/support/Fixtures.php` builds tag groups, tag-like channel sections, custom fields, extra sites, and raw relation rows.

To run a single test class:

```sh
ddev exec vendor/bin/codecept run unit DuplicateDetectorTest
```

## License

This plugin is released under [the Craft License](LICENSE.md).
