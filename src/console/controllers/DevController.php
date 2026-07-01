<?php

namespace yannkost\easyform\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Form;
use yannkost\easyform\services\FormSchemaService;
use yii\console\ExitCode;

/**
 * Development-only seeders for forms and submissions. Guarded by devMode so they
 * can never run in production (override with --force at your own risk).
 *
 *   php craft easy-form/dev/seed-forms
 *   php craft easy-form/dev/seed-forms --random=20
 *   php craft easy-form/dev/seed-submissions --form=e2eContact --count=2000 --days=90
 */
class DevController extends Controller
{
    public bool $force = false;
    public ?string $form = null;
    public int $count = 100;
    public int $random = 0;
    public int $days = 90;

    private const FIRST_NAMES = ['Jane', 'Bob', 'Alice', 'Pierre', 'Maria', 'Ken', 'Sofia', 'Omar', 'Lena', 'Yuki'];
    private const LAST_NAMES = ['Doe', 'Smith', 'Martin', 'Garcia', 'Tanaka', 'Khan', 'Rossi', 'Novak', 'Dubois', 'Kim'];
    private const WORDS = ['hello', 'please', 'thanks', 'question', 'order', 'support', 'feedback', 'urgent', 'info', 'quote'];
    private const SOURCES = ['newsletter', 'google', 'twitter', 'direct', 'referral'];

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
        if ($actionID === 'seed-forms') {
            $options[] = 'random';
        }
        if ($actionID === 'seed-submissions') {
            $options[] = 'form';
            $options[] = 'count';
            $options[] = 'days';
        }
        return $options;
    }

    private function guard(): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode && !$this->force) {
            $this->stderr("Refusing to seed: devMode is off (use --force to override).\n");
            return false;
        }
        return true;
    }

    /**
     * Import the bundled form fixtures (stable handles, idempotent) and
     * optionally generate --random=N additional forms.
     */
    public function actionSeedForms(): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        $forms = EasyForm::getInstance()->forms;
        $dir = dirname(__DIR__, 3) . '/tests/fixtures/forms';
        $created = 0;

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = Json::decodeIfJson(file_get_contents($file) ?: '');
            $def = is_array($data) ? ($data['form'] ?? null) : null;
            if (!is_array($def) || empty($def['handle'])) {
                $this->stderr("Skipping invalid fixture: $file\n");
                continue;
            }

            // Idempotent: replace any existing form with this handle.
            $existing = $forms->getFormByHandle($def['handle']);
            if ($existing) {
                EasyForm::getInstance()->submissions->deleteSubmissionsByFormId($existing->id);
                $forms->deleteFormById($existing->id);
            }

            $form = $this->formFromDefinition($def, $def['handle']);
            if ($forms->saveForm($form)) {
                $this->stdout("Seeded form: {$form->handle}\n");
                $created++;
            } else {
                $this->stderr("Failed: {$def['handle']}: " . implode(', ', $form->getFirstErrors()) . "\n");
            }
        }

        for ($i = 0; $i < $this->random; $i++) {
            $form = $this->randomForm();
            if ($forms->saveForm($form)) {
                $created++;
            }
        }

        $this->stdout("Done. {$created} form(s) seeded.\n");
        return ExitCode::OK;
    }

    /**
     * Bulk-generate submissions for a form, with varied data, status and dates.
     */
    public function actionSeedSubmissions(): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }
        if (empty($this->form)) {
            $this->stderr("Provide --form=<handle>.\n");
            return ExitCode::USAGE;
        }

        $form = EasyForm::getInstance()->forms->getFormByHandle($this->form);
        if (!$form) {
            $this->stderr("Form not found: {$this->form}\n");
            return ExitCode::USAGE;
        }

        $site = Craft::$app->sites->getPrimarySite();
        $fields = $form->getFields();
        $statuses = ['pending', 'pending', 'pending', 'approved', 'approved', 'spam', 'archived'];
        $columns = [
            'formId', 'formHandle', 'formName', 'siteId', 'data', 'fieldSnapshot',
            'primaryEmail', 'searchCol1', 'searchCol2', 'searchCol3', 'userId', 'ipAddress', 'userAgent',
            'status', 'spamScore', 'honeypotValue', 'isTest', 'dateCreated', 'dateUpdated', 'dateDeleted', 'uid',
        ];

        $batch = [];
        $inserted = 0;
        for ($i = 0; $i < $this->count; $i++) {
            $raw = [];
            foreach ($fields as $f) {
                $raw[$f['handle']] = $this->fakeValue($f);
            }
            $built = EasyForm::getInstance()->submissionData->build($form, $raw, null, $site->handle);
            $created = gmdate('Y-m-d H:i:s', time() - random_int(0, $this->days * 86400));

            $batch[] = [
                $form->id, $form->handle, $form->name, $site->id,
                // Pass arrays — Db::batchInsert encodes json columns once.
                $built['data'], $built['fieldSnapshot'],
                $built['promoted']['primaryEmail'], $built['promoted']['searchCol1'], $built['promoted']['searchCol2'], $built['promoted']['searchCol3'],
                null, null, null,
                $statuses[array_rand($statuses)], null, null, 0,
                $created, $created, null, StringHelper::UUID(),
            ];

            if (count($batch) >= 500) {
                Db::batchInsert('{{%easyform_submissions}}', $columns, $batch);
                $inserted += count($batch);
                $batch = [];
                $this->stdout("  inserted {$inserted}…\r");
            }
        }
        if (!empty($batch)) {
            Db::batchInsert('{{%easyform_submissions}}', $columns, $batch);
            $inserted += count($batch);
        }

        $this->stdout("\nSeeded {$inserted} submission(s) for {$form->handle}.\n");
        return ExitCode::OK;
    }

    // Helpers -----------------------------------------------------------------

    private function formFromDefinition(array $def, string $handle): Form
    {
        $form = new Form();
        $form->name = (string) $def['name'];
        $form->handle = $handle;
        $form->description = $def['description'] ?? null;
        $form->fieldLayout = is_array($def['fieldLayout'] ?? null) ? $def['fieldLayout'] : EasyForm::getInstance()->formSchema->createEmptyLayout();
        $form->settings = is_array($def['settings'] ?? null) ? $def['settings'] : [];
        $form->siteSuccessMessages = $form->settings['siteSuccessMessages'] ?? [];
        $form->siteErrorMessages = $form->settings['siteErrorMessages'] ?? [];
        // Submit button label is persisted from the model property (not settings),
        // so set it here or saveForm would write null over a settings value.
        $form->submitButtonLabel = $def['submitButtonLabel'] ?? ($form->settings['submitButtonLabel'] ?? null);
        $form->siteSubmitButtonLabels = $def['siteSubmitButtonLabels'] ?? ($form->settings['siteSubmitButtonLabels'] ?? []);
        $form->notificationSettings = $def['notificationSettings'] ?? null;
        $form->enabled = (bool) ($def['enabled'] ?? true);
        // Honor a requested CAPTCHA provider, but only if it exists in this
        // install (mirrors the import path); otherwise leave it off.
        $provider = $def['captchaProvider'] ?? null;
        $form->captchaProvider = ($provider && EasyForm::getInstance()->captcha->getProvider($provider)) ? $provider : null;
        $form->successMessage = $def['successMessage'] ?? null;
        $form->redirectUrl = $def['redirectUrl'] ?? null;
        $form->hideFormOnSuccess = (bool) ($def['hideFormOnSuccess'] ?? false);
        $form->keepSuccessMessage = (bool) ($def['keepSuccessMessage'] ?? true);
        $form->successMessageDuration = max(1, (int) ($def['successMessageDuration'] ?? 5));
        $form->saveSpamSubmissions = (bool) ($def['saveSpamSubmissions'] ?? false);
        $form->maxSubmissionsPerUser = $def['maxSubmissionsPerUser'] ?? null;
        $form->rateLimit = max(0, (int) ($def['rateLimit'] ?? 0));
        $form->rateLimitWindow = max(1, (int) ($def['rateLimitWindow'] ?? 60));
        $form->allowUrlPrefill = (bool) ($def['allowUrlPrefill'] ?? false);
        $form->showStepIndicator = (bool) ($def['showStepIndicator'] ?? false);
        $form->validateSteps = (bool) ($def['validateSteps'] ?? true);
        $form->webhookUrl = $def['webhookUrl'] ?? null;
        $form->webhookPayload = ($def['webhookPayload'] ?? 'full') === 'data' ? 'data' : 'full';
        return $form;
    }

    private function randomForm(): Form
    {
        $types = ['text', 'email', 'textarea', 'number', 'tel', 'url', 'date', 'select', 'checkboxes'];
        $n = random_int(1, 5);
        $fields = [];
        for ($i = 0; $i < $n; $i++) {
            $type = $types[array_rand($types)];
            $field = [
                'id' => 'rf_' . $i,
                'type' => $type,
                'label' => ucfirst($type) . ' ' . ($i + 1),
                'handle' => $type . $i,
                'required' => (bool) random_int(0, 1),
            ];
            if (in_array($type, ['select', 'checkboxes'], true)) {
                $field['options'] = "a:A\nb:B\nc:C";
                $field['multiple'] = $type === 'checkboxes';
            }
            $fields[] = $field;
        }

        $form = new Form();
        $suffix = StringHelper::randomString(5);
        $form->name = 'Random ' . $suffix;
        $form->handle = 'rnd' . $suffix;
        $form->fieldLayout = [
            'schemaVersion' => FormSchemaService::CURRENT_VERSION,
            'extraFieldPolicy' => 'allowListed',
            'pages' => [['id' => 'page_1', 'label' => 'Page 1', 'rows' => [['id' => 'row_1', 'fields' => $fields]]]],
            'frontendFields' => [],
            'promotedFields' => [],
        ];
        $form->enabled = true;
        return $form;
    }

    private function fakeValue(array $field): mixed
    {
        $type = $field['type'] ?? 'text';
        switch ($type) {
            case 'email':
                return strtolower(self::FIRST_NAMES[array_rand(self::FIRST_NAMES)]) . random_int(1, 999) . '@example.com';
            case 'number':
                return (string) random_int((int) ($field['min'] ?? 1), (int) ($field['max'] ?? 100));
            case 'tel':
                return '+1 555 ' . random_int(1000000, 9999999);
            case 'url':
                return 'https://example.com/' . self::WORDS[array_rand(self::WORDS)];
            case 'date':
                return gmdate('Y-m-d', time() - random_int(0, 3650 * 86400));
            case 'textarea':
                return ucfirst(self::WORDS[array_rand(self::WORDS)]) . ' ' . self::WORDS[array_rand(self::WORDS)] . ' ' . self::WORDS[array_rand(self::WORDS)];
            case 'select':
                return $this->randomOption($field, false);
            case 'checkboxes':
                return $this->randomOption($field, true);
            case 'agree':
                // Mirror the render fallback: primary site's checked value, else "Yes".
                $checked = is_array($field['siteAgreeChecked'] ?? null) ? $field['siteAgreeChecked'] : [];
                $primary = trim(Craft::$app->sites->getPrimarySite()->handle);
                return trim((string) ($checked[$primary] ?? '')) ?: 'Yes';
            case 'hidden':
                return $field['defaultValue'] ?? 'seed';
            case 'file':
                return null;
            default:
                $name = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
                return ($field['handle'] ?? '') === 'name' || str_contains(($field['handle'] ?? ''), 'name')
                    ? $name . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)]
                    : ucfirst(self::WORDS[array_rand(self::WORDS)]);
        }
    }

    private function randomOption(array $field, bool $multiple): mixed
    {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($field['options'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $values[] = trim(explode(':', $line, 2)[0]);
        }
        if (empty($values)) {
            return $multiple ? [] : '';
        }
        if (!$multiple) {
            return $values[array_rand($values)];
        }
        shuffle($values);
        return array_slice($values, 0, random_int(1, count($values)));
    }
}
