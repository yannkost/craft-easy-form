<?php

namespace yannkost\easyform\twigextensions;

use Craft;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use yannkost\easyform\models\Form;
use yannkost\easyform\EasyForm;

/**
 * Twig Extension for EasyForm
 */
class EasyFormTwigExtension extends AbstractExtension
{
    /**
     * @inheritdoc
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('easyForm', [$this, 'renderForm'], ['is_safe' => ['html']]),
            new TwigFunction('easyFormField', [$this, 'renderField'], ['is_safe' => ['html']]),
            new TwigFunction('easyFormLayout', [$this, 'getLayout']),
            new TwigFunction('easyFormCaptchaProviders', [$this, 'configuredCaptchaProviders']),
            new TwigFunction('easyFormCaptcha', [$this, 'captchaProvider']),
            new TwigFunction('easyFormDebugEnabled', [EasyForm::class, 'isDebugEnabled']),
            new TwigFunction('easyFormPresentationalTypes', [$this, 'presentationalTypes']),
        ];
    }

    /**
     * The render-only (valueless) field types, sourced from the single
     * canonical list on FormSchemaService so templates never re-hardcode it.
     *
     * @return string[]
     */
    public function presentationalTypes(): array
    {
        return \yannkost\easyform\services\FormSchemaService::PRESENTATIONAL_TYPES;
    }

    /**
     * Resolve a form from a handle, id, or Form model.
     */
    private function resolveForm($form): ?Form
    {
        if (is_string($form)) {
            return EasyForm::getInstance()->forms->getFormByHandle($form);
        }
        if (is_numeric($form)) {
            return EasyForm::getInstance()->forms->getFormById((int) $form);
        }
        if ($form instanceof Form) {
            return $form;
        }
        return null;
    }

    /**
     * Returns the Form model (not rendered HTML) so templates can build their
     * own markup by looping the layout — `form.pages` → `page.rows` → `row.fields`.
     *
     * ```twig
     * {% set form = easyFormLayout('contact') %}
     * {% for page in form.pages %}{% for row in page.rows %}{% for field in row.fields %}…{% endfor %}{% endfor %}{% endfor %}
     * ```
     */
    public function getLayout($form): ?Form
    {
        return $this->resolveForm($form);
    }

    /**
     * Configured CAPTCHA providers (handle => provider). Uses the loaded plugin
     * singleton — do not rely on craft.easyForm, which may be a fresh instance.
     */
    public function configuredCaptchaProviders(): array
    {
        return EasyForm::getInstance()->captcha->getConfiguredProviders();
    }

    /**
     * Resolve a CAPTCHA provider by handle (or null).
     */
    public function captchaProvider(?string $handle)
    {
        return EasyForm::getInstance()->captcha->getProvider($handle);
    }

    /**
     * Render a single field's default markup (wrapper, label, help text and the
     * type-specific input), identical to how it renders inside a full form.
     *
     * Pair it with easyFormLayout() to hand-roll a form's markup while still
     * getting each field's stock rendering — or pass an ad-hoc field hash:
     *
     * ```twig
     * {% set form = easyFormLayout('contact') %}
     * <form …>
     *   {% for page in form.pages %}{% for row in page.rows %}{% for field in row.fields %}
     *     {{ easyFormField(field) }}
     *   {% endfor %}{% endfor %}{% endfor %}
     * </form>
     *
     * {{ easyFormField({ type: 'email', handle: 'email', label: 'Email', required: true }) }}
     * ```
     *
     * The field renders only its own markup — the surrounding `<form>` (and the
     * asset bundle that powers AJAX submit, validation and conditions) is the
     * caller's responsibility; use easyForm() if you want all of that wired up.
     *
     * @param mixed $field   A Form model's field, or a hash with at least `type` and `handle`.
     * @param array $options Render options. `formId` (default 'easy-form') prefixes the
     *                       input id; `values` is a per-handle map of value overrides.
     */
    public function renderField($field, array $options = []): string
    {
        if (empty($field)) {
            return '';
        }

        // When given a plain hash (rather than a real form field), backfill the
        // keys the shared templates read as an eager `|default(field.x)` argument
        // — those throw on a missing key under strict_variables (devMode), unlike
        // the `field.x|default()` form. Real fields always carry these, so this
        // only matters for ad-hoc hashes: easyFormField({ type: 'text', handle: 'foo' }).
        if (is_array($field)) {
            $field += [
                'type' => 'text',
                'handle' => '',
                'label' => '',
                'content' => '',
                'helpText' => '',
                'defaultValue' => '',
                'fieldId' => '',
            ];
        }

        $formId = $options['formId'] ?? 'easy-form';

        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);

        try {
            return Craft::$app->view->renderTemplate('easy-form/forms/_field', [
                'field' => $field,
                'formId' => $formId,
                'options' => $options,
            ]);
        } catch (\Throwable $e) {
            EasyForm::log('Failed to render field: ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }
            return '';
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }
    }

    /**
     * Render a form by handle or ID
     *
     * @param string|int|Form $form Form handle, ID, or Form model
     * @param array $options Additional options for rendering
     * @return string
     */
    public function renderForm($form, array $options = []): string
    {
        // Get the form model
        $formModel = $this->resolveForm($form);

        if (!$formModel) {
            return '';
        }

        // Check if form is enabled
        if (!$formModel->enabled) {
            return '';
        }

        // Merge default options. `includeStyles` defaults to the global setting
        // but can be overridden per render (e.g. easyForm('x', { includeStyles: false })).
        // The submit button label defaults to the form's configured label for the
        // current site (falling back to "Submit"); an explicit submitButtonText
        // passed to easyForm() still wins via array_merge.
        $currentSiteHandle = Craft::$app->getSites()->getCurrentSite()->handle;
        $options = array_merge([
            'class' => 'easy-form',
            'submitButtonText' => $formModel->getSubmitButtonLabelForSite($currentSiteHandle),
            'submitButtonClass' => 'btn submit',
            'includeStyles' => (bool) EasyForm::getInstance()->getSettings()->includeDefaultStyles,
        ], $options);

        // Set the template mode to CP to access plugin templates
        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);

        try {
            // Render the form template
            $html = Craft::$app->view->renderTemplate('easy-form/forms/_render', [
                'form' => $formModel,
                'options' => $options,
            ]);
        } catch (\Throwable $e) {
            EasyForm::log('Failed to render form "' . $formModel->handle . '": ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            // In dev, surface the error; in production, fail soft so one broken
            // form doesn't take down the whole page.
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }
            $html = '';
        } finally {
            // Restore the original template mode
            Craft::$app->view->setTemplateMode($oldMode);
        }

        return $html;
    }
}
