<?php

namespace yannkost\easyform\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Form;

/**
 * Form Field
 *
 * Allows selecting a form from a dropdown
 */
class FormField extends Field implements PreviewableFieldInterface
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('easy-form', 'Form');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        // Reuse the plugin's monochrome brand mark (rendered with currentColor).
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        // Get all forms
        $forms = EasyForm::getInstance()->forms->getAllForms();

        // Build options array
        $options = [
            '' => Craft::t('easy-form', 'Select a form...'),
        ];

        foreach ($forms as $form) {
            $options[$form->id] = $form->name;
        }

        // Extract the ID from the value if it's a Form model
        $selectedId = null;
        if ($value instanceof Form) {
            $selectedId = $value->id;
        } elseif (is_numeric($value)) {
            $selectedId = $value;
        }

        return Cp::selectHtml([
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $selectedId,
            'options' => $options,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof Form) {
            return $value;
        }

        if (is_numeric($value)) {
            return EasyForm::getInstance()->forms->getFormById((int) $value);
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof Form) {
            return $value->id;
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        // Show the selected form's name in element index columns and cards.
        return $value instanceof Form ? Html::encode($value->name) : '';
    }

    /**
     * @inheritdoc
     */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        // Index the form name so elements are findable by their selected form.
        return $value instanceof Form ? $value->name : '';
    }
}
