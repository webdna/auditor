<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\db\Connection;
use craft\elements\Entry;
use craft\web\View;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\AuditorListeners;
use webdna\auditor\tests\Support\TestSite;
use Yii;
use yii\base\Application;
use yii\log\Logger;

/**
 * Speed (TS-12 steps 1-2): a page view costs nothing with Auditor installed (BR-34), and a save
 * costs no more than BR-35's budget. Step 3, the resave benchmark, is `tests/bench/resave.php`.
 *
 * "Off" is the same request with every one of the plugin's event handlers taken away
 * ({@see AuditorListeners}), which is how the site would run without it. A query is a database
 * command, on any connection, counted from Yii's profiling log.
 */
class SpeedTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    /**
     * BR-35 for an entry: one read of its content row joined to its attribute tables, one of its
     * authors (stored elsewhere), and its share of the request's one insert.
     */
    private const SAVE_BUDGET = 3;

    private TestSite $site;

    private Auditor $plugin;

    private Connection $db;

    private string $edition;

    private int $lastId;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->edition = $this->plugin->edition;
        $this->site = TestSite::build();
        $this->db = $this->secondConnection();
        $this->lastId = (int)$this->db->createCommand('SELECT MAX([[id]]) FROM ' . Table::EVENTS)->queryScalar();
        $this->plugin->set('recorder', Recorder::class);
    }

    protected function _after(): void
    {
        $this->db->createCommand()->delete(Table::EVENTS, ['>', 'id', $this->lastId])->execute();
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->edition = $this->edition;
        Craft::$app->getUser()->setIdentity(null);
        TestSite::removeFiles();
    }

    /**
     * TS-12 step 1, BR-34: a front-end page — element queries, custom fields, relations, the end of
     * the request — runs exactly the same queries with Auditor on as off, for a guest and for a
     * signed-in editor on Pro, and never creates the recorder.
     */
    public function testAPageViewRunsTheSameQueriesWithAuditorOnAsOff(): void
    {
        $other = $this->site->entry('Other');
        foreach (range(1, 3) as $n) {
            $this->site->entry("Page $n", ['plainText' => "Text $n", 'number' => $n, 'related' => [$other->id]]);
        }
        $this->assertGreaterThan(20, AuditorListeners::count(), 'The plugin has handlers to take away');

        $this->plugin->edition = Auditor::EDITION_PRO;
        $editor = $this->site->user('editor', true);
        // Building the content recorded it; the page views start from a recorder never created.
        $this->plugin->set('recorder', Recorder::class);

        foreach (['guest' => null, 'editor' => $editor] as $visitor => $identity) {
            Craft::$app->getUser()->setIdentity($identity);
            // Once unmeasured, so neither measured view pays for compiling the template.
            $this->assertStringContainsString('Page 3', $this->view());

            $off = $this->normalised($this->queriesDuring(fn() => AuditorListeners::detached(fn() => $this->view())));
            $on = $this->normalised($this->queriesDuring(fn() => $this->view()));

            // At least the entries and, lazily eager-loaded in one go, their relations.
            $this->assertGreaterThanOrEqual(2, count($off), "The {$visitor}’s page queries the database");
            $this->assertSame($off, $on, "The $visitor's page runs the same queries with Auditor on");
            $this->assertFalse($this->plugin->has('recorder', true), 'Nothing created the recorder');
        }
    }

    /**
     * TS-12 step 2, BR-35: saving one entry with two changed fields costs, beyond the save itself,
     * no more than the entry's snapshot and one insert — plus, when one of them is a relation
     * field, the ids query its serialisation runs — and the row it writes holds both changes.
     */
    public function testASaveWithTwoChangedFieldsStaysWithinItsBudget(): void
    {
        $this->plugin->set('recorder', new Recorder(['db' => $this->db]));
        $targets = [$this->site->entry('One'), $this->site->entry('Two'), $this->site->entry('Three')];
        $entry = $this->site->entry('Budget', ['plainText' => 'a', 'number' => 1, 'related' => [$targets[0]->id]]);

        $cases = [
            'two plain fields' => [self::SAVE_BUDGET, fn(int $n) => ['plainText' => "text $n", 'number' => $n]],
            'a plain field and a relation' => [self::SAVE_BUDGET + 1, fn(int $n) => ['plainText' => "text $n", 'related' => [$targets[$n % 3]->id]]],
        ];
        $n = 0;
        foreach ($cases as $case => [$budget, $values]) {
            [$warmOff, $warmOn, $off, $on] = [$values(++$n), $values(++$n), $values(++$n), $values(++$n)];

            // Once each way unmeasured, so neither measured save pays Craft's first-save caches.
            AuditorListeners::detached(fn() => $this->change($entry, $warmOff));
            $this->change($entry, $warmOn);
            $this->plugin->getRecorder()->flush();

            $queriesOff = count($this->queriesDuring(fn() => AuditorListeners::detached(fn() => $this->change($entry, $off))));
            $queriesOn = count($this->queriesDuring(function() use ($entry, $on) {
                $this->change($entry, $on);
                $this->plugin->getRecorder()->flush();
            }));

            $this->assertLessThanOrEqual($budget, $queriesOn - $queriesOff, sprintf('%s: %d queries off, %d on', $case, $queriesOff, $queriesOn));

            $row = $this->db->createCommand('SELECT * FROM ' . Table::EVENTS . ' ORDER BY [[id]] DESC')->queryOne();
            $this->assertIsArray($row);
            $data = json_decode((string)$row['data'], true);
            $this->assertIsArray($data);
            $this->assertEqualsCanonicalizing(array_keys($on), array_keys($data), "$case: the row holds both changes");
            $this->assertSame($off['plainText'], $data['plainText']['old'] ?? null);
            $this->assertSame($on['plainText'], $data['plainText']['new'] ?? null);
        }
    }

    /**
     * BR-35 (#7): the blocks an entry saves in its own save have their stored state read together,
     * in one query per table — their rows, their authors — so eight changed blocks cost what one
     * does, and each block's row still holds its change.
     */
    public function testAnEntrySavesEightChangedBlocksForTheQueriesOfOne(): void
    {
        $this->plugin->set('recorder', new Recorder(['db' => $this->db]));
        $extra = [];
        foreach ([1, 8] as $count) {
            $blocks = [];
            foreach (range(1, $count) as $n) {
                $blocks["new$n"] = ['type' => 'block', 'title' => "Block $n", 'fields' => ['blockText' => 'a']];
            }
            $entry = $this->site->entry("Blocks $count", ['blocks' => $blocks]);

            AuditorListeners::detached(fn() => $this->changeBlocks($entry, 'b'));
            $this->changeBlocks($entry, 'c');
            $this->plugin->getRecorder()->flush();

            $off = count($this->queriesDuring(fn() => AuditorListeners::detached(fn() => $this->changeBlocks($entry, 'd'))));
            $on = count($this->queriesDuring(function() use ($entry) {
                $this->changeBlocks($entry, 'e');
                $this->plugin->getRecorder()->flush();
            }));
            $extra[$count] = $on - $off;

            $rows = $this->db->createCommand('SELECT [[data]] FROM ' . Table::EVENTS . ' WHERE [[ownerId]] = :owner ORDER BY [[id]] DESC LIMIT ' . $count, [
                ':owner' => $entry->id,
            ])->queryColumn();
            $this->assertCount($count, $rows, "$count blocks: one row each");
            foreach ($rows as $data) {
                $data = json_decode((string)$data, true);
                $this->assertSame('d', $data['blockText']['old'] ?? null, "$count blocks: the stored text before the save");
                $this->assertSame('e', $data['blockText']['new'] ?? null, "$count blocks: the text saved");
            }
        }

        $this->assertSame($extra[1], $extra[8], sprintf('Extra queries: %d with one block, %d with eight', $extra[1], $extra[8]));
    }

    /**
     * Saves the entry with every block posted and each one's text set, as the control panel's
     * entry form saves it.
     */
    private function changeBlocks(Entry $entry, string $text): void
    {
        $blocks = [];
        foreach (Entry::find()->ownerId($entry->id)->fieldId($this->site->fields['blocks']->id)->status(null)->ids() as $id) {
            $blocks[$id] = ['type' => 'block', 'fields' => ['blockText' => $text]];
        }
        $this->change($entry, ['blocks' => $blocks]);
    }

    private function view(): string
    {
        return $this->asWebRequest(function() {
            $html = Craft::$app->getView()->renderTemplate('speed/page', [], View::TEMPLATE_MODE_SITE);
            Craft::$app->trigger(Application::EVENT_AFTER_REQUEST);

            return $html;
        });
    }

    /**
     * Saves a freshly loaded copy of the entry with the values set, as a control panel save of a
     * stored entry would.
     *
     * @param array<string, mixed> $values
     */
    private function change(Entry $entry, array $values): void
    {
        $fresh = Entry::find()->id($entry->id)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $fresh);
        $fresh->setFieldValues($values);
        $this->site->save($fresh);
    }

    /**
     * The SQL with what Craft makes up afresh each time taken out: a relation join's random alias,
     * the project config version and the second it was written.
     *
     * @param string[] $queries
     * @return string[]
     */
    private function normalised(array $queries): array
    {
        return array_map(fn(string $sql) => (string)preg_replace(
            ['/relations_[a-z]+/', "/'[a-z]{12}'/", "/'\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d'/"],
            ['relations_x', "'token'", "'date'"],
            $sql,
        ), $queries);
    }

    /**
     * The database commands run during a callback, in order, as SQL.
     *
     * @return string[]
     */
    private function queriesDuring(Closure $run): array
    {
        $harness = Yii::getLogger();
        // Never flushed mid-count: a save with nested elements logs more than the thousand messages
        // a logger keeps by default before it empties itself.
        $logger = new Logger(['flushInterval' => PHP_INT_MAX]);
        Yii::setLogger($logger);
        try {
            $run();
        } finally {
            Yii::setLogger($harness);
        }

        $queries = array_filter($logger->messages, fn(array $message) => $message[1] === Logger::LEVEL_PROFILE_BEGIN
            && str_starts_with((string)$message[2], 'yii\db\Command::'));

        return array_values(array_map(fn(array $message) => (string)$message[0], $queries));
    }
}
