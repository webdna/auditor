<?php

namespace webdna\auditor\tests\unit\models;

use Codeception\Test\Unit;
use craft\elements\Entry;
use webdna\auditor\Auditor;
use webdna\auditor\models\Settings;

/**
 * The settings model: defaults, BR-5's switches, and the example config file.
 */
class SettingsTest extends Unit
{
    private const EXAMPLE = __DIR__ . '/../../../examples/config/auditor.php';

    public function testDefaultsRecordEveryContext(): void
    {
        $settings = new Settings();

        $this->assertTrue($settings->validate());
        foreach (Settings::CONTEXTS as $context) {
            $this->assertTrue($settings->records($context), $context);
        }
    }

    public function testEnabledFalseRecordsNothing(): void
    {
        $settings = new Settings(['enabled' => false]);

        foreach (Settings::CONTEXTS as $context) {
            $this->assertFalse($settings->records($context), $context);
        }
    }

    public function testOnlyListedContextsAreRecorded(): void
    {
        $settings = new Settings(['contexts' => [Settings::CONTEXT_CP, Settings::CONTEXT_QUEUE]]);

        $this->assertTrue($settings->validate());
        $this->assertTrue($settings->records(Settings::CONTEXT_CP));
        $this->assertTrue($settings->records(Settings::CONTEXT_QUEUE));
        $this->assertFalse($settings->records(Settings::CONTEXT_SITE));
        $this->assertFalse($settings->records(Settings::CONTEXT_CONSOLE));
    }

    public function testUnknownContextIsRejected(): void
    {
        $settings = new Settings(['contexts' => ['cp', 'web']]);

        $this->assertFalse($settings->validate());
        $this->assertTrue($settings->hasErrors('contexts'));
    }

    public function testExcludedTypesMustBeElementClasses(): void
    {
        $this->assertTrue((new Settings(['excludeElementTypes' => [Entry::class]]))->validate());

        $settings = new Settings(['excludeElementTypes' => ['craft\\elements\\Entrie']]);
        $this->assertFalse($settings->validate());
        $this->assertTrue($settings->hasErrors('excludeElementTypes'));
    }

    public function testNumbersHaveFloors(): void
    {
        $this->assertTrue((new Settings(['retentionDays' => 0]))->validate(), '0 keeps for ever');
        $this->assertFalse((new Settings(['retentionDays' => -1]))->validate());
        $this->assertFalse((new Settings(['bulkThreshold' => 0]))->validate());
    }

    /**
     * Craft rejects a config key the model does not declare, so the example must carry only
     * declared keys — and, since it says its values are the defaults, exactly the defaults.
     */
    public function testExampleConfigIsEveryDefaultAndNothingElse(): void
    {
        $example = require self::EXAMPLE;
        $defaults = (new Settings())->toArray();

        $this->assertIsArray($example);
        $this->assertSame(array_keys($defaults), array_keys($example));
        $this->assertSame($defaults, $example);
        $this->assertTrue((new Settings($example))->validate());
    }

    public function testPluginUsesTheSettingsModel(): void
    {
        $plugin = Auditor::getInstance();

        $this->assertNotNull($plugin);
        $this->assertInstanceOf(Settings::class, $plugin->getSettings());
    }
}
