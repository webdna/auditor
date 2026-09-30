<?php

namespace webdna\auditor\tests\bench;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\AuditorListeners;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\ElementWatcher;

/**
 * TS-12 step 3, BR-36: re-saving the entries of a section takes at most 10% longer with Auditor on
 * than off. "Off" is the same resave with every one of the plugin's handlers taken away
 * ({@see AuditorListeners}); "on" includes the flush that writes its batch row, as the end of the
 * request would.
 *
 * Runs only through `tests/bench/resave.php`, which sets the size and prints the result; anywhere
 * else it is skipped, so a plain `codecept run` never spends minutes on it. The database is the
 * harness's own, reinstalled at the start of every suite run.
 */
class ResaveTest extends Unit
{
    public const RESULT = 'bench-resave.json';

    private TestSite $site;

    protected function _before(): void
    {
        if (getenv('AUDITOR_BENCH') !== '1') {
            $this->markTestSkipped('Run through tests/bench/resave.php.');
        }
        $this->site = TestSite::build();
    }

    protected function _after(): void
    {
        TestSite::removeFiles();
    }

    public function testResaveOverhead(): void
    {
        $count = max(1, (int)getenv('AUDITOR_BENCH_ENTRIES'));
        $runs = max(1, (int)getenv('AUDITOR_BENCH_RUNS'));
        $plugin = Auditor::getInstance();
        $plugin->set('recorder', Recorder::class);

        // Content with a few kinds of field, made with Auditor off so the setup is quick.
        AuditorListeners::detached(function() use ($count) {
            $previous = null;
            for ($i = 1; $i <= $count; $i++) {
                $previous = $this->site->entry("Entry $i", [
                    'plainText' => str_repeat("Text $i ", 20),
                    'number' => $i,
                    'lightswitch' => $i % 2 === 0,
                    'dropdown' => $i % 2 === 0 ? 'red' : 'blue',
                    'related' => $previous ? [$previous->id] : [],
                ]);
            }
        });

        // Once unmeasured, so neither side pays for warming Craft's caches.
        $this->resave(true);

        $times = ['off' => [], 'on' => []];
        for ($run = 1; $run <= $runs; $run++) {
            $times['off'][] = $this->resave(false);
            $times['on'][] = $this->resave(true);
        }

        $bulk = Craft::$app->getDb()->createCommand('SELECT [[data]] FROM ' . Table::EVENTS . ' WHERE [[event]] = :bulk ORDER BY [[id]] DESC', [':bulk' => ElementWatcher::EVENT_BULK])->queryScalar();
        $this->assertIsString($bulk, 'Auditor recorded the resave');
        $this->assertSame($count, json_decode($bulk, true)['count'] ?? null, 'As one batch of every entry');

        $off = $this->median($times['off']);
        $on = $this->median($times['on']);
        file_put_contents(codecept_output_dir(self::RESULT), json_encode([
            'entries' => $count,
            'runs' => $times,
            'medianOff' => $off,
            'medianOn' => $on,
            'overhead' => ($on - $off) / $off * 100,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Re-saves every entry in the section, as `craft resave/entries` does, and says how long it took
     * in seconds.
     */
    private function resave(bool $auditor): float
    {
        $query = Entry::find()->sectionId($this->site->pages->id)->status(null);
        $run = function() use ($query) {
            Craft::$app->getElements()->resaveElements($query, true);
        };

        $start = hrtime(true);
        if ($auditor) {
            $run();
            Auditor::getInstance()->getRecorder()->flush();
        } else {
            AuditorListeners::detached($run);
        }

        return (hrtime(true) - $start) / 1e9;
    }

    /**
     * @param float[] $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
