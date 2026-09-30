<?php

namespace webdna\auditor;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\User;
use craft\events\DefineMenuItemsEvent;
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
use webdna\auditor\web\assets\log\LogAsset;
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
     * Opening an element's History (BR-28, BR-31). Pro only.
     */
    public const PERMISSION_VIEW_HISTORY = 'auditor:viewHistory';

    /**
     * The History item's id in an element's action menu, a test hook (§7).
     */
    public const HISTORY_MENU_ITEM = 'auditor-history';

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
        $this->offerHistory();
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
            $permissions = [
                self::PERMISSION_VIEW_NETWORK => [
                    'label' => Craft::t('auditor', 'See the address and browser each event came from'),
                ],
            ];
            if ($this->is(self::EDITION_PRO)) {
                $permissions[self::PERMISSION_VIEW_HISTORY] = [
                    'label' => Craft::t('auditor', 'See the history of what they can view'),
                ];
            }

            $event->permissions[] = [
                'heading' => Craft::t('auditor', 'Auditor'),
                'permissions' => $permissions,
            ];
        });
    }

    /**
     * History in every element's action menu, on Pro, for someone allowed to open it (BR-31,
     * BR-33). Class-level, so it reaches every element type's edit screen.
     */
    private function offerHistory(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_ACTION_MENU_ITEMS, function(DefineMenuItemsEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $item = $this->historyMenuItem($element, Craft::$app->getUser()->getIdentity());
            if ($item !== null) {
                $event->items[] = $item;
                Craft::$app->getView()->registerAssetBundle(LogAsset::class);
                Craft::$app->getView()->registerTranslations('auditor', [
                    'This event could not be loaded.',
                    'Older events could not be loaded.',
                    'The history could not be loaded.',
                ]);
            }
        });
    }

    /**
     * The History menu item for an element, or null where it does not belong: on Lite, for a
     * viewer without the permission, for an unpublished draft (it has no history yet), or for an
     * element type the log never records.
     *
     * It names the canonical element, because the edit screen usually holds a provisional draft,
     * and it carries only ids: the History itself is fetched when the item is chosen, so the edit
     * screen pays nothing for it (BR-37).
     *
     * @return array<string, mixed>|null
     */
    public function historyMenuItem(ElementInterface $element, ?User $viewer): ?array
    {
        if (!$this->is(self::EDITION_PRO) || !$viewer?->can(self::PERMISSION_VIEW_HISTORY)) {
            return null;
        }
        if (!$element->id || $element->getIsUnpublishedDraft()) {
            return null;
        }
        foreach ($this->getSettings()->excludeElementTypes as $type) {
            if ($element instanceof $type) {
                return null;
            }
        }

        return [
            'id' => self::HISTORY_MENU_ITEM,
            'icon' => 'clock-rotate-left',
            'label' => Craft::t('auditor', 'History'),
            'attributes' => [
                'data' => [
                    'auditor-history' => true,
                    'element-id' => $element->getCanonicalId(),
                    'site-id' => $element->siteId,
                ],
            ],
        ];
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
