<?php
/**
 * Shared fixtures for the Tidy Tags integration checks: two throwaway tag groups
 * (`tidytagsCheck…`), tags in them, and raw relation rows pointing at those tags.
 *
 * Everything is created by setUp() and removed by sweep(), which also runs first so
 * a crashed earlier run can't skew the next one.
 */

use craft\db\Query;
use craft\elements\Tag;
use craft\models\FieldLayout;
use craft\models\TagGroup;
use justinholtweb\tidytags\Plugin;

const TT_GROUP = 'tidytagsCheck';
const TT_OTHER_GROUP = 'tidytagsCheckOther';

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): never
{
    global $passed, $failed;

    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

function tt(): Plugin
{
    return Plugin::$plugin;
}

/**
 * Runs a real console command; returns [exit code, stdout, stderr].
 */
function craftCmd(string $args): array
{
    $proc = proc_open('php craft ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [$code, (string)$out, (string)$err];
}

function saveProjectConfig(): void
{
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->saveModifiedConfigData();
    $projectConfig->writeYamlFiles(true);
}

function sweep(): void
{
    $tags = Craft::$app->getTags();
    $changed = false;

    foreach ([TT_GROUP, TT_OTHER_GROUP] as $handle) {
        $group = $tags->getTagGroupByHandle($handle);
        if ($group === null) {
            continue;
        }
        foreach (Tag::find()->groupId($group->id)->status(null)->trashed(null)->all() as $tag) {
            Craft::$app->getElements()->deleteElement($tag, true);
        }
        $tags->deleteTagGroup($group);
        $changed = true;
    }

    if ($changed) {
        saveProjectConfig();
    }
}

function makeGroup(string $handle): TagGroup
{
    $group = new TagGroup([
        'name' => 'Tidy Tags check ' . $handle,
        'handle' => $handle,
    ]);
    $group->setFieldLayout(new FieldLayout(['type' => Tag::class]));

    if (!Craft::$app->getTags()->saveTagGroup($group)) {
        throw new RuntimeException('Could not save tag group: ' . implode(', ', $group->getFirstErrors()));
    }

    return $group;
}

function makeTag(TagGroup $group, string $title): Tag
{
    $tag = new Tag(['groupId' => $group->id, 'title' => $title]);

    if (!Craft::$app->getElements()->saveElement($tag)) {
        throw new RuntimeException("Could not save tag $title: " . implode(', ', $tag->getFirstErrors()));
    }

    return $tag;
}

/**
 * A field ID for raw relation rows. The rows are data only, so any field will do.
 */
function relationFieldId(): int
{
    return (int)(new Query())->select(['id'])->from(['{{%fields}}'])->orderBy(['id' => SORT_ASC])->scalar();
}

function relate(int $ownerId, int $targetId): void
{
    Craft::$app->getDb()->createCommand()->insert('{{%relations}}', [
        'fieldId' => relationFieldId(),
        'sourceId' => $ownerId,
        'sourceSiteId' => null,
        'targetId' => $targetId,
        'sortOrder' => 1,
    ])->execute();
}

/**
 * @return array<int, array{sourceId: int, targetId: int}>
 */
function relationsTo(array $targetIds): array
{
    return array_map(
        fn($r) => ['sourceId' => (int)$r['sourceId'], 'targetId' => (int)$r['targetId']],
        (new Query())->select(['sourceId', 'targetId'])->from(['{{%relations}}'])
            ->where(['targetId' => $targetIds])->orderBy(['id' => SORT_ASC])->all(),
    );
}

function tagExists(int $id): bool
{
    return Tag::find()->id($id)->status(null)->exists();
}
