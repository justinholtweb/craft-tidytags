<?php
/**
 * Tidy Tags console-command checks: duplicate scans, merge and unused-tag pruning — through
 * the services and through the real `php craft tidytags/…` commands.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-tidytags/tests/integration/console.php
 *
 * Self-cleaning: everything lives in the `tidytagsCheck` / `tidytagsCheckOther` tag groups,
 * which are swept at both ends. Every destructive command is scoped with --source so a check
 * can never touch the harness's other tags.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_fixtures.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\events\DeleteElementEvent;
use craft\services\Elements;
use justinholtweb\tidytags\console\controllers\MergeController;
use justinholtweb\tidytags\console\controllers\UnusedController;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\services\TitleMatcher;
use yii\base\Event;

sweep();
register_shutdown_function('sweep');

$savedStrategy = Craft::$app->getProjectConfig()->get('plugins.tidytags.settings.matchStrategy', true);

$group = makeGroup(TT_GROUP);
$other = makeGroup(TT_OTHER_GROUP);
saveProjectConfig();

$manchester = makeTag($group, 'Manchester United');
$typo = makeTag($group, 'Manchestor United');
$bayernFc = makeTag($group, 'FC Bayern Munich');
$bayern = makeTag($group, 'Bayern Munich');
$arsenal = makeTag($group, 'Arsenal');
$arsenalWomen = makeTag($group, 'Arsenal Women');
$formula = makeTag($group, '=HYPERLINK("http://example.test","x")');
$owner1 = makeTag($group, 'Owner One');
$owner2 = makeTag($group, 'Owner Two');
$foreign = makeTag($other, 'Manchester Uniteds');

// owner1 already relates to the target, so merging the typo drops that row; owner2 moves.
relate($owner1->id, $typo->id);
relate($owner2->id, $typo->id);
relate($owner1->id, $manchester->id);
relate($owner1->id, $bayernFc->id);

$src = '--source=' . TT_GROUP;

/** @return array<int, int[]> cluster ID lists from a JSON scan */
function clusterIds(array $json): array
{
    $out = [];
    foreach ($json['clusters'] as $c) {
        $ids = array_column($c['items'], 'id');
        sort($ids);
        $out[] = $ids;
    }
    return $out;
}

// -----------------------------------------------------------------------------------------------
section('tidytags/duplicates');

check('the default table run lists the typo cluster and exits 0', function() use ($src) {
    [$code, $out] = craftCmd("tidytags/duplicates $src --strategy=fuzzy");

    return $code === 0 && str_contains($out, 'Manchestor United') && str_contains($out, 'Manchester United')
        && str_contains($out, '1 cluster(s)') ?: "exit $code: $out";
});

check('--format=json is pure JSON on stdout (notes on stderr) with the fuzzy cluster only', function() use ($src, $manchester, $typo) {
    [$code, $out, $err] = craftCmd("tidytags/duplicates $src --strategy=fuzzy --format=json");
    $json = json_decode($out, true);
    $want = [$manchester->id, $typo->id];
    sort($want);

    return $code === 0 && is_array($json)
        && $json['strategy'] === 'fuzzy' && $json['threshold'] === 2 && $json['scope'] === 'within'
        && clusterIds($json) === [$want]
        && $json['clusters'][0]['source']['handle'] === TT_GROUP
        && str_contains($err, 'Strategy fuzzy')
        ?: "exit $code: $out / $err";
});

check('--strategy=strict finds the affix pair, keeps the qualifier pair apart and ignores the typo', function() use ($src, $bayern, $bayernFc) {
    [$code, $out] = craftCmd("tidytags/duplicates $src --strategy=strict --format=json");
    $json = json_decode($out, true);
    $want = [$bayern->id, $bayernFc->id];
    sort($want);

    return $code === 0 && $json['strategy'] === 'strict' && $json['threshold'] === null
        && clusterIds($json) === [$want] ?: "exit $code: $out";
});

check('--strategy only applies to the run: the saved setting is unchanged', function() use ($savedStrategy) {
    return Craft::$app->getProjectConfig()->get('plugins.tidytags.settings.matchStrategy', true) === $savedStrategy;
});

check('--threshold=0 under fuzzy finds nothing in the group', function() use ($src) {
    [$code, $out] = craftCmd("tidytags/duplicates $src --strategy=fuzzy --threshold=0 --format=json");
    $json = json_decode($out, true);

    return $code === 0 && $json['total'] === 0 && $json['threshold'] === 0 ?: "exit $code: $out";
});

check('--format=csv writes a header and one row per clustered item', function() use ($src, $manchester, $typo) {
    [$code, $out] = craftCmd("tidytags/duplicates $src --strategy=fuzzy --format=csv");
    $lines = array_values(array_filter(explode("\n", $out)));

    return $code === 0 && $lines[0] === 'cluster,id,title,source,sourceType,differentiator'
        && count($lines) === 3
        && str_contains($out, "1,{$manchester->id},\"Manchester United\"," . TT_GROUP . ',tag,')
        && str_contains($out, "1,{$typo->id},")
        ?: "exit $code: $out";
});

check('--fail-on-duplicates exits 1 when clusters exist', function() use ($src) {
    [$code, , $err] = craftCmd("tidytags/duplicates $src --strategy=fuzzy --fail-on-duplicates");

    return $code === 1 && str_contains($err, '1 duplicate cluster(s) found') ?: "exit $code: $err";
});

check('--fail-on-duplicates exits 0 for a clean source', function() {
    [$code, $out] = craftCmd('tidytags/duplicates --source=' . TT_OTHER_GROUP . ' --fail-on-duplicates');

    return $code === 0 && str_contains($out, 'No duplicates found') ?: "exit $code: $out";
});

check('--scope=cross returns valid JSON and pools sources (the cross-group near-match is found)', function() use ($foreign) {
    [$code, $out] = craftCmd('tidytags/duplicates --scope=cross --strategy=fuzzy --format=json');
    $json = json_decode($out, true);
    $found = false;
    foreach ($json['clusters'] ?? [] as $c) {
        $found = $found || in_array($foreign->id, array_column($c['items'], 'id'), true);
        if ($c['source'] !== null) {
            return 'cross clusters must have no single source';
        }
    }

    return $code === 0 && $json['scope'] === 'cross' && $found ?: "exit $code: " . substr($out, 0, 500);
});

foreach ([
    'an unknown --format' => "tidytags/duplicates $src --format=xml",
    'an unknown --scope' => "tidytags/duplicates --scope=everywhere",
    'an unknown --strategy' => "tidytags/duplicates $src --strategy=loose",
    'a --threshold above 6' => "tidytags/duplicates $src --threshold=9",
    'an unknown --source' => 'tidytags/duplicates --source=noSuchSourceXyz',
    'an unknown --site' => "tidytags/duplicates $src --site=noSuchSiteXyz",
    '--source with --scope=cross' => "tidytags/duplicates $src --scope=cross",
] as $label => $cmd) {
    check("$label is a usage error (64)", function() use ($cmd) {
        [$code, , $err] = craftCmd($cmd);

        return $code === 64 && trim($err) !== '' ?: "exit $code: $err";
    });
}

// -----------------------------------------------------------------------------------------------
section('Sources: handles and unused lookup');

$entrySection = Craft::$app->getEntries()->getSectionByHandle('promoTopics')
    ?? Craft::$app->getEntries()->getSectionByHandle('news');

check('group:/section: prefixes pick a source type; a bare handle matches either', function() use ($entrySection) {
    if ($entrySection === null) {
        return 'no channel section in the harness to configure as tag-like';
    }
    tt()->getSettings()->tagLikeSectionUids = [$entrySection->uid];
    $s = tt()->sources;

    return count($s->findSourcesByHandle(TT_GROUP)) === 1
        && $s->findSourcesByHandle('group:' . TT_GROUP)[0]->type === Source::TYPE_TAG
        && $s->findSourcesByHandle('section:' . TT_GROUP) === []
        && $s->findSourcesByHandle('section:' . $entrySection->handle)[0]->type === Source::TYPE_ENTRY
        && $s->findSourcesByHandle('group:' . $entrySection->handle) === []
        && $s->findSourcesByHandle('nope') === [];
});

check('getUnused() on an entry-backed source returns only entries no relation targets', function() use ($entrySection) {
    $source = Source::fromSection($entrySection);
    $unused = tt()->sources->getUnused($source);
    $all = Entry::find()->sectionId($entrySection->id)->status(null)->site('*')->unique()->ids();
    $used = (new Query())->select(['targetId'])->distinct()->from(['{{%relations}}'])->where(['targetId' => $all])->column();
    $expect = array_values(array_diff(array_map('intval', $all), array_map('intval', $used)));
    $got = array_keys($unused);
    sort($expect);
    sort($got);

    return $got === $expect ?: 'got ' . json_encode($got) . ' want ' . json_encode($expect);
});

check('unused --delete on an entry-backed source is refused (64)', function() use ($entrySection) {
    $c = new UnusedController('unused', tt());
    $c->source = 'section:' . $entrySection->handle;
    $c->delete = true;
    $c->apply = true;

    return $c->actionIndex() === 64;
});

tt()->getSettings()->tagLikeSectionUids = [];

// -----------------------------------------------------------------------------------------------
section('tidytags/merge');

foreach ([
    'a missing --into' => "tidytags/merge {$typo->id}",
    'a non-numeric ID' => "tidytags/merge --into={$manchester->id} abc",
    'an empty ID list' => "tidytags/merge --into={$manchester->id} ,",
    'an unknown --format' => "tidytags/merge --into={$manchester->id} {$typo->id} --format=csv",
] as $label => $cmd) {
    check("$label is a usage error (64)", function() use ($cmd) {
        [$code, , $err] = craftCmd($cmd);

        return $code === 64 && trim($err) !== '' ?: "exit $code: $err";
    });
}

check('no IDs at all is refused by Yii before the command runs (non-zero)', function() use ($manchester) {
    [$code, $out, $err] = craftCmd("tidytags/merge --into={$manchester->id}");

    return $code !== 0 && str_contains($out . $err, 'Missing required arguments: ids') ?: "exit $code: $out $err";
});

$before = relationsTo([$typo->id, $manchester->id]);

check('merging into something that is not a tag exits 65 and changes nothing', function() use ($typo, $before, $manchester) {
    $entryId = (int)Entry::find()->status(null)->site('*')->unique()->select(['elements.id'])->scalar();
    [$code, , $err] = craftCmd("tidytags/merge --into=$entryId {$typo->id} --apply");

    return $code === 65 && str_contains($err, 'is not a tag') && str_contains($err, 'Nothing was merged')
        && relationsTo([$typo->id, $manchester->id]) === $before && tagExists($typo->id) ?: "exit $code: $err";
});

check('a tag from another group exits 65 and changes nothing', function() use ($typo, $foreign, $manchester, $before) {
    [$code, , $err] = craftCmd("tidytags/merge --into={$manchester->id} {$typo->id},{$foreign->id} --apply");

    return $code === 65 && str_contains($err, 'different tag group')
        && tagExists($typo->id) && tagExists($foreign->id)
        && relationsTo([$typo->id, $manchester->id]) === $before ?: "exit $code: $err";
});

check('the default run is a dry run: reports the move and the dropped duplicate, changes nothing', function() use ($typo, $manchester, $before) {
    [$code, $out] = craftCmd("tidytags/merge --into={$manchester->id} {$typo->id}");

    return $code === 0 && str_contains($out, 'Dry run') && str_contains($out, 'would be deleted')
        && str_contains($out, '1 relation(s) would be moved and 1 duplicate relation(s) dropped')
        && tagExists($typo->id) && relationsTo([$typo->id, $manchester->id]) === $before ?: "exit $code: $out";
});

check('--format=json reports the plan, flagged as a dry run', function() use ($typo, $manchester) {
    [$code, $out] = craftCmd("tidytags/merge --into={$manchester->id} {$typo->id} --format=json");
    $json = json_decode($out, true);

    return $code === 0 && $json['dryRun'] === true && $json['moved'] === 1 && $json['deduped'] === 1
        && $json['target']['id'] === $manchester->id
        && $json['sources'][0]['id'] === $typo->id && $json['sources'][0]['relations'] === 2
        ?: "exit $code: $out";
});

check('--apply --dry-run still changes nothing', function() use ($typo, $manchester, $before) {
    [$code, $out] = craftCmd("tidytags/merge --into={$manchester->id} {$typo->id} --apply --dry-run");

    return $code === 0 && str_contains($out, 'Dry run') && tagExists($typo->id)
        && relationsTo([$typo->id, $manchester->id]) === $before ?: "exit $code: $out";
});

check('a merge that fails rolls back and exits 1', function() use ($typo, $manchester, $before) {
    $handler = function(DeleteElementEvent $e) {
        throw new RuntimeException('Tidy Tags check: simulated delete failure');
    };
    Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, $handler);
    try {
        $c = new MergeController('merge', tt());
        $c->into = $manchester->id;
        $c->apply = true;
        $code = $c->actionIndex((string)$typo->id);
    } finally {
        Event::off(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, $handler);
    }

    return $code === 1 && tagExists($typo->id) && relationsTo([$typo->id, $manchester->id]) === $before
        ?: "exit $code";
});

check('--apply merges: relations move, the duplicate row is dropped, the source tag is gone', function() use ($typo, $manchester, $owner1, $owner2) {
    [$code, $out] = craftCmd("tidytags/merge --into={$manchester->id} {$typo->id} --apply");
    $rels = relationsTo([$typo->id, $manchester->id]);
    $owners = array_column($rels, 'sourceId');
    sort($owners);

    return $code === 0 && str_contains($out, 'Merged 1 tag(s)')
        && !tagExists($typo->id)
        && $owners === [$owner1->id, $owner2->id]
        && array_unique(array_column($rels, 'targetId')) === [$manchester->id]
        ?: "exit $code: $out " . json_encode($rels);
});

// -----------------------------------------------------------------------------------------------
section('tidytags/unused');

$expectUnused = [$bayern->id, $arsenal->id, $arsenalWomen->id, $formula->id, $owner1->id, $owner2->id];
sort($expectUnused);

check('--format=json lists exactly the tags nothing relates to', function() use ($src, $expectUnused) {
    [$code, $out] = craftCmd("tidytags/unused $src --format=json");
    $json = json_decode($out, true);
    $ids = array_column($json['items'] ?? [], 'id');
    sort($ids);

    return $code === 0 && $ids === $expectUnused && $json['total'] === count($expectUnused) ?: "exit $code: $out";
});

check('--format=csv neutralises a formula-looking title', function() use ($src, $formula) {
    [$code, $out] = craftCmd("tidytags/unused $src --format=csv");

    return $code === 0 && str_starts_with($out, "id,title,source,sourceType\n")
        && str_contains($out, "{$formula->id},\"'=HYPERLINK(")
        && !preg_match('/^\d+,=/m', $out) ?: "exit $code: $out";
});

check('--apply without --delete is a usage error (64)', function() use ($src) {
    [$code] = craftCmd("tidytags/unused $src --apply");

    return $code === 64 ?: "exit $code";
});

check('--delete alone is a dry run: lists and deletes nothing', function() use ($src, $expectUnused) {
    [$code, $out] = craftCmd("tidytags/unused $src --delete");

    return $code === 0 && str_contains($out, count($expectUnused) . ' unused tag(s) would be deleted')
        && str_contains($out, 'Dry run')
        && count(array_filter($expectUnused, 'tagExists')) === count($expectUnused) ?: "exit $code: $out";
});

check('--delete --apply --dry-run deletes nothing', function() use ($src, $expectUnused) {
    [$code, $out] = craftCmd("tidytags/unused $src --delete --apply --dry-run");

    return $code === 0 && str_contains($out, 'Dry run')
        && count(array_filter($expectUnused, 'tagExists')) === count($expectUnused) ?: "exit $code: $out";
});

check('deleteUnusedTags() keeps a tag that gained a relation after it was listed', function() use ($group, $manchester) {
    $late = makeTag($group, 'Late Arrival');
    relate($manchester->id, $late->id);
    $result = tt()->tags->deleteUnusedTags([$late->id]);

    return $result['skipped'] === [$late->id] && $result['deleted'] === [] && tagExists($late->id);
});

check('--delete --apply prunes the unused tags and nothing else', function() use ($src, $expectUnused, $manchester, $bayernFc, $foreign) {
    [$code, $out] = craftCmd("tidytags/unused $src --delete --apply");

    return $code === 0 && str_contains($out, count($expectUnused) . ' unused tag(s) deleted')
        && array_filter($expectUnused, 'tagExists') === []
        && tagExists($manchester->id) && tagExists($bayernFc->id)
        && tagExists($foreign->id) ?: "exit $code: $out";
});

finish();
