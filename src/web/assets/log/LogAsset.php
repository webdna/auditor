<?php

namespace webdna\auditor\web\assets\log;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The log screen's behaviour: a row's slideout, older pages, and "show more" on long values.
 *
 * @since 1.0.0
 */
class LogAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['log.js'];
        $this->css = ['log.css'];

        parent::init();
    }
}
