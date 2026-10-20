<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\elements\actions\SetStatus;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\queue\jobs\PropagateElements;
use craft\queue\jobs\ResaveElements;
use craft\services\UserPermissions;
use DateTime;
use ReflectionMethod;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\services\Events;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use Yii;
use yii\log\Logger;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * An element's History (task 4.1): Lite offers no History and refuses its addresses whoever asks
 * (TS-9 steps 1-2, BR-33); on Pro the menu item appears for someone allowed it and costs the edit
 * screen nothing (BR-37); and the History lists the element's rows, its nested elements' rows
 * beneath their publish, and the batches that listed it, grouped by request and paged by key
 * without splitting a request (TS-9 step 3, BR-31).
 *
 * Rows are inserted inside the harness's transaction, as in the log screen's tests; TS-9 step 3
 * records a real save and writes the recorder's rows the same way.
 */
class HistoryTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    private TestSite $site;

    private Auditor $plugin;

    private Events $events;

    private User $admin;

    /**
     * Holds History and may view the `pages` section.
     */
    private User $editor;

    /**
     * Holds History, but may not view the `pages` section.
     */
    private User $outsider;

    private string $controllerNamespace;

    private string $edition;

    protected function _before(): void
    {
        $this->site = TestSite::build();
        $this->plugin = Auditor::getInstance();
        $this->events = $this->plugin->getEvents();
        $this->edition = $this->plugin->edition;
        $this->plugin->edition = Auditor::EDITION_PRO;

        // The harness is a console app, so Craft pointed the plugin at its console controllers.
        $this->controllerNamespace = $this->plugin->controllerNamespace;
        $this->plugin->controllerNamespace = 'webdna\\auditor\\controllers';

        $this->admin = $this->site->user('admin', true);
        $this->editor = $this->site->user('editor');
        $this->outsider = $this->site->user('outsider');
        // Test entries have no author, so viewing them is viewing a peer's.
        $this->grant($this->editor, ['accessCp', Auditor::PERMISSION_VIEW_HISTORY, 'viewEntries:' . $this->site->pages->uid, 'viewPeerEntries:' . $this->site->pages->uid]);
        $this->grant($this->outsider, ['accessCp', Auditor::PERMISSION_VIEW_HISTORY]);

        Craft::$app->getDb()->createCommand()->delete(Table::EVENTS)->execute();
    }

    protected function _after(): void
    {
        $this->plugin->edition = $this->edition;
        $this->plugin->controllerNamespace = $this->controllerNamespace;
        $this->plugin->set('recorder', Recorder::class);
        Craft::$app->getUser()->setIdentity(null);
        TestSite::removeFiles();
    }

    /**
     * TS-9 steps 1-2 (BR-33): on Lite no one is offered History, an admin included, and each of
     * its addresses answers 403 — the edition is checked before anything else.
     */
    public function testLiteOffersNoHistoryAndRefusesItsAddresses(): void
    {
        $entry = $this->site->entry('Summer menu');
        $id = $this->insert(['elementId' => (int)$entry->id]);
        $this->plugin->edition = Auditor::EDITION_LITE;

        $this->assertNull($this->plugin->historyMenuItem($entry, $this->admin));
        $this->assertFalse($this->as($this->admin, fn() => $this->hasMenuItem($entry)), 'No History item in the action menu');

        foreach ($this->routes($entry, $id) as $route => $params) {
            $this->assertRefused($this->admin, $route, $params, "$route answered on Lite");
        }

        $this->assertNotContains(Auditor::PERMISSION_VIEW_HISTORY, $this->ourPermissions(), 'Not offered for granting on Lite');
    }

    /**
     * BR-28, BR-31: on Pro, History is offered to and answers someone who holds the permission and
     * can view the element; it refuses someone without the permission, and someone who cannot view
     * the element, at every address.
     */
    public function testProOffersHistoryToThoseAllowedIt(): void
    {
        $entry = $this->site->entry('Summer menu');
        $id = $this->insert(['elementId' => (int)$entry->id]);
        $stranger = $this->site->user('stranger');
        $this->grant($stranger, ['accessCp', 'viewEntries:' . $this->site->pages->uid, 'viewPeerEntries:' . $this->site->pages->uid]);

        $this->assertContains(Auditor::PERMISSION_VIEW_HISTORY, $this->ourPermissions());
        $this->assertTrue($this->as($this->admin, fn() => $this->hasMenuItem($entry)), 'The History item for an admin');
        $this->assertTrue($this->as($this->editor, fn() => $this->hasMenuItem($entry)), 'The History item for the permission');

        $this->assertNull($this->plugin->historyMenuItem($entry, $stranger), 'No item without the permission');

        foreach ($this->routes($entry, $id) as $route => $params) {
            $this->assertRefused($stranger, $route, $params, "$route answered without the permission");
            $this->assertRefused($this->outsider, $route, $params, "$route answered someone who cannot view the entry");
            $this->assertNotEmpty($this->as($this->editor, fn() => Craft::$app->runAction($route, $params))->data);
        }
    }

    /**
     * BR-37: the menu item carries only ids and reads nothing — building it runs no query — and it
     * names the canonical entry when the edit screen holds a provisional draft. An unpublished
     * draft has no History to offer.
     */
    public function testTheMenuItemCostsTheEditScreenNothing(): void
    {
        $entry = $this->site->entry('Summer menu');
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$this->admin->id, null, null, [], true);

        $logger = Yii::getLogger();
        Yii::setLogger(new Logger());
        try {
            $item = $this->plugin->historyMenuItem($draft, $this->admin);
            $queries = array_filter(Yii::getLogger()->messages, fn($message) => str_starts_with((string)$message[2], 'yii\db\Command::'));
        } finally {
            Yii::setLogger($logger);
        }

        $this->assertSame([], array_values($queries), 'No query');
        $this->assertNotNull($item);
        $this->assertSame(Auditor::HISTORY_MENU_ITEM, $item['id']);
        $this->assertSame((int)$entry->id, $item['attributes']['data']['element-id'], 'The canonical entry, not the draft');
        $this->assertTrue($item['attributes']['data']['auditor-history']);

        $unpublished = new Entry(['sectionId' => $this->site->pages->id, 'typeId' => $this->site->page->id, 'title' => 'New']);
        Craft::$app->getDrafts()->saveElementAsDraft($unpublished, (int)$this->admin->id);
        $this->assertNull($this->plugin->historyMenuItem($unpublished, $this->admin), 'An unpublished draft has no History');
    }

    /**
     * TS-9 step 3 (BR-31, BR-12): one publish that changes an entry's title and a Matrix block is
     * one group in History — the entry's row, then the block's beneath it.
     */
    public function testOnePublishIsOneRowWithItsBlockChangeBeneath(): void
    {
        $entry = $this->site->entry('Before', ['blocks' => [
            'new1' => ['type' => 'block', 'title' => 'Block', 'fields' => ['blockText' => 'One']],
        ]]);
        $block = Entry::find()->ownerId($entry->id)->fieldId($this->site->fields['blocks']->id)->one();
        $this->assertInstanceOf(Entry::class, $block);

        $db = $this->secondConnection();
        $recorder = new Recorder(['db' => $db]);
        $this->plugin->set('recorder', $recorder);
        try {
            $this->asWebRequest(function() use ($entry, $block) {
                Craft::$app->getUser()->setIdentity($this->editor);
                $entry->title = 'After';
                $entry->setFieldValue('blocks', [
                    $block->id => ['type' => 'block', 'title' => 'Block', 'fields' => ['blockText' => 'Two']],
                ]);
                $this->site->save($entry);
                Craft::$app->getUser()->setIdentity(null);
            }, cp: true);
            $rows = $recorder->getReady();
        } finally {
            $db->close();
        }
        $this->assertCount(2, $rows, 'The entry and its block');
        // What the recorder would have written, on the connection the History reads.
        (new ReflectionMethod(Recorder::class, 'insertRows'))->invoke($recorder, Craft::$app->getDb(), $rows);

        $page = $this->events->history((int)$entry->id);
        $this->assertCount(1, $page['groups'], 'One publish, one group');
        [$head, $nested] = $page['groups'][0];
        $this->assertSame((int)$entry->id, $head->elementId);
        $this->assertSame('After', $head->data['title']['new'] ?? null);
        $this->assertSame((int)$block->id, $nested->elementId);
        $this->assertSame((int)$entry->id, $nested->ownerId);
        $this->assertSame('Two', $nested->data['blockText']['new'] ?? null);

        $html = $this->panel($this->editor, $entry);
        $this->assertSame(1, substr_count($html, '<tbody data-auditor-request='));
        $this->assertMatchesRegularExpression(
            '~<tr data-auditor-event="' . $head->id . '">.*<tr data-auditor-event="' . $nested->id . '" class="auditor-nested">~s',
            $html,
            'The block\'s row beneath the entry\'s',
        );
        $this->assertStringContainsString('data-auditor-view="auditor/history/view"', $html);
    }

    /**
     * A request that published the element twice (a script, found on the craft5 sandbox) reads
     * as two publishes, each with its own block rows beneath it; a block saved on its own after
     * both stays where it fell.
     */
    public function testEachBlockRowSitsBeneathItsOwnPublish(): void
    {
        $entry = (int)$this->site->entry('Summer menu')->id;
        $block = (int)$this->site->entry('Block')->id;
        $row = fn(bool $nested) => $this->insert(['elementId' => $nested ? $block : $entry, 'ownerId' => $nested ? $entry : null, 'requestId' => 'req-script']);

        $firstBlock = $row(true);
        $firstPublish = $row(false);
        $secondBlock = $row(true);
        $secondPublish = $row(false);
        $alone = $row(true);

        $page = $this->events->history($entry);
        $this->assertCount(1, $page['groups']);
        $this->assertSame(
            [$alone, $secondPublish, $secondBlock, $firstPublish, $firstBlock],
            array_map(fn(Event $event) => $event->id, $page['groups'][0]),
        );
    }

    /**
     * BR-31, BR-15: a batch that listed the element reads as the element's part in it; another
     * element's rows, and a sign-in whose subject has the same id, are not its History.
     */
    public function testHistoryListsTheBatchesThatListedTheElement(): void
    {
        $entry = $this->site->entry('Summer menu');
        $other = $this->site->entry('Winter menu');
        $mine = $this->insert(['elementId' => (int)$entry->id]);
        $this->insert(['elementId' => (int)$other->id]);
        $this->insert(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN, 'elementId' => (int)$entry->id]);
        $batch = $this->insert([
            'event' => ElementWatcher::EVENT_BULK,
            'elementLabel' => null,
            'actorLabel' => 'sam@example.com',
            'data' => ['operation' => 'resave', 'count' => 1240, 'elementType' => Entry::class],
        ]);
        Craft::$app->getDb()->createCommand()->batchInsert(Table::EVENT_ELEMENTS, ['eventId', 'elementId', 'siteId'], [
            [$batch, (int)$entry->id, 1],
            [$batch, (int)$other->id, 1],
        ])->execute();

        $page = $this->events->history((int)$entry->id);
        $this->assertSame([$batch, $mine], array_map(fn(Event $event) => $event->id, $page['events']));

        $html = $this->panel($this->editor, $entry);
        $this->assertStringContainsString('Included in a re-save of 1,240 entries', $html);
        $this->assertStringContainsString('sam@example.com', $html);

        // A job has no display name, so its class reads in words — never as a class (craft5).
        $summary = fn(string $operation, int $count) => $this->events->batchSummary(new Event([
            'family' => Event::FAMILY_ELEMENT,
            'event' => ElementWatcher::EVENT_BULK,
            'data' => ['operation' => $operation, 'count' => $count, 'elementType' => Entry::class],
        ]));
        $this->assertSame('Included in a batch of 4 entries (Propagate elements)', $summary(PropagateElements::class, 4));
        $this->assertSame('Included in a batch of 1 entry (Set status)', $summary(SetStatus::class, 1));
        // Craft's resave job was recorded by its class before 9.6; it reads as the command's does (#6).
        $this->assertSame('Included in a re-save of 4 entries', $summary(ResaveElements::class, 4));
        $this->assertSame('Included in a re-save of 4 entries', $summary('resave', 4));
    }

    /**
     * BR-31: pages are keyed by id and never counted, and a request that straddles a page's end is
     * read whole onto that page, so no publish is split.
     */
    public function testPagesNeverSplitARequest(): void
    {
        $entry = $this->site->entry('Summer menu');
        $block = (int)$this->site->entry('Block')->id;
        $older = [];
        for ($i = 0; $i < 10; $i++) {
            $older[] = $this->insert(['elementId' => (int)$entry->id]);
        }
        $straddling = [
            $this->insert(['elementId' => $block, 'ownerId' => (int)$entry->id, 'requestId' => 'req-publish']),
            $this->insert(['elementId' => $block, 'ownerId' => (int)$entry->id, 'requestId' => 'req-publish']),
            $this->insert(['elementId' => (int)$entry->id, 'requestId' => 'req-publish']),
        ];
        $newer = [];
        for ($i = 0; $i < 49; $i++) {
            $newer[] = $this->insert(['elementId' => (int)$entry->id]);
        }

        $logger = Yii::getLogger();
        Yii::setLogger(new Logger());
        try {
            $first = $this->events->history((int)$entry->id);
            $second = $this->events->history((int)$entry->id, $first['after']);
            $queries = array_filter(Yii::getLogger()->messages, fn($message) => str_starts_with((string)$message[2], 'yii\db\Command::query'));
        } finally {
            Yii::setLogger($logger);
        }

        $this->assertCount(50, $first['groups']);
        $this->assertSame([$straddling[2], $straddling[1], $straddling[0]], array_map(fn(Event $event) => $event->id, end($first['groups'])), 'The whole request, the entry\'s row first');
        $this->assertSame($straddling[0], $first['after']);
        $this->assertSame(array_reverse($older), array_map(fn(Event $event) => $event->id, $second['events']));
        $this->assertNull($second['after']);
        $this->assertSame(array_reverse($newer), array_map(fn(Event $event) => $event->id, array_slice($first['events'], 0, 49)));

        foreach ($queries as $message) {
            $this->assertStringNotContainsStringIgnoringCase('count(', (string)$message[0]);
            $this->assertStringNotContainsStringIgnoringCase('offset', (string)$message[0]);
        }

        $json = $this->as($this->editor, fn() => Craft::$app->runAction('auditor/history/page', ['after' => $first['after']] + $this->params($entry)))->data;
        $this->assertSame(10, substr_count($json['html'], 'data-auditor-event='));
        $this->assertNull($json['after']);
    }

    /**
     * A row's detail from History: only a row of this element's History, with the address hidden
     * (BR-27), and the link to the rest of its request only for someone who can open the log.
     */
    public function testARowsDetailIsOnlyForThatElementsHistory(): void
    {
        $entry = $this->site->entry('Summer menu');
        $other = $this->site->entry('Winter menu');
        $mine = $this->insert(['elementId' => (int)$entry->id, 'ip' => '203.0.113.9', 'data' => ['title' => ['label' => 'Title', 'old' => 'A', 'new' => '<b>B</b>']]]);
        $theirs = $this->insert(['elementId' => (int)$other->id]);

        $detail = (string)$this->as($this->editor, fn() => Craft::$app->runAction('auditor/history/view', ['id' => $mine] + $this->params($entry)))->data;
        $this->assertStringContainsString('data-auditor-field="title"', $detail);
        $this->assertStringContainsString('&lt;b&gt;B&lt;/b&gt;', $detail);
        $this->assertStringContainsString('data-auditor-ip>—</dd>', $detail);
        $this->assertStringNotContainsString('Everything recorded in this request', $detail, 'The log is not theirs to open');

        $this->assertStringContainsString('Everything recorded in this request', (string)$this->as($this->admin, fn() => Craft::$app->runAction('auditor/history/view', ['id' => $mine] + $this->params($entry)))->data);

        try {
            $this->as($this->editor, fn() => Craft::$app->runAction('auditor/history/view', ['id' => $theirs] + $this->params($entry)));
            $this->fail('Another element\'s row was shown');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * §3's first run: an element with no rows reads as having none since install.
     */
    public function testAnElementWithNoRowsSaysSo(): void
    {
        $html = $this->panel($this->editor, $this->site->entry('Summer menu'));
        $this->assertStringContainsString('data-auditor-empty', $html);
        $this->assertStringContainsString('No changes recorded since Auditor was installed.', $html);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routes(Entry $entry, int $id): array
    {
        return [
            'auditor/history/index' => $this->params($entry),
            'auditor/history/page' => ['after' => $id + 1] + $this->params($entry),
            'auditor/history/view' => ['id' => $id] + $this->params($entry),
        ];
    }

    /**
     * @return array{elementId: int, siteId: int}
     */
    private function params(Entry $entry): array
    {
        $params = ['elementId' => (int)$entry->id, 'siteId' => (int)$entry->siteId];
        // The controller reads the element from the query string, as the slideout sends it.
        Craft::$app->getRequest()->setQueryParams($params);

        return $params;
    }

    private function panel(User $user, Entry $entry): string
    {
        return (string)$this->as($user, fn() => Craft::$app->runAction('auditor/history/index', $this->params($entry)))->data;
    }

    private function hasMenuItem(Entry $entry): bool
    {
        foreach ($entry->getActionMenuItems() as $item) {
            if (($item['id'] ?? null) === Auditor::HISTORY_MENU_ITEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertRefused(User $user, string $route, array $params, string $message): void
    {
        try {
            $this->as($user, fn() => Craft::$app->runAction($route, $params));
            $this->fail($message);
        } catch (ForbiddenHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * The permissions offered in the plugin's group, read from a fresh service: Craft's keeps the
     * list it built first.
     *
     * @return string[]
     */
    private function ourPermissions(): array
    {
        $permissions = (new UserPermissions())->getAllPermissions();
        $ours = array_filter($permissions, fn($group) => $group['heading'] === 'Auditor');

        return array_merge(...array_map(fn($group) => array_keys($group['permissions']), array_values($ours)));
    }

    private function as(User $user, Closure $callback): mixed
    {
        return $this->asWebRequest(function() use ($user, $callback) {
            Craft::$app->getUser()->setIdentity($user);
            try {
                return $callback();
            } finally {
                Craft::$app->getUser()->setIdentity(null);
            }
        }, cp: true);
    }

    /**
     * @param string[] $permissions
     */
    private function grant(User $user, array $permissions): void
    {
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, $permissions);
    }

    /**
     * A row, inserted as the recorder would have written it.
     *
     * @param array<string, mixed> $attributes
     */
    private function insert(array $attributes): int
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert(Table::EVENTS, $attributes + [
            'family' => Event::FAMILY_ELEMENT,
            'event' => ElementWatcher::EVENT_SAVED,
            'elementType' => Entry::class,
            'elementLabel' => 'Entry',
            'actorLabel' => Event::SYSTEM,
            'context' => 'cp',
            'requestId' => 'req-' . uniqid('', true),
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
