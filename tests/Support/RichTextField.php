<?php

namespace webdna\auditor\tests\Support;

use craft\htmlfield\HtmlField;

/**
 * A rich-text field as CKEditor's is: built on Craft's shared HTML field, storing an HTML string.
 *
 * CKEditor's own class cannot stand in: it belongs to a plugin, and Craft loads a field whose
 * plugin is not installed as a `MissingField`. This one belongs to no plugin.
 */
class RichTextField extends HtmlField
{
    public static function displayName(): string
    {
        return 'Rich text';
    }
}
