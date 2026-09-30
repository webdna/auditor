<?php

namespace webdna\auditor;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Application;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * Auditor: one log of what changed on a Craft site, who changed it and from where, and of every
 * sign-in and change of access.
 *
 * Both editions record identically (BR-33); Pro adds the History and Activity views.
 *
 * @author webdna
 * @since 1.0.0
 *
 * @method Settings getSettings()
 * @property-read Recorder $recorder
 * @property-read Differ $differ
 */
class Auditor extends Plugin
{
    /**
     * The handle, as Craft knows the plugin and as the translation and log categories are named.
     */
    public const HANDLE = 'auditor';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /**
     * Starts above 1.0.0 on purpose (BR-39): a different plugin with this handle was installed at
     * 1.0.0 on some sites, and only a higher version makes Craft run this plugin's migrations there.
     */
    public string $schemaVersion = '2.0.0';

    /**
     * @return string[]
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'recorder' => Recorder::class,
                'differ' => Differ::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $elements = new ElementWatcher($this);
        $elements->attach();
        $this->registerFlushes($elements);
    }

    public function getRecorder(): Recorder
    {
        /** @var Recorder */
        return $this->get('recorder');
    }

    public function getDiffer(): Differ
    {
        /** @var Differ */
        return $this->get('differ');
    }

    /**
     * The recorder's flush points (BR-3). Each asks whether the recorder was ever created, so a
     * request that recorded nothing does no work here at all (BR-34).
     */
    private function registerFlushes(ElementWatcher $elements): void
    {
        // The watcher goes first at each end, so a batch still open records its row in time for the
        // flush, and in the job's name.
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function() use ($elements) {
            $elements->closeAll();
            if ($this->has('recorder', true)) {
                $this->getRecorder()->flush();
            }
        });

        // Class-level, so the queue component is not created just to listen to it.
        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, function(ExecEvent $event) use ($elements) {
            if (is_object($event->job)) {
                $this->getRecorder()->beginJob($event->job);
                $elements->beginJob($event->job);
            }
        });
        $endJob = function() use ($elements) {
            $elements->endJob();
            if ($this->has('recorder', true)) {
                $this->getRecorder()->endJob();
            }
        };
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, $endJob);
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, $endJob);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
