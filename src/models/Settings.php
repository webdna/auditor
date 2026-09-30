<?php

namespace webdna\auditor\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * The plugin's settings, set only in `config/auditor.php` — there is no settings screen, so they
 * deploy with the rest of the site's code.
 *
 * Craft applies the config file over this model and rejects a key it does not declare, so every
 * key the file may carry is a property here.
 *
 * @since 1.0.0
 */
class Settings extends Model
{
    public const CONTEXT_CP = 'cp';
    public const CONTEXT_SITE = 'site';
    public const CONTEXT_CONSOLE = 'console';
    public const CONTEXT_QUEUE = 'queue';

    public const CONTEXTS = [
        self::CONTEXT_CP,
        self::CONTEXT_SITE,
        self::CONTEXT_CONSOLE,
        self::CONTEXT_QUEUE,
    ];

    /**
     * False turns all recording off (BR-5).
     */
    public bool $enabled = true;

    /**
     * The contexts recorded; default all four (BR-5).
     *
     * @var string[]
     */
    public array $contexts = self::CONTEXTS;

    /**
     * Element classes never recorded.
     *
     * @var class-string<ElementInterface>[]
     */
    public array $excludeElementTypes = [];

    /**
     * Field handles and attribute names recorded as `{redacted: true}` (BR-10). Fields of Craft's
     * Password type are redacted whether listed or not.
     *
     * @var string[]
     */
    public array $redact = ['password', 'newPassword', 'verificationCode', 'authKey'];

    /**
     * Rows older than this are removed; 0 keeps them for ever (BR-25).
     */
    public int $retentionDays = 365;

    /**
     * One Craft bulk operation touching more top-level elements than this is one batch row (BR-13).
     */
    public int $bulkThreshold = 10;

    /**
     * Record a batch as per-element rows instead of one batch row (BR-14).
     */
    public bool $recordBulkDiffs = false;

    /**
     * Write a row for a save that changed nothing (BR-7).
     */
    public bool $recordNoChangeSaves = false;

    /**
     * Record sign-ins made by the remember-me cookie (BR-16).
     */
    public bool $recordCookieLogins = false;

    /**
     * Whether anything is recorded in the given context (BR-5).
     */
    public function records(string $context): bool
    {
        return $this->enabled && in_array($context, $this->contexts, true);
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['enabled', 'recordBulkDiffs', 'recordNoChangeSaves', 'recordCookieLogins'], 'boolean'],
            [['retentionDays'], 'integer', 'min' => 0],
            [['bulkThreshold'], 'integer', 'min' => 1],
            [['contexts'], 'each', 'rule' => ['in', 'range' => self::CONTEXTS, 'strict' => true]],
            [['redact'], 'each', 'rule' => ['string']],
            [['excludeElementTypes'], 'validateElementTypes'],
        ];
    }

    /**
     * Each excluded type must be an element class, so a typo cannot silently exclude nothing.
     */
    public function validateElementTypes(string $attribute): void
    {
        foreach ($this->$attribute as $type) {
            if (!is_string($type) || !is_subclass_of($type, ElementInterface::class)) {
                $this->addError($attribute, sprintf('“%s” is not an element type.', is_string($type) ? $type : gettype($type)));
            }
        }
    }
}
