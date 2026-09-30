<?php

namespace justinholtweb\tidytagstests\unit\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Tag;
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
 * The permission gate lives in beforeAction() and is covered separately via
 * runAction(), which is the only path that triggers it.
 */
class TagsControllerTest extends PluginTestCase
{
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
        $this->prepareGetRequest(['elementId' => 1]);

        $this->expectException(ForbiddenHttpException::class);
        $this->controller()->runAction('usages');
    }
}
