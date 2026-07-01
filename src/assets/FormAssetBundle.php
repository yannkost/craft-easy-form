<?php

namespace yannkost\easyform\assets;

use craft\web\AssetBundle;

/**
 * Asset bundle for front-end forms
 */
class FormAssetBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@yannkost/easyform/assets/dist';

        $this->js = [
            'form-submit.js',
        ];

        // The bundled stylesheet (form-render.css) is registered separately via
        // FormStyleAsset so it can be toggled off (includeDefaultStyles setting /
        // the `includeStyles` render option).

        parent::init();
    }
}
