<?php

namespace webdna\auditor\tests\unit\services;

use Closure;
use Codeception\Test\Unit;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use webdna\auditor\Auditor;
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Differ;

/**
 * The differ's logic that needs no stored rows: payload redaction (BR-10) and the tracked-attribute
 * map (BR-9). Value comparison per field type needs real saves and is in the integration suite.
 */
class DifferTest extends Unit
{
    public function testAPayloadIsRedactedAtAnyDepthAndOnlyByKey(): void
    {
        $differ = new Differ(['settings' => new Settings()]);

        $redacted = $differ->redact([
            'username' => 'someone',
            'password' => 'hunter2',
            'nested' => ['newPassword' => 'hunter3', 'kept' => 'password'],
            'list' => [['verificationCode' => 'abc']],
        ]);

        $this->assertSame([
            'username' => 'someone',
            'password' => ['redacted' => true],
            'nested' => ['newPassword' => ['redacted' => true], 'kept' => 'password'],
            'list' => [['verificationCode' => ['redacted' => true]]],
        ], $redacted);
    }

    public function testTheRedactListComesFromTheSettings(): void
    {
        $differ = new Differ(['settings' => new Settings(['redact' => ['bankAccount']])]);

        $this->assertTrue($differ->isRedacted('bankAccount'));
        $this->assertFalse($differ->isRedacted('password'), 'A list set in config replaces the defaults');
        $this->assertFalse($differ->isRedacted('BankAccount'), 'Handles are case-sensitive, as Craft’s are');
    }

    public function testTheDefaultRedactListCoversCraftsSecrets(): void
    {
        foreach (['password', 'newPassword', 'verificationCode', 'authKey'] as $name) {
            $this->assertTrue((new Differ(['settings' => new Settings()]))->isRedacted($name), $name);
        }
    }

    /**
     * BR-9's defaults, per core type.
     */
    public function testEachCoreTypeTracksBr9sAttributes(): void
    {
        $expected = [
            Entry::class => ['title', 'slug', 'enabled', 'enabledForSite', 'postDate', 'expiryDate', 'authorIds', 'typeId', 'sectionId'],
            User::class => ['email', 'username', 'fullName', 'firstName', 'lastName', 'admin', 'photoId'],
            Asset::class => ['title', 'filename', 'folderId', 'volumeId', 'alt'],
            Category::class => ['title', 'slug', 'enabled'],
            Tag::class => ['title', 'slug', 'enabled'],
            Address::class => [],
        ];

        foreach ($expected as $type => $attributes) {
            $this->assertSame($attributes, array_keys(Differ::defaultTrackedAttributes($type)), $type);
        }
    }

    /**
     * Every column names a table and a column; everything else reads its own stored value.
     */
    public function testEveryDefaultIsAColumnOrAReader(): void
    {
        foreach ([Entry::class, User::class, Asset::class, Category::class, Tag::class] as $type) {
            foreach (Differ::defaultTrackedAttributes($type) as $name => $source) {
                $this->assertTrue(
                    $source instanceof Closure || preg_match('/^[a-z_]+\.[A-Za-z]+$/', $source) === 1,
                    "$type::$name",
                );
            }
        }
    }

    public function testTheEventIsRaisedOncePerTypeAndItsChangesKept(): void
    {
        $differ = new Differ(['settings' => new Settings()]);
        $raised = 0;
        $differ->on(Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, function(DefineTrackedAttributesEvent $event) use (&$raised) {
            $raised++;
            unset($event->attributes['slug']);
            $event->attributes['uri'] = 'elements_sites.uri';
        });

        $differ->trackedAttributes(Entry::class);
        $attributes = $differ->trackedAttributes(Entry::class);

        $this->assertSame(1, $raised);
        $this->assertArrayNotHasKey('slug', $attributes);
        $this->assertSame('elements_sites.uri', $attributes['uri']);
    }

    public function testThePluginRegistersTheDiffer(): void
    {
        $this->assertInstanceOf(Differ::class, Auditor::getInstance()->getDiffer());
    }
}
