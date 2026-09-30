<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\UserGroup;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;
use craft\web\twig\variables\Cp;
use DateTime;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Filter;
use webdna\auditor\services\Events;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use Yii;
use yii\log\Logger;
use yii\web\ForbiddenHttpException;

/**
 * The log screen through its controller, as the signed-in people of §7's test data: who may open
 * it (TN-6, TN-7), who sees an address (TS-3 steps 4-5, BR-27), that nothing logged is rendered
 * as markup (TN-3) and a long value is cut short with a control for the rest (TN-4), and that it
 * filters and pages as BR-29 says.
 *
 * Rows are inserted directly inside the harness's transaction: recording them is the watchers'
 * tests' business, and the recorder writes nothing while a transaction is open.
 */
class LogScreenTest extends Unit
{
    use WebRequests;

    private TestSite $site;

    private Events $events;

    private User $admin;

    /**
     * Holds only the log (§7's `viewer`).
     */
    private User $viewer;

    /**
     * Signs in to the control panel, and holds no Auditor permission.
     */
    private User $stranger;

    private string $controllerNamespace;

    protected function _before(): void
    {
        $this->site = TestSite::build();
        $plugin = Auditor::getInstance();
        $this->events = $plugin->getEvents();

        // The harness is a console app, so Craft pointed the plugin at its console controllers.
        $this->controllerNamespace = $plugin->controllerNamespace;
        $plugin->controllerNamespace = 'webdna\\auditor\\controllers';

        $this->admin = $this->site->user('admin', true);
        $this->viewer = $this->site->user('viewer');
        $this->stranger = $this->site->user('stranger');
        $this->grant($this->viewer, ['accessCp', Auditor::PERMISSION_VIEW_LOG]);
        $this->grant($this->stranger, ['accessCp']);

        // Whatever the harness database holds is set aside; the transaction puts it back.
        Craft::$app->getDb()->createCommand()->delete(Table::EVENTS)->execute();
    }

    protected function _after(): void
    {
        Auditor::getInstance()->controllerNamespace = $this->controllerNamespace;
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getRequest()->setQueryParams([]);
        TestSite::removeFiles();
    }

    /**
     * TN-7 and TN-6: without "Access Auditor" the page, its JSON pages and a row's detail are all
     * refused, and the control panel shows no Auditor item. With it, all three answer.
     */
    public function testTheLogIsOnlyForThoseAllowedToSeeIt(): void
    {
        $id = $this->insert(['event' => ElementWatcher::EVENT_SAVED]);

        foreach (['auditor/events/log' => [], 'auditor/events/index' => [], 'auditor/events/view' => ['id' => $id]] as $route => $params) {
            try {
                $this->as($this->stranger, fn() => Craft::$app->runAction($route, $params));
                $this->fail("$route answered someone without the permission");
            } catch (ForbiddenHttpException) {
                $this->addToAssertionCount(1);
            }

            $this->assertInstanceOf(Response::class, $this->as($this->viewer, fn() => Craft::$app->runAction($route, $params)));
        }

        $this->assertFalse($this->as($this->stranger, fn() => $this->hasNavItem()), 'No nav item without the permission');
        $this->assertTrue($this->as($this->viewer, fn() => $this->hasNavItem()), 'The nav item with it');

        // A permission to change a row does not exist to be granted (BR-28).
        $permissions = Craft::$app->getUserPermissions()->getAllPermissions();
        $ours = array_merge(...array_map(fn($group) => array_keys($group['permissions']), array_filter($permissions, fn($group) => $group['heading'] === 'Auditor')));
        $this->assertSame([Auditor::PERMISSION_VIEW_NETWORK], $ours);
    }

    /**
     * TS-3 steps 4-5 (BR-27): a failed sign-in's address and browser read "—" to `viewer`, and
     * show to an admin and to anyone granted the permission.
     */
    public function testTheAddressShowsOnlyToThoseAllowedToSeeIt(): void
    {
        $id = $this->insert([
            'family' => Event::FAMILY_AUTH,
            'event' => AuthWatcher::EVENT_LOGIN_FAILED,
            'elementLabel' => 'member@example.com',
            'data' => ['username' => 'member', 'reason' => 'invalid_credentials'],
            'ip' => '203.0.113.9',
            'userAgent' => 'SomeBrowser/9.9',
        ]);

        $hidden = $this->view($this->viewer, $id);
        $this->assertStringContainsString('data-auditor-ip>—</dd>', $hidden);
        $this->assertStringContainsString('data-auditor-user-agent>—</dd>', $hidden);
        $this->assertStringNotContainsString('203.0.113.9', $hidden);
        $this->assertStringNotContainsString('SomeBrowser', $hidden);
        $this->assertStringContainsString('member', $hidden, 'The attempted username is shown to anyone who can see the log');

        $this->assertStringContainsString('data-auditor-ip>203.0.113.9</dd>', $this->view($this->admin, $id));

        $this->grant($this->viewer, ['accessCp', Auditor::PERMISSION_VIEW_LOG, Auditor::PERMISSION_VIEW_NETWORK]);
        $shown = $this->view($this->viewer, $id);
        $this->assertStringContainsString('data-auditor-ip>203.0.113.9</dd>', $shown);
        $this->assertStringContainsString('SomeBrowser/9.9', $shown);
    }

    /**
     * TN-3 (BR-30): markup in a value, a field label and a subject label is shown as text, in the
     * slideout and in the log's rows.
     */
    public function testLoggedMarkupIsShownAsText(): void
    {
        $id = $this->insert([
            'elementLabel' => '<img src=x onerror=alert(1)>',
            'data' => ['body' => ['label' => 'Body <b>bold</b>', 'old' => '<script>alert(1)</script>', 'new' => 'plain']],
        ]);

        $detail = $this->view($this->admin, $id);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detail);
        $this->assertStringContainsString('Body &lt;b&gt;bold&lt;/b&gt;', $detail);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $detail);
        $this->assertStringNotContainsString('<script>alert', $detail);
        $this->assertStringNotContainsString('<img src=x', $detail);
        $this->assertStringContainsString('data-auditor-field="body"', $detail);

        $rows = $this->page($this->admin, [])['html'];
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $rows);
        $this->assertStringNotContainsString('<img src=x', $rows);
    }

    /**
     * TN-4 (BR-30): a 20,000-character value shows its first 500 characters, then a "Show more"
     * control; the rest is in the page, hidden, and escaped like the start.
     */
    public function testALongValueIsCutShortWithAControlForTheRest(): void
    {
        $long = str_repeat('a', 500) . '<b>' . str_repeat('z', 19497);
        $this->assertSame(20000, strlen($long));
        $id = $this->insert(['data' => ['body' => ['label' => 'Body', 'old' => 'short', 'new' => $long]]]);

        $detail = $this->view($this->admin, $id);
        $this->assertMatchesRegularExpression('~<span>a{500}</span><span class="auditor-ellipsis">…</span><span class="auditor-rest hidden">&lt;b&gt;z{19497}</span>~', $detail);
        $this->assertStringContainsString('data-auditor-more aria-expanded="false"', $detail);
        $this->assertStringContainsString('Show more', $detail);
        $this->assertSame(1, substr_count($detail, 'data-auditor-more'), 'The short value has no control');

        $stored = (new \craft\db\Query())->select('data')->from(Table::EVENTS)->where(['id' => $id])->scalar();
        $this->assertStringContainsString(str_repeat('z', 19497), (string)$stored, 'Stored whole');
    }

    /**
     * BR-29: each filter narrows the log, and the search matches the subject and actor labels
     * but never a logged value.
     */
    public function testEachFilterNarrowsTheLog(): void
    {
        $entry = $this->insert(['elementLabel' => 'Summer menu', 'actorId' => (int)$this->admin->id, 'actorLabel' => 'admin@example.com', 'context' => 'cp', 'siteId' => 1]);
        $login = $this->insert(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN, 'elementType' => User::class, 'elementLabel' => 'viewer@example.com', 'actorLabel' => 'viewer@example.com', 'context' => 'site', 'requestId' => 'req-login']);
        $group = $this->insert(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_GROUP_SAVED, 'elementType' => UserGroup::class, 'elementLabel' => 'Editors', 'context' => 'console', 'data' => ['group' => ['name' => 'Summer staff']]]);
        $old = $this->insert(['elementLabel' => 'Old news', 'context' => 'queue', 'dateCreated' => new DateTime('-40 days')]);

        $this->assertIds([$entry], ['search' => 'summer'], 'The search does not read logged values');
        $this->assertIds([$login], ['search' => 'viewer@']);
        $this->assertIds([$login], ['family' => Event::FAMILY_AUTH]);
        $this->assertIds([$group], ['event' => AuthWatcher::EVENT_GROUP_SAVED]);
        $this->assertIds([$group], ['elementType' => UserGroup::class]);
        $this->assertIds([$entry], ['actor' => [(string)$this->admin->id]]);
        $this->assertIds([$old], ['context' => 'queue']);
        $this->assertIds([$entry], ['siteId' => '1']);
        $this->assertIds([$login], ['request' => 'req-login']);
        $this->assertIds([$old], ['to' => (new DateTime('-30 days'))->format(DATE_ATOM)]);
        $this->assertIds([$group, $login, $entry], ['from' => (new DateTime('-1 day'))->format(DATE_ATOM)]);
        $this->assertIds([$old, $group, $login, $entry], ['family' => 'nonsense', 'context' => 'nowhere'], 'An unknown value is ignored, not searched for');
    }

    /**
     * BR-29: pages are fetched by key — 120 rows are 50, 50 and 20, newest first, with no gap or
     * repeat — and no page counts the table.
     */
    public function testPagesAreFetchedByKeyAndNeverCounted(): void
    {
        $ids = [];
        for ($i = 0; $i < 120; $i++) {
            $ids[] = $this->insert(['elementLabel' => "Entry $i"]);
        }
        $ids = array_reverse($ids);

        $logger = Yii::getLogger();
        Yii::setLogger(new Logger());
        try {
            $first = $this->events->page(new Filter());
            $second = $this->page($this->viewer, ['after' => $first['after']]);
            $third = $this->page($this->viewer, ['after' => $second['after']]);
            $queries = array_filter(Yii::getLogger()->messages, fn($message) => str_starts_with((string)$message[2], 'yii\db\Command::query'));
        } finally {
            Yii::setLogger($logger);
        }

        $this->assertSame(array_slice($ids, 0, 50), array_map(fn(Event $event) => $event->id, $first['events']));
        $this->assertSame($ids[49], $first['after']);
        $this->assertSame(array_slice($ids, 50, 50), $this->rowIds($second['html']));
        $this->assertSame(array_slice($ids, 100), $this->rowIds($third['html']));
        $this->assertNull($third['after'], 'The last page says there is no next');

        $this->assertNotEmpty($queries);
        foreach ($queries as $message) {
            $this->assertStringNotContainsStringIgnoringCase('count(', (string)$message[0]);
            $this->assertStringNotContainsStringIgnoringCase('offset', (string)$message[0]);
        }
    }

    /**
     * The slideout's payload list: a save's fields as Field / Before / After, a redacted one as
     * changed with no value, and each access payload shape of task 2.2 as changes and details.
     */
    public function testEachPayloadReadsAsChangesAndDetails(): void
    {
        $saved = $this->describe(['data' => [
            'title' => ['label' => 'Title', 'old' => 'Old', 'new' => 'New'],
            'enabled' => ['label' => 'Enabled', 'old' => true, 'new' => false],
            'secret' => ['label' => 'Secret', 'redacted' => true],
        ]]);
        $this->assertSame([
            ['key' => 'title', 'label' => 'Title', 'old' => 'Old', 'new' => 'New', 'redacted' => false],
            ['key' => 'enabled', 'label' => 'Enabled', 'old' => 'Yes', 'new' => 'No', 'redacted' => false],
            ['key' => 'secret', 'label' => 'Secret', 'old' => '', 'new' => '', 'redacted' => true],
        ], $saved['changes']);

        $group = $this->describe(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_GROUP_SAVED, 'data' => [
            'group' => ['id' => 3, 'handle' => 'editors', 'name' => 'Editors'],
            'changes' => ['name' => ['old' => 'Writers', 'new' => 'Editors']],
            'permissions' => ['added' => ['accesscp'], 'removed' => []],
        ]]);
        $this->assertSame([['key' => 'changes.name', 'label' => 'Name', 'old' => 'Writers', 'new' => 'Editors', 'redacted' => false]], $group['changes']);
        $this->assertSame(
            ['Group' => 'Editors (editors)', 'Permissions added' => 'accesscp', 'Permissions removed' => ''],
            array_column($group['details'], 'value', 'label'),
        );

        $permissions = $this->describe(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_PERMISSIONS_CHANGED, 'data' => ['old' => ['accesscp', 'editentries'], 'new' => ['accesscp', 'deleteentries']]]);
        $this->assertSame(['Added' => 'deleteentries', 'Removed' => 'editentries'], array_column($permissions['details'], 'value', 'label'));

        $partial = $this->describe(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_PERMISSIONS_CHANGED, 'data' => ['new' => ['accesscp'], 'partial' => true]]);
        $this->assertSame(['Permissions' => 'accesscp', 'Partial' => 'Yes'], array_column($partial['details'], 'value', 'label'));

        $groups = $this->describe(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_GROUPS_CHANGED, 'data' => ['added' => [], 'removed' => ['editors']]]);
        $this->assertSame(['Added' => '', 'Removed' => 'editors'], array_column($groups['details'], 'value', 'label'));

        $admin = $this->describe(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_ADMIN_CHANGED, 'data' => ['admin' => ['old' => false, 'new' => true]]]);
        $this->assertSame([['key' => 'admin', 'label' => 'Admin', 'old' => 'No', 'new' => 'Yes', 'redacted' => false]], $admin['changes']);

        $mfa = $this->describe(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_MFA_ENABLED, 'data' => ['method' => \craft\auth\methods\TOTP::class]]);
        $this->assertSame(['Method' => \craft\auth\methods\TOTP::displayName()], array_column($mfa['details'], 'value', 'label'));

        $bulk = $this->describe(['event' => ElementWatcher::EVENT_BULK, 'data' => ['operation' => 'resave', 'count' => 25, 'elementType' => Entry::class]]);
        $this->assertSame(['Operation' => 'Re-save', 'Elements' => '25', 'Element type' => 'Entry'], array_column($bulk['details'], 'value', 'label'));

        $moved = $this->describe(['event' => ElementWatcher::EVENT_MOVED, 'data' => ['structureId' => 1, 'action' => 'placeAfter', 'targetId' => 9, 'targetLabel' => 'About']]);
        $this->assertSame(['Move' => 'After', 'Next to' => 'About'], array_column($moved['details'], 'value', 'label'));

        $redacted = $this->describe(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN_FAILED, 'data' => ['username' => 'x', 'password' => ['redacted' => true]]]);
        $this->assertTrue($redacted['details'][1]['redacted']);

        // A list of items (an order's line items, BR-23): a row per item added or removed, a row
        // per field changed, labels from the entry, a redacted value in words.
        $order = $this->describe(['data' => [
            'email' => ['label' => 'Email', 'old' => 'a@example.com', 'new' => 'b@example.com'],
            'lineItems' => [
                'label' => 'Line items',
                'fields' => ['qty' => 'Quantity', 'note' => 'Note'],
                'added' => [['id' => 3, 'label' => 'Scarf', 'values' => ['qty' => 2, 'note' => ['redacted' => true]]]],
                'removed' => [['id' => 2, 'label' => 'Cap', 'values' => ['qty' => 1, 'note' => null]]],
                'changed' => [['id' => 1, 'label' => 'Boot', 'changes' => ['qty' => ['old' => 1, 'new' => 3]]]],
            ],
        ]]);
        $this->assertSame(['email'], array_column($order['changes'], 'key'), 'the list is not a field change');
        $this->assertSame([
            ['id' => '3', 'item' => 'Scarf', 'change' => 'Added', 'old' => '', 'new' => "Quantity: 2\nNote: value not recorded", 'redacted' => false],
            ['id' => '2', 'item' => 'Cap', 'change' => 'Removed', 'old' => 'Quantity: 1', 'new' => '', 'redacted' => false],
            ['id' => '1', 'item' => 'Boot', 'change' => 'Quantity', 'old' => '1', 'new' => '3', 'redacted' => false],
        ], $order['collections'][0]['rows']);
        $this->assertSame('Line items', $order['collections'][0]['label']);

        // And they render: each change and detail carries its test hook.
        $id = $this->insert(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_GROUP_SAVED, 'data' => ['group' => ['name' => 'Editors'], 'changes' => ['name' => ['old' => 'Writers', 'new' => 'Editors']]]]);
        $detail = $this->view($this->admin, $id);
        $this->assertStringContainsString('data-auditor-field="changes.name"', $detail);
        $this->assertStringContainsString('data-auditor-field="group"', $detail);

        // A list of items renders as its own table, escaped like every value (BR-30).
        $id = $this->insert(['event' => ElementWatcher::EVENT_SAVED, 'data' => ['lineItems' => [
            'label' => 'Line items',
            'fields' => ['qty' => 'Quantity'],
            'added' => [],
            'removed' => [['id' => 2, 'label' => '<script>alert(1)</script>', 'values' => ['qty' => 1]]],
            'changed' => [['id' => 1, 'label' => 'Boot', 'changes' => ['qty' => ['old' => 1, 'new' => 3]]]],
        ]]]);
        $detail = $this->view($this->admin, $id);
        $this->assertStringContainsString('data-auditor-field="lineItems"', $detail);
        $this->assertStringContainsString('data-auditor-item="1"', $detail);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detail);
        $this->assertStringNotContainsString('<script>alert(1)', $detail);
    }

    /**
     * TN-5: a row links to its subject while it exists and the viewer may see it; once it is
     * deleted the row keeps its label and loses the link.
     */
    public function testARowLinksToItsSubjectOnlyWhileItExists(): void
    {
        $entry = $this->site->entry('Linked');
        $id = $this->insert(['elementId' => (int)$entry->id, 'elementType' => Entry::class, 'elementLabel' => 'Linked', 'siteId' => (int)$entry->siteId]);

        $event = $this->events->get($id);
        $this->assertNotNull($event);
        $this->assertSame([$id => $entry->getCpEditUrl()], $this->events->subjectUrls([$event], $this->admin));

        Craft::$app->getElements()->deleteElement($entry);
        $this->assertSame([], $this->events->subjectUrls([$event], $this->admin));
        $this->assertStringContainsString('Linked', $this->view($this->admin, $id));
    }

    /**
     * The page itself renders: the filters, the rows with their hooks, and on an empty log the
     * first-run text (§3); on a filter that matches nothing, a different one.
     */
    public function testThePageRendersItsRowsAndItsEmptyStates(): void
    {
        $empty = $this->logPage($this->admin, []);
        $this->assertStringContainsString('data-auditor-empty', $empty);
        $this->assertStringContainsString('Nothing recorded yet', $empty);

        $id = $this->insert(['elementLabel' => 'Summer menu']);
        $page = $this->logPage($this->admin, ['search' => 'summer']);
        $this->assertStringContainsString("data-auditor-event=\"$id\"", $page);
        $this->assertStringContainsString('data-auditor-filters', $page);
        $this->assertStringNotContainsString('data-auditor-older', $page, 'One page, so no older button');

        $none = $this->logPage($this->admin, ['search' => 'winter']);
        $this->assertStringContainsString('No events match these filters.', $none);
    }

    private function hasNavItem(): bool
    {
        foreach ((new Cp())->nav() as $item) {
            // Craft makes each item's address absolute.
            if (($item['url'] ?? null) === \craft\helpers\UrlHelper::cpUrl('auditor')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $expected
     * @param array<string, mixed> $params
     */
    private function assertIds(array $expected, array $params, string $message = ''): void
    {
        $page = $this->events->page(Filter::fromParams($params));
        $this->assertSame($expected, array_map(fn(Event $event) => $event->id, $page['events']), $message ?: json_encode($params) ?: '');
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{changes: list<array<string, mixed>>, details: list<array<string, mixed>>, collections: list<array<string, mixed>>}
     */
    private function describe(array $attributes): array
    {
        return $this->events->describe(new Event($attributes + ['family' => Event::FAMILY_ELEMENT, 'event' => ElementWatcher::EVENT_SAVED]));
    }

    /**
     * @return list<int>
     */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-auditor-event="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function view(User $user, int $id): string
    {
        return (string)$this->as($user, fn() => Craft::$app->runAction('auditor/events/view', ['id' => $id]))->data;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{html: string, after: int|null}
     */
    private function page(User $user, array $params): array
    {
        $after = $params['after'] ?? null;
        unset($params['after']);
        Craft::$app->getRequest()->setQueryParams($params);

        /** @var array{html: string, after: int|null} */
        return $this->as($user, fn() => Craft::$app->runAction('auditor/events/index', ['after' => $after]))->data;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function logPage(User $user, array $params): string
    {
        Craft::$app->getRequest()->setQueryParams($params);

        return $this->as($user, function() {
            /** @var Response $response */
            $response = Craft::$app->runAction('auditor/events/log');
            (new TemplateResponseFormatter())->format($response);

            return (string)$response->content;
        });
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
        $date = $attributes['dateCreated'] ?? new DateTime();
        unset($attributes['dateCreated']);

        $db = Craft::$app->getDb();
        $db->createCommand()->insert(Table::EVENTS, $attributes + [
            'family' => Event::FAMILY_ELEMENT,
            'event' => ElementWatcher::EVENT_SAVED,
            'elementLabel' => 'Entry',
            'actorLabel' => Event::SYSTEM,
            'context' => 'console',
            'requestId' => 'req-' . uniqid(),
            'dateCreated' => Db::prepareDateForDb($date),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
