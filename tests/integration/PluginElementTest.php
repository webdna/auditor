<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\db\Connection;
use craft\db\Query;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\models\Event;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\Review;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Event as YiiEvent;

/**
 * The README's example for a plugin element whose data sits in its own table (#1): Auditor tracks
 * none of a plugin element's attributes by default, and the example's handler names its review
 * status and rating, after which a save records a change to either like any tracked attribute
 * (BR-9, BR-7, BR-11).
 *
 * The reviews are saved through Craft on its own connection, inside the harness's per-test
 * transaction, into a temporary `reviews` table; the recorder is on a second connection, so the
 * rows a save records are ready at once.
 */
class PluginElementTest extends Unit
{
    use SecondConnection;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    private ?Closure $handler = null;

    /**
     * How many of the recorder's ready rows the test has already looked at.
     */
    private int $seen = 0;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        // The differ asks once per element type: a fresh one asks again.
        $this->plugin->set('differ', Differ::class);
        Review::createTable();

        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
    }

    protected function _after(): void
    {
        if ($this->handler !== null) {
            YiiEvent::off(Differ::class, Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, $this->handler);
        }
        $this->db->createCommand()->delete(Table::EVENTS, ['elementType' => Review::class])->execute();
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
        Review::dropTable();
    }

    /**
     * Without a handler a review's status is not Auditor's to know: changing it records nothing.
     */
    public function testAPluginElementHasNoTrackedAttributesByDefault(): void
    {
        $review = $this->review('pending', 3);

        $review->reviewStatus = 'approved';
        $this->save($review);

        $this->assertSame([], $this->unseen());
    }

    /**
     * With the README's handler, the created row holds the status and the rating, and a save that
     * approves the review records the status alone, read before from the review's own table.
     */
    public function testTheReadmeExampleRecordsAStatusChange(): void
    {
        $this->readmeExample();

        $review = new Review(['reviewStatus' => 'pending', 'rating' => 3]);
        $this->save($review);
        $created = $this->only(ElementWatcher::EVENT_CREATED);
        $this->assertSame(['label' => 'Review status', 'old' => null, 'new' => 'pending'], $created->data['reviewStatus'] ?? null);
        $this->assertSame(['label' => 'Rating', 'old' => null, 'new' => 3], $created->data['rating'] ?? null);

        $review = $this->fresh($review);
        $review->reviewStatus = 'approved';
        $this->save($review);
        $saved = $this->only(ElementWatcher::EVENT_SAVED);
        $this->assertSame(Review::class, $saved->elementType);
        $this->assertSame((int)$review->id, $saved->elementId);
        $this->assertSame(['reviewStatus'], array_keys($saved->data ?? []), 'The rating did not change');
        $this->assertSame(['label' => 'Review status', 'old' => 'pending', 'new' => 'approved'], $saved->data['reviewStatus']);

        $review->rating = 5;
        $this->save($review);
        $this->assertSame(
            ['label' => 'Rating', 'old' => 3, 'new' => 5],
            $this->only(ElementWatcher::EVENT_SAVED)->data['rating'] ?? null,
            'The stored rating is read back as a number',
        );

        // Nothing changed: no row (BR-7).
        $this->save($review);
        $this->assertSame([], $this->unseen());
    }

    /**
     * The handler as the README gives it, `myplugin\elements\Review` being the test's own.
     */
    private function readmeExample(): void
    {
        $this->handler = function(DefineTrackedAttributesEvent $event) {
            if ($event->elementType === Review::class) {
                $event->attributes['reviewStatus'] = 'reviews.status';
                $event->attributes['rating'] = 'reviews.rating';
            }
        };
        YiiEvent::on(Differ::class, Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, $this->handler);
    }

    private function review(string $status, int $rating): Review
    {
        $review = new Review(['reviewStatus' => $status, 'rating' => $rating]);
        $this->save($review);
        $this->unseen();

        return $this->fresh($review);
    }

    private function save(Review $review): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($review), implode(', ', $review->getFirstErrors()));
    }

    /**
     * A review as a request would load it: its element row from Craft, its own columns from its table.
     */
    private function fresh(Review $review): Review
    {
        /** @var array{status: string|null, rating: int|string|null} $row */
        $row = (new Query())->from(Review::TABLE)->where(['id' => $review->id])->one();

        return new Review([
            'id' => $review->id,
            'uid' => $review->uid,
            'siteId' => $review->siteId,
            'reviewStatus' => $row['status'],
            'rating' => $row['rating'] === null ? null : (int)$row['rating'],
        ]);
    }

    /**
     * The one row the last save recorded, which must be of the given kind.
     */
    private function only(string $kind): Event
    {
        $rows = $this->unseen();
        $this->assertCount(1, $rows);
        $this->assertSame($kind, $rows[0]->event);

        return $rows[0];
    }

    /**
     * The rows recorded since the last look.
     *
     * @return Event[]
     */
    private function unseen(): array
    {
        $ready = $this->recorder->getReady();
        $rows = array_slice($ready, $this->seen);
        $this->seen = count($ready);

        return $rows;
    }
}
