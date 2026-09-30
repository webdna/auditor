<?php

namespace webdna\auditor\tests\integration;

use Codeception\Test\Unit;
use craft\db\Connection;
use craft\elements\Entry;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\events\DefineElementSnapshotEvent;
use webdna\auditor\models\Event;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\CommerceWatcher;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Event as YiiEvent;

/**
 * `EVENT_DEFINE_ELEMENT_SNAPSHOT` (BR-24) on real entry saves: a key compared as a field is, a key
 * its handler words itself, a new element's keys, and redaction of both (BR-10). And TN-14: with
 * Commerce absent, nothing of the Commerce watcher is attached or loaded.
 *
 * As in the element watcher's tests, the recorder is on a second connection, so rows are ready at
 * once.
 */
class ElementSnapshotTest extends Unit
{
    use SecondConnection;

    private TestSite $site;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * What the test's handler reports as the entry's state, by entry id.
     *
     * @var array<int, mixed>
     */
    private array $state = [];

    /**
     * The test's handler: reports {@see $state} under `stock`, and a worded `lines` change when set.
     */
    private ?\Closure $handler = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $lines = null;

    /**
     * @var list<array<string, mixed>|null>
     */
    private array $befores = [];

    /**
     * @var string[] uids of rows a test flushed to the table, removed afterwards
     */
    private array $written = [];

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->settings = $this->plugin->getSettings()->toArray();
        $this->site = TestSite::build();

        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
        $this->plugin->set('differ', Differ::class);
    }

    protected function _after(): void
    {
        if ($this->handler !== null) {
            YiiEvent::off(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT, $this->handler);
        }
        if ($this->written) {
            $this->db->createCommand()->delete(Table::EVENTS, ['uid' => $this->written])->execute();
        }
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
        $this->plugin->getSettings()->setAttributes($this->settings, false);
        TestSite::removeFiles();
    }

    /**
     * TN-14: the integration suite runs without Commerce, and nothing of it is attached or loaded.
     */
    public function testWithoutCommerceNothingOfItIsAttachedOrLoaded(): void
    {
        $this->assertFalse(CommerceWatcher::applies());
        $this->assertFalse(YiiEvent::hasHandlers(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT), 'no snapshot handler without Commerce');

        $entry = $this->site->entry('Plain');
        $entry->title = 'Plain, saved';
        $this->site->save($entry);

        $this->assertNotEmpty($this->rowsFor($entry), 'saves record as usual');
        $this->assertFalse(class_exists(CommerceWatcher::ORDER, false), 'no Commerce class loaded');
        $this->assertFalse(class_exists('craft\\commerce\\Plugin', false));
    }

    /**
     * A handler's key is read before the save and after it, and recorded as a field is when the two
     * differ; unchanged, it adds nothing, and a save with nothing else writes no row (BR-7).
     */
    public function testAKeyIsComparedAsAFieldIs(): void
    {
        $this->listen();
        $entry = $this->site->entry('Stocked');
        $this->state[(int)$entry->id] = 1;
        $this->clear();

        $this->state[(int)$entry->id] = 1;
        $this->site->save($entry);
        $this->assertSame([], $this->rowsFor($entry), 'unchanged, and nothing else changed: no row');

        $this->state[(int)$entry->id] = 4;
        $this->befores = [];
        // The handler's before read happens as the save starts, so change the state inside it.
        $this->saveChangingStateTo($entry, 5);
        $row = $this->onlyRowFor($entry);
        $this->assertSame(ElementWatcher::EVENT_SAVED, $row->event);
        $this->assertSame(['stock' => ['label' => 'stock', 'old' => 4, 'new' => 5]], $row->data);
        $this->assertSame([null, ['stock' => 4]], $this->befores, 'called before the save, then after it with what it read');
    }

    /**
     * A handler that words its change is recorded as given, redacted by key at any depth but for
     * its field labels (BR-10); an empty change means nothing changed.
     */
    public function testAWordedChangeIsRecordedAsGivenAndRedacted(): void
    {
        $this->plugin->getSettings()->redact = array_merge($this->plugin->getSettings()->redact, ['secretNote']);
        $this->listen();
        $entry = $this->site->entry('Lined');
        $this->state[(int)$entry->id] = 1;
        $this->clear();

        $this->lines = [
            'label' => 'Lines',
            'fields' => ['qty' => 'Quantity', 'secretNote' => 'Secret note'],
            'added' => [['id' => 1, 'label' => 'Boot', 'values' => ['qty' => 1, 'secretNote' => 'wrapped']]],
            'removed' => [],
            'changed' => [['id' => 2, 'label' => 'Cap', 'changes' => ['secretNote' => ['old' => 'a', 'new' => 'b']]]],
        ];
        $this->site->save($entry);

        $lines = $this->onlyRowFor($entry)->data['lines'] ?? null;
        $this->assertSame(['qty' => 'Quantity', 'secretNote' => 'Secret note'], $lines['fields'] ?? null, 'labels are words, never redacted');
        $this->assertSame(['qty' => 1, 'secretNote' => ['redacted' => true]], $lines['added'][0]['values'] ?? null);
        $this->assertSame(['secretNote' => ['redacted' => true]], $lines['changed'][0]['changes'] ?? null);
        $this->assertStringNotContainsString('wrapped', (string)json_encode($lines));

        $this->clear();
        $this->lines = [];
        $this->site->save($entry);
        $this->assertSame([], $this->rowsFor($entry), 'an empty change is no change');
    }

    /**
     * A new element's handler keys are on its created row, with an empty before (BR-11); a key in
     * `redact` is recorded as changed with no value.
     */
    public function testANewElementCarriesItsKeysAndARedactedKeyHasNoValue(): void
    {
        $this->listen();
        $this->state[0] = 7;
        $entry = $this->site->entry('Fresh');

        $row = $this->onlyRowFor($entry);
        $this->assertSame(ElementWatcher::EVENT_CREATED, $row->event);
        $this->assertSame(['label' => 'stock', 'old' => null, 'new' => 7], $row->data['stock'] ?? null);
        $this->assertSame([], $this->befores[array_key_last($this->befores)], 'a new element has an empty before');

        $this->plugin->getSettings()->redact = array_merge($this->plugin->getSettings()->redact, ['stock']);
        $this->clear();
        $this->state[(int)$entry->id] = 1;
        $this->saveChangingStateTo($entry, 2);
        $this->assertSame(['stock' => ['label' => 'stock', 'redacted' => true]], $this->onlyRowFor($entry)->data);
    }

    private function listen(): void
    {
        $this->handler = function(DefineElementSnapshotEvent $event) {
            if (!$event->element instanceof Entry) {
                return;
            }
            $this->befores[] = $event->before;
            $id = (int)$event->element->id;
            $event->values['stock'] = $this->state[$id] ?? $this->state[0] ?? null;
            if ($event->before !== null && $this->lines !== null) {
                $event->changes['lines'] = $this->lines;
            }
        };
        YiiEvent::on(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT, $this->handler);
    }

    /**
     * Saves the entry with its reported state changed between the before and after reads, as a
     * handler's own data would change during the save.
     */
    private function saveChangingStateTo(Entry $entry, mixed $value): void
    {
        $id = (int)$entry->id;
        $change = function() use ($id, $value) {
            $this->state[$id] = $value;
        };
        YiiEvent::on(Entry::class, Entry::EVENT_AFTER_SAVE, $change);
        try {
            $this->site->save($entry);
        } finally {
            YiiEvent::off(Entry::class, Entry::EVENT_AFTER_SAVE, $change);
        }
    }

    /**
     * @return Event[]
     */
    private function rowsFor(Entry $entry): array
    {
        return array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->elementId === (int)$entry->id));
    }

    private function onlyRowFor(Entry $entry): Event
    {
        $rows = $this->rowsFor($entry);
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * Forgets the rows recorded so far — the test's own setting-up.
     */
    private function clear(): void
    {
        foreach ($this->recorder->getReady() as $row) {
            $this->written[] = (string)$row->uid;
        }
        $this->recorder->flush();
    }
}
