<?php

namespace justinholtweb\tidytagstests\unit\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\tidytags\controllers\TagsController;
use justinholtweb\tidytags\Plugin;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Exercises the action methods directly with a prepared request, so the JSON
 * branches are covered without standing up CP routing or CSRF.
 *
 * The permission gates live in beforeAction() and are covered separately via
 * runAction(), which is the only path that triggers them. Every test starts
 * logged in as an admin, since merge and swap check the current user's rights
 * over the elements they touch.
 */
class TagsControllerTest extends PluginTestCase
{
    protected function _before(): void
    {
        parent::_before();
        Fixtures::login(Fixtures::createUser('admin', true));
    }

    protected function _after(): void
    {
        Fixtures::login(null);
        parent::_after();
    }

    private function controller(): TagsController
    {
        return new TagsController('tags', Plugin::$plugin);
    }

    /**
     * @param array<string, mixed> $bodyParams
     */
    private function preparePostRequest(array $bodyParams): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = Craft::$app->getRequest();
        $this->acceptJson();
        $request->setBodyParams($bodyParams);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function prepareGetRequest(array $queryParams): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->acceptJson();
        Craft::$app->getRequest()->setQueryParams($queryParams);
    }

    /**
     * Sets the accepted content types on the Request itself. Poking
     * `$_SERVER['HTTP_ACCEPT']` is unreliable here — headers are parsed and
     * cached the first time they're read, which may already have happened
     * during app bootstrap.
     */
    private function acceptJson(): void
    {
        $request = Craft::$app->getRequest();
        $request->getHeaders()->set('Accept', 'application/json');
        $request->setAcceptableContentTypes(['application/json' => ['q' => 1]]);
    }

    public function testRenameReturnsJsonSuccess(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest(['tagId' => $tag->id, 'title' => 'Feline']);

        $response = $this->controller()->actionRename();

        self::assertSame(['success' => true], $response->data);
        self::assertSame('Feline', Tag::find()->id($tag->id)->status(null)->one()->title);
    }

    public function testRenameReportsFailureForUnknownTag(): void
    {
        $this->preparePostRequest(['tagId' => 999999, 'title' => 'Feline']);

        self::assertSame(['success' => false], $this->controller()->actionRename()->data);
    }

    public function testRenameRequiresTagIdAndTitle(): void
    {
        $this->preparePostRequest(['title' => 'Feline']);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionRename();
    }

    public function testRenameScopesToASingleSiteWhenGiven(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest([
            'tagId' => $tag->id,
            'title' => 'Chat',
            'siteId' => $second->id,
        ]);

        self::assertSame(['success' => true], $this->controller()->actionRename()->data);

        self::assertSame(
            'Chat',
            Tag::find()->id($tag->id)->siteId($second->id)->status(null)->one()->title,
        );
        self::assertSame(
            'Cat',
            Tag::find()->id($tag->id)->siteId(Craft::$app->getSites()->getPrimarySite()->id)->status(null)->one()->title,
        );
    }

    /**
     * A blank siteId (what an "all sites" <select> posts) must mean all sites,
     * not site 0.
     */
    public function testRenameTreatsBlankSiteIdAsAllSites(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest(['tagId' => $tag->id, 'title' => 'Feline', 'siteId' => '']);

        self::assertSame(['success' => true], $this->controller()->actionRename()->data);
        self::assertSame(
            'Feline',
            Tag::find()->id($tag->id)->siteId($second->id)->status(null)->one()->title,
        );
    }

    public function testDeleteAcceptsAnArrayOfIds(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $cat = Fixtures::createTag($group, 'Cat');
        $dog = Fixtures::createTag($group, 'Dog');

        $this->preparePostRequest(['tagIds' => [$cat->id, $dog->id]]);

        self::assertSame(
            ['success' => true, 'deleted' => 2],
            $this->controller()->actionDelete()->data,
        );
    }

    public function testDeleteAcceptsASingleId(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest(['tagId' => $tag->id]);

        self::assertSame(
            ['success' => true, 'deleted' => 1],
            $this->controller()->actionDelete()->data,
        );
    }

    public function testDeleteReportsZeroForUnknownIds(): void
    {
        $this->preparePostRequest(['tagIds' => [999999]]);

        self::assertSame(
            ['success' => false, 'deleted' => 0],
            $this->controller()->actionDelete()->data,
        );
    }

    public function testDeleteRequiresAnId(): void
    {
        $this->preparePostRequest([]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionDelete();
    }

    public function testMergeReturnsJsonSuccess(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');
        $source = Fixtures::createTag($group, 'Cats');

        $this->preparePostRequest(['sourceIds' => [$source->id], 'targetId' => $target->id]);

        self::assertSame(['success' => true], $this->controller()->actionMerge()->data);
        self::assertNull(Tag::find()->id($source->id)->status(null)->one());
    }

    public function testMergeRejectsNonArraySourceIds(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest(['sourceIds' => 'nope', 'targetId' => $target->id]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionMerge();
    }

    public function testSwapRepointsRelationsWithoutDeleting(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');
        $target = Fixtures::createTag($group, 'Feline');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Article');
        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $tag->id);

        $this->preparePostRequest(['sourceIds' => [$tag->id], 'targetId' => $target->id]);

        self::assertSame(['success' => true], $this->controller()->actionSwap()->data);

        $targetIds = (new Query())
            ->select(['targetId'])
            ->from(['{{%relations}}'])
            ->where(['sourceId' => $entry->id])
            ->column();

        self::assertSame([$target->id], array_map('intval', $targetIds));
        self::assertNotNull(Tag::find()->id($tag->id)->status(null)->one());
    }

    public function testSwapCoercesStringIdsFromPostedForms(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');
        $target = Fixtures::createTag($group, 'Feline');

        $this->preparePostRequest([
            'sourceIds' => [(string)$tag->id],
            'targetId' => (string)$target->id,
        ]);

        self::assertSame(['success' => true], $this->controller()->actionSwap()->data);
    }

    public function testSwapRejectsNonArraySourceIds(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');

        $this->preparePostRequest(['sourceIds' => '1', 'targetId' => $target->id]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->actionSwap();
    }

    public function testCheckDuplicateReturnsMatches(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchester');

        $this->prepareGetRequest(['title' => 'Manchestor', 'groupId' => $group->id]);

        $data = $this->controller()->actionCheckDuplicate()->data;

        self::assertTrue($data['success']);
        self::assertCount(1, $data['matches']);
        self::assertSame('Manchester', $data['matches'][0]['title']);
    }

    public function testCheckDuplicateReturnsEmptyMatchesForBlankTitle(): void
    {
        $this->prepareGetRequest(['title' => '', 'groupId' => '']);

        $data = $this->controller()->actionCheckDuplicate()->data;

        self::assertTrue($data['success']);
        self::assertSame([], $data['matches']);
    }

    public function testUsagesReturnsRelations(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Article');
        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $tag->id);

        $this->prepareGetRequest(['elementId' => $tag->id]);

        $data = $this->controller()->actionUsages()->data;

        self::assertTrue($data['success']);
        self::assertCount(1, $data['usages']);
        self::assertSame('Article', $data['usages'][0]['title']);
        self::assertFalse($data['hasMore']);
    }

    public function testUsagesFlagsWhenTheListIsCutOff(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');
        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);

        for ($i = 0; $i <= TagsController::USAGES_LIMIT; $i++) {
            $entry = Fixtures::createEntry($section, "Article $i");
            Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $tag->id);
        }

        $this->prepareGetRequest(['elementId' => $tag->id]);
        $data = $this->controller()->actionUsages()->data;

        self::assertCount(TagsController::USAGES_LIMIT, $data['usages']);
        self::assertTrue($data['hasMore']);
    }

    public function testUsagesRejectsMissingOrInvalidElementId(): void
    {
        $this->prepareGetRequest([]);

        self::assertSame(
            ['success' => false, 'usages' => []],
            $this->controller()->actionUsages()->data,
        );
    }

    /**
     * beforeAction() gates every action on accessPlugin-tidytags, so a guest
     * must never reach one.
     */
    public function testActionsRequireThePluginPermission(): void
    {
        Fixtures::login(null);
        $this->prepareGetRequest(['elementId' => 1]);

        $this->expectException(ForbiddenHttpException::class);
        $this->controller()->runAction('usages');
    }

    /**
     * @return array<string, array{0: string, 1: string[]}>
     */
    public static function mutationPermissionProvider(): array
    {
        return [
            'rename with plugin access only' => ['rename', ['accessPlugin-tidytags']],
            'swap with plugin access only' => ['swap', ['accessPlugin-tidytags']],
            'delete with plugin access only' => ['delete', ['accessPlugin-tidytags']],
            'delete with manage only' => ['delete', ['accessPlugin-tidytags', Plugin::PERMISSION_MANAGE_TAGS]],
            'merge with manage only' => ['merge', ['accessPlugin-tidytags', Plugin::PERMISSION_MANAGE_TAGS]],
            'merge with delete only' => ['merge', ['accessPlugin-tidytags', Plugin::PERMISSION_DELETE_TAGS]],
        ];
    }

    /**
     * Plugin access lets someone browse and scan, but every mutation needs its
     * own permission.
     *
     * @dataProvider mutationPermissionProvider
     * @param string[] $permissions
     */
    public function testMutationsRequireTheirPermission(string $action, array $permissions): void
    {
        $user = Fixtures::createUser('editor', false, $permissions);
        self::assertTrue($user->can('accessPlugin-tidytags'), 'The user must clear the plugin-access gate.');
        Fixtures::login($user);
        $this->preparePostRequest(['tagId' => 1, 'tagIds' => [1], 'sourceIds' => [1], 'targetId' => 2, 'title' => 'x']);

        $this->expectException(ForbiddenHttpException::class);
        $this->controller()->runAction($action);
    }

    /**
     * Builds a tag related from an entry in a section, and a user with every
     * Tidy Tags permission but no rights in that section.
     *
     * @return array{0: \craft\elements\Tag, 1: \craft\elements\Tag, 2: \craft\elements\Entry, 3: User}
     */
    private function tagUsedInASectionTheUserCannotEdit(): array
    {
        $group = Fixtures::createTagGroup('animals');
        $source = Fixtures::createTag($group, 'Cats');
        $target = Fixtures::createTag($group, 'Cat');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Article');
        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $source->id);

        $user = Fixtures::createUser('tagger', false, [
            'accessPlugin-tidytags',
            Plugin::PERMISSION_MANAGE_TAGS,
            Plugin::PERMISSION_DELETE_TAGS,
        ]);

        return [$source, $target, $entry, $user];
    }

    /**
     * Merging re-points the relations an entry holds, which edits that entry,
     * so it needs save rights over the entry as well as the tag permissions.
     */
    public function testMergeIsForbiddenWhenTheUserCannotEditAnAffectedEntry(): void
    {
        [$source, $target, $entry, $user] = $this->tagUsedInASectionTheUserCannotEdit();
        Fixtures::login($user);

        $this->preparePostRequest(['sourceIds' => [$source->id], 'targetId' => $target->id]);

        try {
            $this->controller()->actionMerge();
            self::fail('Expected a 403.');
        } catch (ForbiddenHttpException) {
        }

        self::assertNotNull(Tag::find()->id($source->id)->status(null)->one());
        $targetIds = (new Query())->select(['targetId'])->from(['{{%relations}}'])->where(['sourceId' => $entry->id])->column();
        self::assertSame([$source->id], array_map('intval', $targetIds));
    }

    public function testSwapIsForbiddenWhenTheUserCannotEditAnAffectedEntry(): void
    {
        [$source, $target, , $user] = $this->tagUsedInASectionTheUserCannotEdit();
        Fixtures::login($user);

        $this->preparePostRequest(['sourceIds' => [$source->id], 'targetId' => $target->id]);

        $this->expectException(ForbiddenHttpException::class);
        $this->controller()->actionSwap();
    }

    /**
     * A tag nobody uses has no owners to check, so tag permissions are enough.
     */
    public function testMergeOfUnusedTagsNeedsOnlyTagPermissions(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $source = Fixtures::createTag($group, 'Cats');
        $target = Fixtures::createTag($group, 'Cat');

        Fixtures::login(Fixtures::createUser('tagger', false, [
            'accessPlugin-tidytags',
            Plugin::PERMISSION_MANAGE_TAGS,
            Plugin::PERMISSION_DELETE_TAGS,
        ]));

        $this->preparePostRequest(['sourceIds' => [$source->id], 'targetId' => $target->id]);

        self::assertSame(['success' => true], $this->controller()->actionMerge()->data);
    }

    /**
     * Swap must not become a general-purpose tool for re-pointing relations
     * between arbitrary entries outside the plugin's configured sources.
     */
    public function testSwapRejectsElementsOutsideTidyTagsSources(): void
    {
        $section = Fixtures::createChannelSection('news');
        $a = Fixtures::createEntry($section, 'First');
        $b = Fixtures::createEntry($section, 'Second');

        $this->preparePostRequest(['sourceIds' => [$a->id], 'targetId' => $b->id]);

        $this->expectException(ForbiddenHttpException::class);
        $this->controller()->actionSwap();
    }

    public function testSwapAllowsEntriesInConfiguredSections(): void
    {
        $section = Fixtures::createChannelSection('teams');
        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);
        $a = Fixtures::createEntry($section, 'Arsenal FC');
        $b = Fixtures::createEntry($section, 'Arsenal');

        $this->preparePostRequest(['sourceIds' => [$a->id], 'targetId' => $b->id]);

        self::assertSame(['success' => true], $this->controller()->actionSwap()->data);
    }

    public function testUsagesHidesElementsTheUserCannotView(): void
    {
        [$source, , , $user] = $this->tagUsedInASectionTheUserCannotEdit();
        Fixtures::login($user);

        $this->prepareGetRequest(['elementId' => $source->id]);

        $data = $this->controller()->actionUsages()->data;

        self::assertTrue($data['success']);
        self::assertSame([], $data['usages']);
    }

    public function testUsagesIgnoresElementsOutsideTidyTagsSources(): void
    {
        $section = Fixtures::createChannelSection('news');
        $entry = Fixtures::createEntry($section, 'Article');

        $this->prepareGetRequest(['elementId' => $entry->id]);

        self::assertSame(
            ['success' => false, 'usages' => []],
            $this->controller()->actionUsages()->data,
        );
    }
}
