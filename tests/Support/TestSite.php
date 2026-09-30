<?php

namespace webdna\auditor\tests\Support;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets as AssetsField;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\UserGroup;
use craft\models\Volume;
use RuntimeException;

/**
 * The test site of spec §7, built through Craft's own services.
 *
 * The harness wraps each test in a transaction and rolls it back, so a test that needs the content
 * model builds it in `_before()` and it is gone afterwards; nothing is kept in project config
 * files. Only the volume's files live outside the database, in the system temp directory (Craft
 * refuses a local filesystem inside its storage path), and {@see removeFiles()} clears them.
 *
 * Handles avoid Craft's reserved words — `link` is one, hence the Link field is `website`.
 *
 * Not built: a CKEditor field — CKEditor is a plugin the harness does not install. It stores an
 * HTML string, as Plain Text does.
 */
class TestSite
{
    /**
     * Field handle => field, for the `pages` layout.
     *
     * @var array<string, FieldInterface>
     */
    public array $fields = [];

    public Section $pages;

    public Section $tree;

    public EntryType $page;

    public EntryType $block;

    public Volume $volume;

    public static function build(): self
    {
        $site = new self();
        $site->buildVolume();
        $site->buildFields();
        $site->buildSections();
        $site->buildGroups();

        return $site;
    }

    /**
     * A saved entry in `pages`, with the given field values.
     *
     * @param array<string, mixed> $values
     */
    public function entry(string $title, array $values = [], ?User $author = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->pages->id,
            'typeId' => $this->page->id,
            'title' => $title,
        ]);
        if ($author) {
            $entry->setAuthor($author);
        }
        $entry->setFieldValues($values);
        $this->save($entry);

        return $entry;
    }

    /**
     * A saved asset in the local volume, holding a few bytes of text.
     */
    public function asset(string $filename): Asset
    {
        $temp = tempnam(sys_get_temp_dir(), 'auditor');
        file_put_contents($temp, 'auditor test file');

        $asset = new Asset([
            'tempFilePath' => $temp,
            'filename' => $filename,
            'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($this->volume->id)?->id,
            'volumeId' => $this->volume->id,
            'avoidFilenameConflicts' => true,
        ]);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $this->save($asset);

        return $asset;
    }

    public function user(string $username, bool $admin = false): User
    {
        $user = new User([
            'username' => $username,
            'email' => "$username@example.com",
            'admin' => $admin,
        ]);
        $this->save($user);

        return $user;
    }

    public static function removeFiles(): void
    {
        FileHelper::removeDirectory(self::volumePath());
    }

    public function save(\craft\base\ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            throw new RuntimeException('Test element did not save: ' . implode(' ', $element->getFirstErrors()));
        }
    }

    private static function volumePath(): string
    {
        return sys_get_temp_dir() . '/auditor-test-volume';
    }

    private function buildVolume(): void
    {
        $fs = new Local(['name' => 'Test files', 'handle' => 'testFiles', 'path' => self::volumePath()]);
        $this->ok(Craft::$app->getFs()->saveFilesystem($fs), $fs);

        $this->volume = new Volume(['name' => 'Test volume', 'handle' => 'testVolume', 'fsHandle' => 'testFiles']);
        $this->ok(Craft::$app->getVolumes()->saveVolume($this->volume), $this->volume);
    }

    private function buildFields(): void
    {
        $blockText = $this->field(new PlainText(['name' => 'Block text', 'handle' => 'blockText']));
        $this->block = $this->entryType('block', 'Block', [$blockText]);

        $this->fields = [
            'plainText' => $this->field(new PlainText(['name' => 'Plain text', 'handle' => 'plainText'])),
            'number' => $this->field(new Number(['name' => 'Number', 'handle' => 'number', 'decimals' => 2])),
            'dropdown' => $this->field(new Dropdown(['name' => 'Dropdown', 'handle' => 'dropdown', 'options' => [
                ['label' => 'Red', 'value' => 'red'],
                ['label' => 'Blue', 'value' => 'blue'],
            ]])),
            'multiSelect' => $this->field(new MultiSelect(['name' => 'Multi-select', 'handle' => 'multiSelect', 'options' => [
                ['label' => 'One', 'value' => 'one'],
                ['label' => 'Two', 'value' => 'two'],
                ['label' => 'Three', 'value' => 'three'],
            ]])),
            'lightswitch' => $this->field(new Lightswitch(['name' => 'Lightswitch', 'handle' => 'lightswitch'])),
            'date' => $this->field(new Date(['name' => 'Date', 'handle' => 'date', 'showTime' => true])),
            'related' => $this->field(new EntriesField(['name' => 'Related', 'handle' => 'related'])),
            'files' => $this->field(new AssetsField(['name' => 'Files', 'handle' => 'files'])),
            'money' => $this->field(new Money(['name' => 'Money', 'handle' => 'money', 'currency' => 'GBP'])),
            'website' => $this->field(new Link(['name' => 'Website', 'handle' => 'website'])),
            'table' => $this->field(new Table(['name' => 'Table', 'handle' => 'table', 'columns' => [
                'col1' => ['heading' => 'Name', 'handle' => 'name', 'type' => 'singleline'],
                'col2' => ['heading' => 'Count', 'handle' => 'count', 'type' => 'number'],
            ]])),
            'blocks' => $this->field(new Matrix(['name' => 'Blocks', 'handle' => 'blocks', 'entryTypes' => [$this->block]])),
            'secret' => $this->field(new PlainText(['name' => 'Secret', 'handle' => 'secret'])),
        ];
    }

    private function buildSections(): void
    {
        $this->page = $this->entryType('page', 'Page', array_values($this->fields));
        $this->pages = $this->section('pages', 'Pages', Section::TYPE_CHANNEL, $this->page);

        $node = $this->entryType('node', 'Node', []);
        $this->tree = $this->section('tree', 'Tree', Section::TYPE_STRUCTURE, $node);
    }

    private function buildGroups(): void
    {
        foreach (['editors' => 'Editors', 'members' => 'Members'] as $handle => $name) {
            $group = new UserGroup(['name' => $name, 'handle' => $handle]);
            $this->ok(Craft::$app->getUserGroups()->saveGroup($group), $group);
        }
    }

    private function field(FieldInterface $field): FieldInterface
    {
        $this->ok(Craft::$app->getFields()->saveField($field), $field);

        return $field;
    }

    /**
     * @param FieldInterface[] $fields
     */
    private function entryType(string $handle, string $name, array $fields): EntryType
    {
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([
            new EntryTitleField(),
            ...array_map(fn(FieldInterface $field) => new CustomField($field), $fields),
        ]);
        $layout->setTabs([$tab]);

        $type = new EntryType(['name' => $name, 'handle' => $handle]);
        $type->setFieldLayout($layout);
        $this->ok(Craft::$app->getEntries()->saveEntryType($type), $type);

        return $type;
    }

    private function section(string $handle, string $name, string $type, EntryType $entryType): Section
    {
        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => $type,
            'maxAuthors' => 2,
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                    'hasUrls' => false,
                    'enabledByDefault' => true,
                ]),
            ],
        ]);
        $section->setEntryTypes([$entryType]);
        $this->ok(Craft::$app->getEntries()->saveSection($section), $section);

        return $section;
    }

    private function ok(bool $saved, object $model): void
    {
        if (!$saved) {
            $errors = method_exists($model, 'getFirstErrors') ? $model->getFirstErrors() : [];
            throw new RuntimeException(sprintf('Test site: %s did not save: %s', $model::class, implode(' ', $errors)));
        }
    }
}
