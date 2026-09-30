<?php

namespace webdna\auditor;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Events;
use webdna\auditor\services\Recorder;
use webdna\auditor\watchers\AuthWatcher;
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
 * @property-read Events $events
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
     * Opening the log (BR-28). It is Craft's own "Access Auditor": Craft refuses every control
     * panel address under the plugin's handle without it, so a permission of the plugin's own
     * could never open the log alone.
     */
    public const PERMISSION_VIEW_LOG = 'accessPlugin-' . self::HANDLE;

    /**
     * Seeing the address and browser a row came from (BR-27).
     */
    public const PERMISSION_VIEW_NETWORK = 'auditor:viewNetwork';

    /**
     * Starts above 1.0.0 on purpose (BR-39): a different plugin with this handle was installed at
     * 1.0.0 on some sites, and only a higher version makes Craft run this plugin's migrations there.
     */
    public string $schemaVersion = '2.0.0';

    public bool $hasCpSection = true;

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
                'events' => Events::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $elements = new ElementWatcher($this);
        $elements->attach();
        (new AuthWatcher($this))->attach();
        $this->registerFlushes($elements);
        $this->registerControlPanel();
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

    public function getEvents(): Events
    {
        /** @var Events */
        return $this->get('events');
    }

    /**
     * The log screen's address and the permissions of BR-28. Class-level listeners, so neither
     * the URL manager nor the permissions service is created just to be listened to.
     */
    private function registerControlPanel(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules[self::HANDLE] = self::HANDLE . '/events/log';
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('auditor', 'Auditor'),
                'permissions' => [
                    self::PERMISSION_VIEW_NETWORK => [
                        'label' => Craft::t('auditor', 'See the address and browser each event came from'),
                    ],
                ],
            ];
        });
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
