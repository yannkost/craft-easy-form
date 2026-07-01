<?php

namespace yannkost\easyform\assets;

use craft\web\AssetBundle;

/**
 * The bundled front-end form stylesheet, kept separate from FormAssetBundle so
 * it can be skipped when default styles are turned off.
 */
class FormStyleAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@yannkost/easyform/assets/dist';

        $this->css = [
            'form-render.css',
        ];

        parent::init();
    }
}
