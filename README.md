# Tidy Tags

Tidy Tags keeps your Craft CMS taxonomy clean. It finds near-duplicate titles in your tag-like entry sections — Topics, Teams, Competitions, anything that's a vocabulary rather than content — and lets you merge, rename and prune them across every site. While editors work, it warns them that `England (Football)` already exists before they create yet another `England`.

This is the **Craft 6** line. Craft 6 has no Tags: tag groups become entry sections, and Tidy Tags works on those. For Craft 5, use Tidy Tags 5.x.

> **Beta.** Tidy Tags 6 is in beta, like Craft 6 itself. Upgrading from Craft 5? Read [UPGRADING.md](UPGRADING.md) first.

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5 or later

## Installation

```bash
composer require "justinholtweb/craft-tidytags:^6.0@beta"
php artisan craft:plugin:install tidytags
```

Then, in **Settings → Tidy Tags**, tick the channel sections that hold your vocabularies under **Tag-like sections**.

## Sources

A source is a **channel section you've marked as tag-like**. Each one gets:

- Dashboard counts, in total and per site
- A Manage screen with per-site titles and search, a page at a time
- Within-section and cross-section duplicate scans
- **Swap relations** — re-points relations from one entry to another without touching the entries themselves
- The "did you mean?" warning on its entries' titles

### Allowing rename, merge and delete

A tag-like section is **read-only by default**, because entries can carry URLs, content and authors. For sections that hold a vocabulary rather than content, tick them under **Allow rename, merge and delete** (or list them in `writableSectionUids`). Only channel sections qualify. They then get Rename, Merge and Delete on the Manage and Duplicates screens and in the console:

- **Entries go to the trash.** Deleted and merged entries are soft-deleted and can be restored from the section's trash. Restoring a merged entry doesn't bring its relations back; they stay on the entry that was kept.
- **Merging reports the URLs it retires.** Each old URL is listed with the kept entry's URL in the same site. After a merge in the control panel, a notice offers the list as a CSV download (kept for a day, for the person who merged). `tidytags:merge --format=csv` prints it.
- **Merging stays within one section and its sites.** Every entry must be in the kept entry's section, and the kept entry must exist in every site the others — or the relations being moved — are in. Otherwise the merge is refused, with the reason.
- **Entry permissions apply.** Renaming needs the right to edit each site involved and save the entry there; merging and deleting need the right to delete each entry removed; merge and swap need the right to save every element whose relations move.
- **Generated titles can't be renamed.** An entry type with no title field gets its titles from a format, which would overwrite a rename.

## Finding duplicates

### Matching strategies

Everything in Tidy Tags that asks "are these two titles the same thing?" — the duplicate scanner, the cross-source scan, the editor warning — goes through one service, so they always agree. How it answers is set by **Match strategy** under **Settings → Tidy Tags**.

| | `fuzzy` (default) | `strict` |
| --- | --- | --- |
| Case, whitespace, punctuation | ignored | ignored |
| Typos | matched, within a Levenshtein threshold | not matched |
| Leading/trailing affixes (`FC`, `AFC`, `CF`…) | not matched | matched |
| Qualifiers (`Women`, `II`, `U21`, `One`…) | ignored | veto the match |

**Pick `fuzzy`** if your vocabulary is free text typed by editors. The duplicates you have are misspellings, and that is what edit distance is for: `Manchestor` gets flagged against `Manchester`.

**Pick `strict`** if your vocabulary is a controlled vocabulary of proper nouns — clubs, competitions, places, people. Edit distance is the wrong tool for these, because such names sit close together in edit space while the variation that actually occurs is a whole affix word.

Measured against a real 2,633-title corpus of teams, competitions and tags:

```
fuzzy    Essex   ~ Sussex      Durham ~ Fulham      Tampa ~ Samoa     <- all false positives
         Top 10  ~ Top 14
         ...and missed FC Bayern Munich ~ Bayern Munich (3 edits apart)

strict   FC Bayern Munich ~ Bayern Munich        Wrexham    ~ Wrexham AFC
         Real Madrid      ~ Real Madrid CF       Barrow AFC ~ Barrow
         ...and none of the above false positives
```

Neither is better in general, which is why it is a setting and not a change of default. 

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

### The Duplicates screen

Two scopes:

- **Within source** — near-duplicates inside each section. Writable sections offer **Merge cluster**; read-only ones offer **Swap relations**.
- **Across sources** — clusters that span sections, such as a Topic created for something already kept as a Team. Always **Swap relations**, never a delete.

Each item links to its entry, shows its section and any configured display fields, and has **Show usages**, which lists the first 200 elements that relate to it. Clusters are paged (`duplicatesPageSize`, 25 by default). Under `fuzzy` the threshold is set per scan (0–6, default 2).

Scans work on IDs and titles and find candidates through an index, so a 17,000-title section clusters in well under a second. Results are cached until the next entry save or delete.

### Differentiator and display fields

Under each tag-like section in **Settings → Tidy Tags**:

- **Differentiator field** — two items with the same title but different values here are *not* duplicates. `England (Football)` and `England (Cricket)` stay apart, while a stray `England` with no value is still flagged against both.
- **Display fields** — shown next to each item in clusters and the editor warning.

### "Did you mean?" for editors

On the edit page of an entry in a tag-like section, Tidy Tags checks the title as it's typed and lists similar entries from every tag-like section, linked, below the field. It's a nudge, never a block. Entries in other sections cost one request per screen, after which the check switches itself off. Editors need Tidy Tags access to see it. The slideout an Entries field opens for **New entry** isn't covered yet.

## Console commands

The console is trusted the way any `php artisan` command is: it doesn't check a user's permissions.

```bash
php artisan tidytags:duplicates
php artisan tidytags:duplicates --source=topics --strategy=strict --format=json
php artisan tidytags:duplicates --scope=cross --format=csv > duplicates.csv
php artisan tidytags:duplicates --fail-on-duplicates            # CI: exit 1 when any are found

php artisan tidytags:merge --into=12 34 56                      # dry run
php artisan tidytags:merge --into=12 34,56 --apply              # merge
php artisan tidytags:merge --into=12 34 --apply --format=csv    # merge, printing the retired URLs

php artisan tidytags:unused --source=topics --format=csv
php artisan tidytags:unused --delete                            # dry run
php artisan tidytags:unused --delete --apply                    # trash them
```

Merge and `--delete` are **dry runs unless you pass `--apply`**, and `--dry-run` always wins. Unused entries in read-only sections are listed but never deleted. The Craft 5 names (`tidytags/duplicates`, `tidytags/merge`, `tidytags/unused`) still work.

With `--format=json` or `csv`, only the report goes to stdout and every note goes to stderr. CSV cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading `'`, so a spreadsheet won't run a title as a formula.

| Exit | Meaning |
| --- | --- |
| `0` | Success, or a dry run |
| `1` | Duplicates found with `--fail-on-duplicates`, a merge failed (and was rolled back), or an entry couldn't be deleted |
| `2` | Bad options, or the entries can't be merged |

## Permissions

| Permission | Allows |
| --- | --- |
| `accessPlugin-tidytags` | Browsing sources, the Duplicates screen, usage lookups and the editor warning |
| `tidytags-manageTags` | Renaming and swapping relations |
| `tidytags-deleteTags` | Deleting (nested under *manage*); merging needs both |

Craft's own entry and site permissions apply on top; see [Allowing rename, merge and delete](#allowing-rename-merge-and-delete).

## Configuration

Everything on the settings screen can be set in `config/craft/tidytags.php`:

```php
<?php

return [
    'matchStrategy' => 'strict',
    'affixTokens' => ['fc', 'afc', 'cf'],
    'qualifierTokens' => ['women', 'ii', 'reserves', 'u21'],
    'tagLikeSectionUids' => ['af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b'],
    'writableSectionUids' => ['af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b'],
    'sourceFieldConfig' => [
        'af2a1ee1-7a4b-4d9a-bc0b-6b3b5b9f3c8b' => ['differentiator' => 'sport', 'display' => ['sport', 'country']],
    ],
    'pageSize' => 250,
    'duplicatesPageSize' => 50,
];
```

## Control panel endpoints

All under your CP trigger, and all need `accessPlugin-tidytags`; the POSTs need a CSRF token and the permissions above.

| Route | Method | Params |
| --- | --- | --- |
| `tidytags/tags/check-duplicate` | GET | `title`, `elementId`, `siteId` |
| `tidytags/tags/usages` | GET | `elementId` |
| `tidytags/tags/rename` | POST | `tagId`, `title`, `siteId` |
| `tidytags/tags/delete` | POST | `tagId` or `tagIds[]` |
| `tidytags/tags/merge` | POST | `targetId`, `sourceIds[]` |
| `tidytags/tags/swap` | POST | `targetId`, `sourceIds[]` |
| `tidytags/tags/url-report` | GET | `report` |

Ask for JSON (`Accept: application/json`) and merge answers with `urls` (the retired URLs) and `urlReport` (a CSV link, or null).

## Troubleshooting

- **A section doesn't appear on the dashboard.** Only channel sections can be tag-like, and they must be ticked under *Tag-like sections*.
- **No Rename, Merge or Delete buttons.** The section must also be ticked under *Allow rename, merge and delete*, and you need the Tidy Tags permissions.
- **A merge is refused.** The message says why: a different section, an entry missing from a site, or a draft. Use Swap for cross-section clean-ups.
- **The editor warning doesn't show.** It needs Tidy Tags access and only runs on entries in tag-like sections, on the full edit page.

## Development

Tests run in the Craft 6 harness container (PHP 8.5), on an in-memory SQLite install of Craft and the plugin:

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

## License

This plugin is released under [the Craft License](LICENSE.md).
