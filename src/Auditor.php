<?php

namespace webdna\auditor;

use craft\base\Model;
use craft\base\Plugin;
use webdna\auditor\models\Settings;

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

    public function init(): void
    {
        parent::init();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
