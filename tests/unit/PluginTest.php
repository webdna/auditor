<?php

namespace webdna\auditor\tests\unit;

use Codeception\Test\Unit;
use Craft;
use webdna\auditor\Auditor;

/**
 * The plugin installs and boots under Craft's test harness, and its metadata agrees with itself.
 */
class PluginTest extends Unit
{
    public function testPluginIsInstalledAndBooted(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin(Auditor::HANDLE);

        $this->assertInstanceOf(Auditor::class, $plugin);
        $this->assertSame($plugin, Auditor::getInstance());
    }

    public function testHandleMatchesComposerMetadata(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame(Auditor::HANDLE, $composer['extra']['handle']);
        $this->assertSame(Auditor::class, $composer['extra']['class']);
        $this->assertSame('^5.6', $composer['require']['craftcms/cms']);
        $this->assertSame('^8.2', $composer['require']['php']);
    }

    /**
     * BR-39: only a schema above the other plugin's 1.0.0 makes Craft run this plugin's migrations
     * on a site that had it.
     */
    public function testSchemaVersionStartsAboveTheOlderPluginsVersion(): void
    {
        $plugin = Auditor::getInstance();

        $this->assertNotNull($plugin);
        $this->assertSame('2.0.0', $plugin->schemaVersion);
    }

    public function testEditionsAreLiteThenPro(): void
    {
        $this->assertSame([Auditor::EDITION_LITE, Auditor::EDITION_PRO], Auditor::editions());
    }
}
