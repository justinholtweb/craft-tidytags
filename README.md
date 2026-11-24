# Tidy Tags

Tidy Tags keeps your taxonomy clean, whether it lives in tag groups or entry sections. Built for Craft CMS 5, with multi-site support and duplicate detection across sources.

Tidy Tags gives you a control panel section for auditing, cleaning, and merging tags across every site in your Craft install. It compares tags against each other *and* against tag-like entry sections (Teams, Competitions, Topics — anything you've entrified or that should stay aligned), shows usages inline, and watches tag fields while editors are working so they're nudged toward existing taxonomy before they create yet another `England` tag.

Tags are deprecated in Craft 6. If you've [entrified](https://craftcms.com/blog/entrification) your tags with `php craft entrify/tags`, point Tidy Tags at the resulting channel sections and they show up on the dashboard, in the duplicate scanner and in the editor "did you mean?" warning alongside native tag groups. Allow rename, merge and delete for a section, and you can clean its entries the same way you clean tags. See [Tag-like entry sections](#tag-like-entry-sections) and [Preparing for Craft 6](#preparing-for-craft-6).

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
- **Page** through large groups — the view loads `pageSize` items at a time (100 by default), so a group with tens of thousands of tags opens as quickly as a small one
- **Rename** a single tag (all sites or one site)
- **Merge** two or more tags — relations are re-pointed to the target tag, duplicates are de-duped, and source tags are deleted in a single transaction
- **Delete** one or more tags in bulk

Entry sections get the same actions once they [allow rename, merge and delete](#allowing-rename-merge-and-delete). Rename, merge, and delete each need their own permission; see [Permissions](#permissions). All actions are multi-site aware. Choosing "All sites" in the filter shows every tag once (keyed to the primary site) with its translations in sibling columns; choosing a specific site scopes everything to that site.

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
- Has a **Show usages** button that lists every entry holding a relation to it, with edit links (the first 200)

Clusters are paged, `duplicatesPageSize` per page (25 by default), and only the clusters on the current page are loaded as elements.

Under the `fuzzy` strategy the Levenshtein threshold is configurable per-scan (default 2, range 0–6). The `strict` strategy has no distance to threshold, so the control has no effect there.

#### Large vocabularies

Scans are built for sources with tens of thousands of items. They work on IDs and titles rather than loaded elements, and look up likely matches through an index instead of comparing every title with every other. The results are identical to a full pairwise comparison, and a 17,000-title source clusters in well under a second. Results are cached and rebuilt automatically when a tag or entry is saved or deleted, or when the match settings change.

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

The same warning appears on the **title field of entries in tag-like sections**, on the full edit page and in the slideout an Entries field opens for **New entry**. That covers vocabularies you've already converted to entries, where there's no tag field to type into. Matches come from every tag-like section, and the entry being edited is left out. On entries in other sections the first lookup switches the check off, so ordinary content costs one request per editor screen. Editors need Tidy Tags access to see the warning.

### Tag-like entry sections

Craft's `php craft entrify/tags` command converts a tag group into a channel section, turning each tag into an entry. Once entrified, your "tags" are regular entries with URLs, bodies, drafts, authors, and revisions.

Tidy Tags can surface these sections alongside native tag groups so the dashboard and scanners aren't suddenly empty after entrification. To opt a section in:

1. Go to **Settings → Plugins → Tidy Tags**.
2. Check each channel section you want surfaced under **Tag-like sections**.
3. Save.

Every tag-like section gets:

- Dashboard counts (total + per-site)
- Per-site title browsing and search
- Within-source and cross-source duplicate clusters
- **Swap relations** — the safe primitive that re-points relations from one entry to another without touching the entries themselves
- Inclusion in the editor "did you mean?" warning when the editor is in a tag field

#### Allowing rename, merge and delete

A tag-like section is **read-only by default**, because entries can carry URLs, content and authors that a tag never had. For sections that hold a vocabulary rather than content, tick them under **Allow rename, merge and delete** in the settings (or list them in `writableSectionUids`). Only channel sections qualify. Those sections then get the same Rename, Merge and Delete actions as tag groups, on the Manage screen, on the Duplicates screen and in the console. A few things work differently from tags:

- **Entries go to the trash.** Deleted and merged entries are soft-deleted, so you can restore them from the section's trash. Restoring a merged entry doesn't bring its relations back; they stay on the entry that was kept.
- **Merging reports the URLs it retires.** If the section has URLs, the merge lists each old URL with the kept entry's URL in the same site. After a merge in the control panel, a notice offers the list as a CSV download (kept for a day). From the console, `--format=csv` prints it. Feed it to whatever redirect tool the site uses.
- **Merging stays within one section and its sites.** Every entry must be in the kept entry's section. The kept entry must also exist in every site the merged entries exist in, and in every site whose relations would move to it. Otherwise Tidy Tags refuses the merge and says why.
- **Entry permissions apply.** Renaming needs the right to save the entry. Deleting and merging need the right to delete each entry being removed, as well as Tidy Tags' own permissions.
- **Generated titles can't be renamed.** If an entry type has no title field, its titles come from a format, and a rename would be overwritten on the next save, so Tidy Tags refuses it.

### Configuration file

The plugin settings screen is the primary place to manage tag-like sections and per-source field config. Every field on the settings model can also be driven from `config/tidytags.php`, which is overlaid on top of the saved settings at request time — handy for per-environment configuration:

```php
<?php
// config/tidytags.php
return [
    // 'fuzzy' (default) or 'strict' — see Matching strategies above.
    'matchStrategy' => 'strict',

    // Items per page on the group (Manage) view. 1–1000, default 100.
    'pageSize' => 250,

    // Clusters per page on the Duplicates screen. 1–500, default 25.
    'duplicatesPageSize' => 50,

    // Only used by the strict strategy. Arrays here, or a newline/comma
    // separated string, or leave them out to keep the defaults.
    'affixTokens'     => ['fc', 'afc', 'cf', 'sc', 'ac'],
    'qualifierTokens' => ['women', 'ladies', 'ii', 'reserves', 'u21', 'one', 'two'],

    'tagLikeSectionUids' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b', // teams
        'c4e1be83-7c18-47e3-b6aa-2b5d1b9a94d2', // competitions
    ],
    // Tag-like sections whose entries may be renamed, merged and deleted.
    // UIDs that aren't also in tagLikeSectionUids are ignored.
    'writableSectionUids' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b', // teams
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

## Preparing for Craft 6

Craft 6 deprecates Tags, and upgrading may convert your tag groups to entry sections for you. Converting them yourself on Craft 5 with `php craft entrify/tags` lets you choose the section and entry type. Cleaning up first means the duplicates and unused tags don't come along.

Run the readiness check:

```sh
php craft tidytags/entrify-check
php craft tidytags/entrify-check --format=json
php craft tidytags/entrify-check --fail-on-issues   # exit 1 while any group has duplicates or unused tags
```

For each tag group it reports the number of tags, how many nothing uses, how many duplicate clusters the current match strategy finds, and which Tags fields point at the group. It then prints the commands to run, in order:

1. Review duplicates with `tidytags/duplicates --source=group:<handle>` and merge them.
2. Prune unused tags with `tidytags/unused --source=group:<handle> --delete --apply`.
3. Convert with `php craft entrify/tags <handle>`.

Then add the new section under **Settings → Tidy Tags** as a tag-like section, and allow rename, merge and delete for it so you can keep cleaning it here. The dashboard shows a reminder for as long as you have tag groups.

## Console commands

Everything the Duplicates screen and the Manage screen's merge do is also available from the command line, for vocabularies too large to page through and for CI reports. The commands are trusted the way any `php craft` command is: they don't check a user's permissions, and merge doesn't check who can edit the entries whose relations move.

### `tidytags/duplicates`

Scans for near-duplicates with the same service the Duplicates screen uses, so both report the same clusters. Read-only.

```sh
php craft tidytags/duplicates
php craft tidytags/duplicates --source=topics --strategy=strict --format=json
php craft tidytags/duplicates --scope=cross --format=csv > duplicates.csv
php craft tidytags/duplicates --fail-on-duplicates
```

| Option | |
| --- | --- |
| `--source` | One tag group or tag-like section, by handle. Use `group:<handle>` or `section:<handle>` when a handle names both. |
| `--scope` | `within` (default) or `cross` — clusters that span more than one source. Can't be combined with `--source`. |
| `--strategy` | `fuzzy` or `strict` for this run only. The saved `matchStrategy` setting is left alone. |
| `--threshold` | Levenshtein threshold for `fuzzy`, 0–6 (default 2). |
| `--site` | Site handle whose titles are compared (default: the primary site). |
| `--format` | `table` (default), `json` or `csv`. |
| `--fail-on-duplicates` | Exit with status 1 when any clusters are found, so a CI job fails on new duplicates. |

### `tidytags/merge`

Merges tags, or entries in a section that allows it, into one, exactly like the Merge button. Relations move to the target, relations the target already has are dropped, and the merged items are deleted (entries to the trash), all in one transaction.

```sh
php craft tidytags/merge --into=12 34 56                     # dry run
php craft tidytags/merge --into=12 34,56 --apply             # merge
php craft tidytags/merge --into=12 34 --apply --format=csv   # merge, printing the retired URLs as CSV
```

**It is a dry run unless you pass `--apply`.** The dry run lists each item with how many of its relations would move and how many would be dropped, plus any URLs the merge would retire. `--dry-run` always wins, even next to `--apply`. Every item must be in the target's tag group or section, and pass the [entry merge rules](#allowing-rename-merge-and-delete); otherwise nothing is merged. `--format=json` prints the plan as JSON. `--format=csv` prints only the URL map (`site,sourceId,sourceTitle,oldUrl,newUrl`).

### `tidytags/unused`

Lists tags and tag-like entries that nothing relates to — no row in `{{%relations}}` targets them from any field, element or site — and optionally deletes them.

```sh
php craft tidytags/unused
php craft tidytags/unused --source=topics --format=csv
php craft tidytags/unused --delete                 # dry run
php craft tidytags/unused --delete --apply         # delete
```

`--delete` removes unused tags, and unused entries in sections that allow it (to the trash). Entries in read-only sections are listed but never deleted, and `--delete` refuses a read-only `--source`. **`--delete` is a dry run unless you pass `--apply`**, and `--dry-run` always wins. Each item is checked again just before it is deleted, so one that picked up a relation since the scan is kept and reported.

### Output and exit codes

`--format=json` and `--format=csv` write only the report to stdout; every note goes to stderr, so the output can be piped into `jq` or a file. CSV cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with `'` so a spreadsheet won't run a tag title as a formula.

| Exit | Meaning |
| --- | --- |
| `0` | Success, or a dry run |
| `1` | `--fail-on-duplicates` found duplicates, `--fail-on-issues` found a group to clean, a merge failed (and was rolled back), or an item couldn't be deleted |
| `64` | Bad options or arguments |
| `65` | The items can't be merged (not writable, in different groups or sections, or missing from a site) |

## Permissions

| Permission | Allows |
| --- | --- |
| `accessPlugin-tidytags` | Browsing sources, the Duplicates scanner, and the usage lookups |
| `tidytags-manageTags` | Renaming tags and tag-like entries, and swapping relations |
| `tidytags-deleteTags` | Deleting tags and tag-like entries (nested under *manage*) |

Merging needs both *manage* and *delete*, since it deletes the merged items. For entries, Craft's own entry permissions apply on top: renaming needs the right to save the entry, and deleting or merging needs the right to delete each entry being removed.

Merge and swap also edit every element that holds a relation to the tags involved, so they check that the user can save each of those elements. Someone who can't edit entries in a section can't merge or swap tags that those entries use. Swap only accepts tags and entries in configured tag-like sections, and the usage lookup leaves out elements the user can't view.

Admins have every permission. Before 5.3.0, plugin access was enough for every action, so grant the new permissions to any non-admin group that should keep renaming, merging, or deleting.

## How merging works

When you merge tags or entries, Tidy Tags:

1. Checks the merge is allowed: same tag group or section, every item writable, and for entries the site rules above.
2. Records the URLs the merged items have in each site, with the kept item's URL there.
3. Starts a database transaction.
4. Reads every row in `{{%relations}}` whose `targetId` is one of the source items.
5. For each such row, checks whether a relation with the same `fieldId`, `sourceId`, and `sourceSiteId` already points at the target. If so, the source row is deleted (it would otherwise become a duplicate); if not, it's updated to point at the target.
6. Deletes each source item (all sites) via `Craft::$app->elements->deleteElement()`: tags outright, entries to the trash.
7. Commits the transaction.

This preserves every entry's relationship to the merged tag while cleaning up redundant rows.

## How swap works

**Swap** is the element-type-agnostic version of merge. It runs the same relation re-pointing logic (steps 1–3 above) inside a transaction but **never deletes the source elements**. Use it when:

- You're consolidating across sources (Tag → Team, Team → Competition, etc.)
- You're cleaning up entry-backed duplicates and want to verify by hand before deleting

After a swap, the source entries are orphaned (no relations point at them) but still present. Delete them through Craft's normal entry UI once you're satisfied.

Merge and swap rewrite `{{%relations}}` directly, so afterwards they invalidate element and template caches for every element type they touched.

## Configuration

Plugin-wide settings (match strategy, affix and qualifier tokens, tag-like and writable sections, per-source field config, and page sizes) live on the **Settings → Plugins → Tidy Tags** screen and can be overlaid from `config/tidytags.php`; see [Configuration file](#configuration-file) above. The duplicate similarity threshold is a query parameter on the Duplicates page (`?threshold=N`), and the "did you mean" endpoint accepts `title`, `groupId`, and `siteId` parameters if you want to call it from your own code.

## Action endpoints

| Action | Method | Params |
| --- | --- | --- |
| `tidytags/tags/check-duplicate` | GET | `title`, `groupId` or `elementId`, `siteId` |
| `tidytags/tags/usages` | GET | `elementId` |
| `tidytags/tags/rename` | POST | `tagId`, `title`, `siteId` |
| `tidytags/tags/delete` | POST | `tagId` or `tagIds[]` |
| `tidytags/tags/merge` | POST | `targetId`, `sourceIds[]` |
| `tidytags/tags/swap` | POST | `targetId`, `sourceIds[]` |
| `tidytags/tags/url-report` | GET | `report` |

`tagId` and `tagIds[]` accept entry IDs from sections that allow rename, merge and delete. As JSON, merge returns `success`, `message` (why it was refused), `urls` (the retired URLs) and `urlReport` (a CSV download link, or null when no URLs were retired). The CSV link only works for the user who ran the merge, for a day.

Every endpoint requires `accessPlugin-tidytags`. The same operations are available as [console commands](#console-commands). The mutating endpoints also need a CSRF token and the permissions listed under [Permissions](#permissions).

## Development

The repo ships a [DDEV](https://ddev.com) config and a Codeception suite that runs the plugin's services against a real, freshly installed Craft 5 database.

```sh
ddev start
ddev composer install
ddev exec vendor/bin/codecept run unit
```

The suite installs Craft into the DDEV database from scratch on every run and wraps each test in a transaction, so it needs no seeded project config and leaves nothing behind. Test-only Craft config lives in `tests/_craft/`, and `tests/support/Fixtures.php` builds tag groups, tag-like channel sections, custom fields, extra sites, and raw relation rows.

Integration checks for the console commands run as a plain PHP script inside a Craft install that has the plugin installed (they create and remove their own `tidytagsCheck` tag groups):

```sh
php /path/to/craft-tidytags/tests/integration/console.php   # from the Craft project root
```

To run a single test class:

```sh
ddev exec vendor/bin/codecept run unit DuplicateDetectorTest
```

## License

This plugin is released under [the Craft License](LICENSE.md).
