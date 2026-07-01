<?php

namespace yannkost\easyform\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Form Builder asset bundle
 */
class FormBuilderAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@yannkost/easyform/assets/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'Sortable.min.js',
            'form-builder.js',
        ];

        $this->jsOptions = [
            'type' => 'module',
        ];

        $this->css = [
            'form-builder.css',
        ];

        parent::init();
    }
}
