<?php

namespace yannkost\easyform\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yannkost\easyform\models\Form;
use yannkost\easyform\EasyForm;

/**
 * Forms controller
 */
class FormsController extends Controller
{
    use PaginatesTrait;

    /**
     * @var array|int|bool The CSRF token + client-error endpoints are used by the
     * frontend submit script and must be reachable anonymously; all other actions
     * require auth.
     */
    protected array|int|bool $allowAnonymous = ['get-csrf-token', 'log-client-error'];

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // The client-error beacon is fire-and-forget and only active in debug;
        // it doesn't carry a CSRF token.
        if ($action->id === 'log-client-error') {
            $this->enableCsrfValidation = false;
        }
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Everything except the public frontend helpers needs form management.
        if (!in_array($action->id, ['get-csrf-token', 'log-client-error'], true)) {
            $this->requirePermission('easy-form:manageForms');
        }
        return true;
    }

    /**
     * Logs a client-side (JavaScript) error to the plugin log file.
     * No-op unless EASY_FORM_DEBUG is enabled, so it is inert in production.
     */
    public function actionLogClientError(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!EasyForm::isDebugEnabled()) {
            return $this->asJson(['ok' => false]);
        }

        $request = Craft::$app->getRequest();
        $message = substr((string) $request->getBodyParam('message', ''), 0, 1000);
        $context = substr((string) $request->getBodyParam('context', ''), 0, 200);
        $formHandle = substr((string) $request->getBodyParam('formHandle', ''), 0, 100);

        EasyForm::log("[client] form={$formHandle} context={$context}: {$message}", 'warning');

        return $this->asJson(['ok' => true]);
    }

    /**
     * Lists all forms
     */
    public function actionIndex(): Response
    {
        $search = trim((string) Craft::$app->getRequest()->getQueryParam('search', ''));
        $limit = self::resolvePageSize(Craft::$app->getRequest()->getQueryParam('limit'));
        $formsService = EasyForm::getInstance()->forms;

        $query = $formsService->getFormsQuery($search);
        $pages = new \yii\data\Pagination([
            'totalCount' => (int) $query->count(),
            'defaultPageSize' => $limit,
            'pageSize' => $limit,
        ]);

        $records = $query->offset($pages->offset)->limit($pages->limit)->all();
        $forms = array_map([$formsService, 'formFromRecord'], $records);

        $submissionCounts = EasyForm::getInstance()->submissions->getCountsByFormIds(
            array_map(static fn($f) => (int) $f->id, $forms)
        );

        return $this->renderTemplate('easy-form/forms/index', [
            'forms' => $forms,
            'search' => $search,
            'pages' => $pages,
            'limit' => $limit,
            'submissionCounts' => $submissionCounts,
        ]);
    }

    /**
     * Apply a bulk action (enable / disable / delete) to many forms (AJAX).
     */
    public function actionBulk(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $action = $request->getRequiredBodyParam('bulkAction');
        $ids = array_filter(array_map('intval', (array) $request->getBodyParam('ids', [])));

        if (empty($ids)) {
            return $this->asJson(['success' => false, 'error' => 'No forms selected']);
        }

        $forms = EasyForm::getInstance()->forms;
        $affected = 0;

        if ($action === 'delete') {
            // Opt-in: also permanently delete each form's submissions (default is
            // to orphan them). Delete submissions first, while formId still matches.
            $deleteSubmissions = (bool) $request->getBodyParam('deleteSubmissions', false);
            foreach ($ids as $id) {
                $form = $forms->getFormById($id);
                if (!$form) {
                    continue;
                }
                if ($deleteSubmissions) {
                    EasyForm::getInstance()->submissions->deleteSubmissionsByFormId((int) $form->id);
                }
                if ($forms->deleteForm($form)) {
                    $affected++;
                }
            }
        } elseif ($action === 'status') {
            $enabled = (bool) $request->getRequiredBodyParam('enabled');
            foreach ($ids as $id) {
                $form = $forms->getFormById($id);
                if ($form) {
                    $form->enabled = $enabled;
                    if ($forms->saveForm($form)) {
                        $affected++;
                    }
                }
            }
        } else {
            return $this->asJson(['success' => false, 'error' => 'Unknown bulk action']);
        }

        return $this->asJson(['success' => true, 'affected' => $affected]);
    }

    /**
     * Edit or create a form
     */
    public function actionEdit(?int $formId = null, ?Form $form = null): Response
    {
        if ($form === null) {
            if ($formId !== null) {
                $form = EasyForm::getInstance()->forms->getFormById($formId);
                if (!$form) {
                    throw new NotFoundHttpException('Form not found');
                }
            } else {
                $form = new Form();
                $form->fieldLayout = EasyForm::getInstance()->formSchema->createEmptyLayout();

                // Apply global defaults to new forms.
                $settings = EasyForm::getInstance()->getSettings();
                if ($settings->defaultSuccessMessage) {
                    $form->successMessage = $settings->defaultSuccessMessage;
                }
                if ($settings->defaultNotificationEmail) {
                    $form->notificationSettings = [[
                        'name' => Craft::t('easy-form', 'Default notification'),
                        'enabled' => true,
                        'recipients' => [$settings->defaultNotificationEmail],
                        'subject' => Craft::t('easy-form', 'New form submission'),
                    ]];
                }
            }
        }

        return $this->renderTemplate('easy-form/forms/edit', [
            'form' => $form,
            'isNew' => !$form->id,
        ]);
    }

    /**
     * Save a form
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $formId = $request->getBodyParam('formId');

        if ($formId) {
            $form = EasyForm::getInstance()->forms->getFormById($formId);
            if (!$form) {
                throw new NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
        }

        // Set basic attributes
        $form->name = $request->getBodyParam('name');
        $form->handle = $request->getBodyParam('handle');
        $form->description = $request->getBodyParam('description');
        $form->enabled = (bool) $request->getBodyParam('enabled', true);
        // Handle site-specific messages
        $form->siteSuccessMessages = $request->getBodyParam('siteSuccessMessages', []);
        $form->siteErrorMessages = $request->getBodyParam('siteErrorMessages', []);
        // Legacy success message - keep for backward compatibility if needed, or just allow it to be overwritten
        $form->successMessage = $request->getBodyParam('successMessage');
        
        $form->redirectUrl = $request->getBodyParam('redirectUrl');
        // Per-site redirect overrides; blanks are dropped so they fall back to
        // the default redirect URL at submit time.
        $siteRedirectUrls = $request->getBodyParam('siteRedirectUrls', []);
        $form->siteRedirectUrls = is_array($siteRedirectUrls)
            ? array_filter($siteRedirectUrls, fn($v) => is_scalar($v) && trim((string) $v) !== '')
            : [];
        // Submit button label + per-site overrides (blanks dropped so they fall
        // back to the default label, then to the generic "Submit").
        $form->submitButtonLabel = $request->getBodyParam('submitButtonLabel');
        $siteSubmitButtonLabels = $request->getBodyParam('siteSubmitButtonLabels', []);
        $form->siteSubmitButtonLabels = is_array($siteSubmitButtonLabels)
            ? array_filter($siteSubmitButtonLabels, fn($v) => is_scalar($v) && trim((string) $v) !== '')
            : [];
        $form->hideFormOnSuccess = (bool) $request->getBodyParam('hideFormOnSuccess', false);
        $form->keepSuccessMessage = (bool) $request->getBodyParam('keepSuccessMessage', true);
        $form->successMessageDuration = max(1, (int) $request->getBodyParam('successMessageDuration', 5));
        $form->allowUrlPrefill = (bool) $request->getBodyParam('allowUrlPrefill', false);
        $form->showStepIndicator = (bool) $request->getBodyParam('showStepIndicator', false);
        $form->validateSteps = (bool) $request->getBodyParam('validateSteps', true);
        $form->webhookUrl = trim((string) $request->getBodyParam('webhookUrl', '')) ?: null;
        $form->webhookPayload = $request->getBodyParam('webhookPayload') === 'data' ? 'data' : 'full';

        // Per-form file-handling override (stored in the settings JSON blob).
        // When disabled, the form follows the global Easy Form settings.
        $settingsBlob = $form->getSettingsArray();
        $fileOverride = $request->getBodyParam('fileOverride', []);
        if (is_array($fileOverride) && !empty($fileOverride['enabled'])) {
            $settingsBlob['fileOverride'] = [
                'enabled' => true,
                'uploadMode' => ($fileOverride['uploadMode'] ?? '') === 'filesystem' ? 'filesystem' : 'asset',
                'uploadVolumeUid' => (string) ($fileOverride['uploadVolumeUid'] ?? ''),
                'uploadSubfolder' => trim((string) ($fileOverride['uploadSubfolder'] ?? '')),
                'uploadFilesystemPath' => trim((string) ($fileOverride['uploadFilesystemPath'] ?? '')),
                'uploadBaseUrl' => trim((string) ($fileOverride['uploadBaseUrl'] ?? '')),
                'uploadDateSubfolders' => !empty($fileOverride['uploadDateSubfolders']),
                'maxFileSize' => ($fileOverride['maxFileSize'] ?? '') !== '' ? (int) $fileOverride['maxFileSize'] : null,
            ];
        } else {
            unset($settingsBlob['fileOverride']);
        }
        $form->settings = $settingsBlob;
        
        // Spam protection
        $form->saveSpamSubmissions = (bool) $request->getBodyParam('saveSpamSubmissions', false);
        $form->autoApprove = (bool) $request->getBodyParam('autoApprove', false);
        $form->captchaProvider = $request->getBodyParam('captchaProvider') ?: null;
        $form->maxSubmissionsPerUser = $request->getBodyParam('maxSubmissionsPerUser') ?: null;
        $form->rateLimit = max(0, (int) $request->getBodyParam('rateLimit', 0));
        $form->rateLimitWindow = max(1, (int) $request->getBodyParam('rateLimitWindow', 60));

        // Build field layout from pages, rows and fields
        $pages = $request->getBodyParam('pages', []);
        
        // Backward compatibility: if 'rows' is posted instead of 'pages', wrap in a single page
        if (empty($pages)) {
            $rows = $request->getBodyParam('rows', []);
            if (!empty($rows)) {
                $pages = [
                    [
                        'id' => 'page_1',
                        'label' => 'Page 1',
                        'rows' => $rows,
                    ],
                ];
            }
        }

        // Defensive: a malformed POST could send a scalar instead of an array.
        if (!is_array($pages)) {
            $pages = [];
        }

        EasyForm::debug('Received pages data: ' . json_encode($pages));

        // Preserve the existing v3 contract pieces unless explicitly posted.
        $existingLayout = $form->id ? $form->getNormalizedLayout() : [];
        $schemaService = EasyForm::getInstance()->formSchema;

        $policy = $request->getBodyParam('extraFieldPolicy', $existingLayout['extraFieldPolicy'] ?? null);

        $frontendFields = $request->getBodyParam('frontendFields', null);
        if (is_string($frontendFields)) {
            $frontendFields = Json::decodeIfJson($frontendFields);
        }
        if (!is_array($frontendFields)) {
            $frontendFields = $existingLayout['frontendFields'] ?? [];
        }

        $promotedFields = $request->getBodyParam('promotedFields', null);
        if (is_string($promotedFields)) {
            $promotedFields = Json::decodeIfJson($promotedFields);
        }
        if (!is_array($promotedFields)) {
            $promotedFields = $existingLayout['promotedFields'] ?? [];
        }

        $fieldLayout = [
            'schemaVersion' => \yannkost\easyform\services\FormSchemaService::CURRENT_VERSION,
            'extraFieldPolicy' => $schemaService->normalizePolicy($policy),
            'pages' => [],
            'frontendFields' => array_values(array_filter((array) $frontendFields, 'is_array')),
            'promotedFields' => (array) $promotedFields,
        ];

        // Collected, user-facing layout problems (missing/duplicate handles, …) and
        // a form-wide handle registry so we can reject duplicates instead of letting
        // them silently overwrite each other on submit.
        $layoutErrors = [];
        $seenHandles = [];

        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageData = [
                'id' => $page['id'] ?? 'page_' . ($pageIndex + 1),
                'label' => $page['label'] ?? 'Page ' . ($pageIndex + 1),
                'rows' => [],
            ];

            // Page-level conditions
            if (!empty($page['conditions']) && is_array($page['conditions'])) {
                $pageData['conditions'] = $this->buildConditions($page['conditions']);
            }

            // Per-site enable map (site-handle => '1'|'0'); absent = enabled
            // everywhere. Filtered the same way as rows (blanks/non-scalars dropped).
            $pageSiteEnabled = is_array($page['siteEnabled'] ?? null) ? $page['siteEnabled'] : [];
            $pageData['siteEnabled'] = array_filter(
                $pageSiteEnabled,
                fn($v) => is_scalar($v) && trim((string) $v) !== ''
            );

            // Per-page Next/Previous button labels (+ per-site overrides). Blank
            // values are dropped so the front end falls back to "Next"/"Previous".
            $dropBlank = fn($map) => is_array($map)
                ? array_filter($map, fn($v) => is_scalar($v) && trim((string) $v) !== '')
                : [];
            foreach (['nextLabel', 'prevLabel'] as $labelKey) {
                $label = $page[$labelKey] ?? '';
                if (is_scalar($label) && trim((string) $label) !== '') {
                    $pageData[$labelKey] = trim((string) $label);
                }
            }
            $siteNextLabels = $dropBlank($page['siteNextLabels'] ?? []);
            $sitePrevLabels = $dropBlank($page['sitePrevLabels'] ?? []);
            if (!empty($siteNextLabels)) {
                $pageData['siteNextLabels'] = $siteNextLabels;
            }
            if (!empty($sitePrevLabels)) {
                $pageData['sitePrevLabels'] = $sitePrevLabels;
            }

            $rows = $page['rows'] ?? [];
            if (!is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $rowIndex => $row) {
                if (!is_array($row)) {
                    EasyForm::debug("Page $pageIndex, Row $rowIndex is not an array: " . gettype($row));
                    continue;
                }
                
                if (!isset($row['fields']) || !is_array($row['fields'])) {
                    EasyForm::debug("Page $pageIndex, Row $rowIndex has no fields array. Row data: " . json_encode($row));
                    continue;
                }

                $rowData = [
                    'id' => $row['id'] ?? 'row_' . ($pageIndex + 1) . '_' . ($rowIndex + 1),
                    'fields' => [],
                ];

                // Row-level conditions (same shape as page/field conditions).
                if (!empty($row['conditions']) && is_array($row['conditions'])) {
                    $rowData['conditions'] = $this->buildConditions($row['conditions']);
                }

                // Per-site enable map (site-handle => '1'|'0'); absent = enabled
                // everywhere. Rows skip the field-level site* strip loop, so filter
                // blanks/non-scalars here the same way.
                $rowSiteEnabled = is_array($row['siteEnabled'] ?? null) ? $row['siteEnabled'] : [];
                $rowData['siteEnabled'] = array_filter(
                    $rowSiteEnabled,
                    fn($v) => is_scalar($v) && trim((string) $v) !== ''
                );

                foreach ($row['fields'] as $fieldIndex => $field) {
                    if (!is_array($field)) {
                        EasyForm::debug("Field at page $pageIndex, row $rowIndex, field $fieldIndex is not an array");
                        continue;
                    }

                    $type = trim((string) ($field['type'] ?? ''));
                    $handle = trim((string) ($field['handle'] ?? ''));
                    $loc = $this->fieldLocationLabel($pageIndex, $rowIndex, $fieldIndex, (string) ($field['label'] ?? ''));

                    // Misconfigured fields are reported, not silently dropped.
                    if ($type === '') {
                        $layoutErrors[] = Craft::t('easy-form', '{loc} is missing a type and was not saved.', ['loc' => $loc]);
                        continue;
                    }
                    if ($handle === '') {
                        $layoutErrors[] = Craft::t('easy-form', '{loc} is missing a handle — give it a label and save again.', ['loc' => $loc]);
                        continue;
                    }
                    if (isset($seenHandles[$handle])) {
                        $layoutErrors[] = Craft::t('easy-form', 'Duplicate field handle “{handle}” ({loc}). Field handles must be unique.', ['handle' => $handle, 'loc' => $loc]);
                        continue;
                    }
                    $seenHandles[$handle] = true;

                    // Use the trimmed values downstream.
                    $field['type'] = $type;
                    $field['handle'] = $handle;

                    $fieldData = [
                        'id' => $field['id'] ?? 'field_' . $pageIndex . '_' . $rowIndex . '_' . $fieldIndex,
                        'type' => $field['type'],
                        'label' => $field['label'] ?? '',
                        'handle' => $field['handle'],
                        'required' => (bool) ($field['required'] ?? false),
                        'placeholder' => $field['placeholder'] ?? '',
                        'defaultValue' => $field['defaultValue'] ?? '',
                        'siteDefaultValues' => $field['siteDefaultValues'] ?? [],
                        'fieldId' => $field['fieldId'] ?? '',
                        'classList' => $field['classList'] ?? '',
                        'siteLabels' => $field['siteLabels'] ?? [],
                        'siteRequiredMessages' => $field['siteRequiredMessages'] ?? [],
                        'siteUniqueMessages' => $field['siteUniqueMessages'] ?? [],
                        'helpText' => $field['helpText'] ?? '',
                        'siteHelpTexts' => $field['siteHelpTexts'] ?? [],
                        // Per-site enable map (site-handle => '1'|'0'); absent = enabled
                        // everywhere. The site* strip loop below keeps the '1'/'0' flags
                        // and coerces a tampered non-array to [].
                        'siteEnabled' => $field['siteEnabled'] ?? [],
                    ];
                    
                    // Field-level conditions
                    if (!empty($field['conditions']) && is_array($field['conditions'])) {
                        $fieldData['conditions'] = $this->buildConditions($field['conditions']);
                    }
                    
                    // Add validation fields based on type
                    if (in_array($field['type'], ['text', 'textarea', 'tel', 'url'])) {
                        $fieldData['minLength'] = $field['minLength'] ?? '';
                        $fieldData['maxLength'] = $field['maxLength'] ?? '';
                        $fieldData['siteMinLengthMessages'] = $field['siteMinLengthMessages'] ?? [];
                        $fieldData['siteMaxLengthMessages'] = $field['siteMaxLengthMessages'] ?? [];
                    }
                    
                    if ($field['type'] === 'number') {
                        $fieldData['min'] = $field['min'] ?? '';
                        $fieldData['max'] = $field['max'] ?? '';
                        $fieldData['decimals'] = $field['decimals'] ?? '';
                        $fieldData['siteMinMessages'] = $field['siteMinMessages'] ?? [];
                        $fieldData['siteMaxMessages'] = $field['siteMaxMessages'] ?? [];
                    }

                    if ($field['type'] === 'email') {
                        $fieldData['siteInvalidMessages'] = $field['siteInvalidMessages'] ?? [];
                    }

                    // "Must be unique" — only meaningful for single-value scalar fields.
                    if (in_array($field['type'], ['text', 'email', 'tel', 'url', 'number'], true)) {
                        $fieldData['unique'] = (bool) ($field['unique'] ?? false);
                    }

                    if ($field['type'] === 'url') {
                        $fieldData['requireScheme'] = (bool) ($field['requireScheme'] ?? false);
                    }

                    if ($field['type'] === 'file') {
                        $fieldData['maxFileSize'] = $field['maxFileSize'] ?? '';
                        $fieldData['maxTotalSize'] = $field['maxTotalSize'] ?? '';
                        $fieldData['allowMultiple'] = (bool) ($field['allowMultiple'] ?? false);
                        $fieldData['maxFiles'] = $field['maxFiles'] ?? '';
                        $fieldData['allowedFileTypes'] = $field['allowedFileTypes'] ?? '';
                        $fieldData['siteFileSizeMessages'] = $field['siteFileSizeMessages'] ?? [];
                        // Per-site editable hint label templates ({n} / {types} placeholders).
                        $fieldData['siteFileHintPerFile'] = is_array($field['siteFileHintPerFile'] ?? null) ? $field['siteFileHintPerFile'] : [];
                        $fieldData['siteFileHintTotal'] = is_array($field['siteFileHintTotal'] ?? null) ? $field['siteFileHintTotal'] : [];
                        $fieldData['siteFileHintCount'] = is_array($field['siteFileHintCount'] ?? null) ? $field['siteFileHintCount'] : [];
                        $fieldData['siteFileHintTypes'] = is_array($field['siteFileHintTypes'] ?? null) ? $field['siteFileHintTypes'] : [];
                        $fieldData['siteFileHintPrompt'] = is_array($field['siteFileHintPrompt'] ?? null) ? $field['siteFileHintPrompt'] : [];
                    }
                    
                    if (in_array($field['type'], ['select', 'checkboxes'])) {
                        $siteOptions = is_array($field['siteOptions'] ?? null) ? $field['siteOptions'] : [];
                        // The builder keeps the primary site's options in
                        // siteOptions[primary] (the Options field has no base input).
                        // Mirror them into the base `options` so secondary-site and
                        // CP-detail fallbacks resolve to the primary list.
                        $baseOptions = (string) ($field['options'] ?? '');
                        if (trim($baseOptions) === '') {
                            $primaryHandle = Craft::$app->getSites()->getPrimarySite()->handle;
                            $baseOptions = (string) ($siteOptions[$primaryHandle] ?? '');
                        }
                        $fieldData['options'] = $baseOptions;
                        $fieldData['siteOptions'] = $siteOptions;
                        $fieldData['multiple'] = (bool) ($field['multiple'] ?? false);
                    }
                    
                    if ($field['type'] === 'agree') {
                        $fieldData['agreeText'] = $field['agreeText'] ?? '';
                        $fieldData['siteAgreeText'] = is_array($field['siteAgreeText'] ?? null) ? $field['siteAgreeText'] : [];

                        // Per-site link list. Each site maps to an ordered list of
                        // { text, entryId, url } rows: the phrase to turn into a link
                        // plus its target (a linked Entry wins over a custom URL).
                        // The key is deliberately not "site"-prefixed so the generic
                        // scalar strip below leaves this nested structure intact.
                        $rawLinks = is_array($field['links'] ?? null) ? $field['links'] : [];
                        $links = [];
                        foreach ($rawLinks as $handle => $rows) {
                            if (!is_array($rows)) {
                                continue;
                            }
                            $clean = [];
                            foreach ($rows as $row) {
                                if (!is_array($row)) {
                                    continue;
                                }
                                $text = trim((string) ($row['text'] ?? ''));
                                $url = trim((string) ($row['url'] ?? ''));
                                // elementSelect posts the id as a single-element array.
                                $rawId = $row['entryId'] ?? null;
                                $rawId = is_array($rawId) ? reset($rawId) : $rawId;
                                $entryId = ((int) $rawId > 0) ? (int) $rawId : null;
                                // Drop fully-empty rows the user added but never filled.
                                if ($text === '' && $entryId === null && $url === '') {
                                    continue;
                                }
                                $clean[] = ['text' => $text, 'entryId' => $entryId, 'url' => $url];
                            }
                            if ($clean) {
                                $links[$handle] = $clean;
                            }
                        }
                        $fieldData['links'] = $links;

                        // Per-language checkbox values + default state. The "site"-
                        // prefixed keys get blank entries stripped below, so a blank
                        // language falls back to the primary at render/validate time.
                        $fieldData['siteAgreeChecked'] = is_array($field['siteAgreeChecked'] ?? null) ? $field['siteAgreeChecked'] : [];
                        $fieldData['siteAgreeUnchecked'] = is_array($field['siteAgreeUnchecked'] ?? null) ? $field['siteAgreeUnchecked'] : [];
                        $fieldData['siteAgreeDefault'] = is_array($field['siteAgreeDefault'] ?? null) ? $field['siteAgreeDefault'] : [];
                    }

                    // Presentational (render-only) fields carry no value.
                    if ($field['type'] === 'heading') {
                        $level = $field['headingLevel'] ?? 'h3';
                        $fieldData['headingLevel'] = in_array($level, ['h2', 'h3', 'h4'], true) ? $level : 'h3';
                    }

                    if ($field['type'] === 'callout') {
                        $style = $field['calloutStyle'] ?? 'info';
                        $fieldData['calloutStyle'] = in_array($style, ['info', 'warning', 'success', 'error'], true) ? $style : 'info';
                        $color = trim((string) ($field['calloutColor'] ?? ''));
                        $fieldData['calloutColor'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) ? $color : '';
                        $fieldData['content'] = (string) ($field['content'] ?? '');
                        $fieldData['siteContent'] = $field['siteContent'] ?? [];
                    }

                    if ($field['type'] === 'paragraph') {
                        $fieldData['content'] = (string) ($field['content'] ?? '');
                        $fieldData['siteContent'] = $field['siteContent'] ?? [];
                    }

                    // Drop blank per-site overrides. The builder always posts an
                    // input for every secondary site, so an untranslated one would
                    // store '' — and the render-time fallback (`siteX[handle] ?? base`)
                    // would then show empty instead of the primary value. Coercing
                    // non-arrays to [] also hardens against tampered scalar posts.
                    foreach ($fieldData as $key => $value) {
                        if (is_string($key) && str_starts_with($key, 'site')) {
                            $fieldData[$key] = is_array($value)
                                ? array_filter($value, fn($v) => is_scalar($v) && trim((string) $v) !== '')
                                : [];
                        }
                    }

                    $rowData['fields'][] = $fieldData;
                }

                // Only add rows that have fields
                if (!empty($rowData['fields'])) {
                    $pageData['rows'][] = $rowData;
                }
            }

            // Always add the page (even if empty — user may add fields later)
            $fieldLayout['pages'][] = $pageData;
        }

        // Check if we have at least one field across all pages
        $totalFields = 0;
        foreach ($fieldLayout['pages'] as $page) {
            foreach ($page['rows'] ?? [] as $row) {
                $totalFields += count($row['fields'] ?? []);
            }
        }
        
        EasyForm::debug('Processed field layout: ' . json_encode($fieldLayout));
        EasyForm::debug('Total fields: ' . $totalFields);

        // Misconfigured fields: reject the save and tell the user exactly what to
        // fix, rather than silently dropping the offending fields. The valid fields
        // are kept on the model so re-render preserves the rest of their work.
        if (!empty($layoutErrors)) {
            $form->fieldLayout = $fieldLayout;
            Craft::$app->getSession()->setError(Craft::t('easy-form', 'The form was not saved. {errors}', [
                'errors' => implode(' ', $layoutErrors),
            ]));
            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
            ]);
            return null;
        }

        if ($totalFields === 0) {
            Craft::$app->getSession()->setError(Craft::t('easy-form', 'Please add at least one field to the form before saving.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
            ]);
            return null;
        }

        $form->fieldLayout = $fieldLayout;

        // Notification settings
        $notificationSettingsRaw = $request->getBodyParam('notificationSettings', []);
        $notificationSettings = [];
        
        if (is_array($notificationSettingsRaw)) {
            foreach ($notificationSettingsRaw as $index => $setting) {
                if (!is_array($setting)) {
                    continue;
                }
                // Handle array_filter for recipients
                $recipients = $setting['recipients'] ?? '';
                if (is_string($recipients)) {
                    $recipientsArray = array_filter(array_map('trim', explode(',', $recipients)));
                } else {
                    $recipientsArray = $recipients;
                }

                $notificationSettings[] = [
                    'name' => $setting['name'] ?? 'Notification ' . ($index + 1),
                    'enabled' => (bool) ($setting['enabled'] ?? false),
                    'recipients' => $recipientsArray,
                    'subject' => $setting['subject'] ?? 'New form submission',
                    'senderName' => $setting['senderName'] ?? '',
                    'senderEmail' => $setting['senderEmail'] ?? '',
                    'replyTo' => $setting['replyTo'] ?? '',
                    'cc' => $setting['cc'] ?? '',
                    'bcc' => $setting['bcc'] ?? '',
                    'template' => $setting['template'] ?? '',
                    // How the per-site Email Content is rendered. Whitelisted so a
                    // tampered post can't select an unknown parser.
                    'contentFormat' => in_array($setting['contentFormat'] ?? 'simple', ['simple', 'markdown', 'html'], true)
                        ? $setting['contentFormat']
                        : 'simple',
                    // Coerce per-site maps to arrays so a tampered scalar post can't
                    // reach a string-offset read when notifications are built.
                    'siteTemplates' => is_array($setting['siteTemplates'] ?? null) ? $setting['siteTemplates'] : [],
                    'siteUseTwig' => is_array($setting['siteUseTwig'] ?? null) ? $setting['siteUseTwig'] : [],
                    'siteContent' => is_array($setting['siteContent'] ?? null) ? $setting['siteContent'] : [],
                    'siteEnabled' => is_array($setting['siteEnabled'] ?? null) ? $setting['siteEnabled'] : [],
                    'conditions' => $this->parseNotificationConditions($setting['conditions'] ?? null),
                    'attachFiles' => (bool) ($setting['attachFiles'] ?? false),
                ];
            }
        }
        
        $form->notificationSettings = !empty($notificationSettings) ? $notificationSettings : null;

        // Save the form. Wrap in try/catch so an unexpected exception (DB, event
        // handler, …) surfaces as a friendly error and keeps the user's work,
        // instead of a 500 that discards everything they entered.
        try {
            $saved = EasyForm::getInstance()->forms->saveForm($form);
        } catch (\Throwable $e) {
            EasyForm::log('Unexpected error saving form: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
            Craft::$app->getSession()->setError(Craft::t('easy-form', 'An unexpected error occurred while saving the form. Please try again, or check the logs if the problem persists.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
            ]);
            return null;
        }

        if (!$saved) {
            // Log validation errors for debugging
            $errors = $form->getErrors();
            if (!empty($errors)) {
                EasyForm::log('Form validation errors: ' . json_encode($errors), 'warning');
                $errorMessages = [];
                foreach ($errors as $attribute => $attributeErrors) {
                    foreach ($attributeErrors as $error) {
                        $errorMessages[] = $attribute . ': ' . $error;
                    }
                }
                Craft::$app->getSession()->setError(Craft::t('easy-form', 'Could not save form: {errors}', [
                    'errors' => implode(', ', $errorMessages)
                ]));
            } else {
                Craft::$app->getSession()->setError(Craft::t('easy-form', 'Could not save form.'));
            }

            // Send the form back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('easy-form', 'Form saved.'));

        return $this->redirectToPostedUrl($form);
    }

    /**
     * Get fresh CSRF token
     */
    public function actionGetCsrfToken(): Response
    {
        $this->requireAcceptsJson();
        
        return $this->asJson([
            'csrfToken' => Craft::$app->getRequest()->getCsrfToken()
        ]);
    }

    /**
     * Render a freshly-added field server-side so the builder can inject it
     * with its full Craft markup. This exists for field types whose settings
     * need server-rendered controls the JS builder can't reproduce — namely the
     * agree field's Entry selector (Craft's elementSelectField). Returns the
     * field HTML plus the head/body HTML Craft registered while rendering, so
     * the client can run the element-select init without saving the form first.
     *
     * The field is rendered at the page/row/field index the client gives it, so
     * the element-select input names match where the field will live.
     */
    public function actionRenderField(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $type = (string) $request->getRequiredBodyParam('type');

        // Only types that genuinely need server-side controls go through here;
        // everything else is built client-side in field-manager.js.
        $serverRenderedTypes = ['agree'];
        if (!in_array($type, $serverRenderedTypes, true)) {
            throw new BadRequestHttpException("Field type \"{$type}\" is not server-rendered.");
        }

        $pageIndex = (int) $request->getBodyParam('pageIndex', 0);
        $rowIndex = (int) $request->getBodyParam('rowIndex', 0);
        $fieldIndex = (int) $request->getBodyParam('fieldIndex', 0);

        $field = ['type' => $type, 'label' => '', 'handle' => ''];

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('easy-form/forms/_field-in-row', [
            'field' => $field,
            'pageIndex' => $pageIndex,
            'rowIndex' => $rowIndex,
            'fieldIndex' => $fieldIndex,
        ], $view::TEMPLATE_MODE_CP);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * Render a single agree-field link row (text + Entry select + custom URL).
     *
     * Mirrors actionRenderField: the row's element select is Craft markup that
     * only works as server-rendered HTML plus the init JS Craft registers while
     * rendering. The builder injects the returned html and runs head/body HTML so
     * a freshly-added row's Entry picker works without saving the form first.
     */
    public function actionLinkRow(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $fieldPrefix = (string) $request->getRequiredBodyParam('fieldPrefix');
        $site = (string) $request->getRequiredBodyParam('site');
        $index = (int) $request->getBodyParam('index', 0);

        // The site handle is attacker-controllable; only accept a real one.
        if (Craft::$app->getSites()->getSiteByHandle($site) === null) {
            throw new BadRequestHttpException("Unknown site handle \"{$site}\".");
        }

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('easy-form/forms/_fields/_link-row', [
            'fieldPrefix' => $fieldPrefix,
            'linkSite' => $site,
            'linkIndex' => $index,
            'link' => [],
        ], $view::TEMPLATE_MODE_CP);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * Duplicate a form
     */
    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('id');
        $newName = $request->getRequiredBodyParam('newName');
        
        $originalForm = EasyForm::getInstance()->forms->getFormById($formId);

        if (!$originalForm) {
            return $this->asJson([
                'success' => false,
                'error' => 'Form not found'
            ]);
        }

        // Create a new form with copied data
        $newForm = new Form();
        $newForm->name = $newName;
        
        // Give the copy a fresh, collision-free handle, then carry over every
        // duplicable setting (layout + behavior/spam/notification/webhook).
        $newForm->handle = $this->uniqueHandle($newName);
        EasyForm::getInstance()->forms->copyDuplicableSettings($originalForm, $newForm);

        if (!EasyForm::getInstance()->forms->saveForm($newForm)) {
            $errors = $newForm->getErrors();
            EasyForm::log('Form duplication failed: ' . json_encode($errors), 'error');
            return $this->asJson([
                'success' => false,
                'error' => 'Could not save duplicated form: ' . json_encode($errors)
            ]);
        }

        return $this->asJson([
            'success' => true,
            'formId' => $newForm->id
        ]);
    }

    /**
     * Export a form definition as a portable JSON download.
     */
    public function actionExport(): Response
    {
        $request = Craft::$app->request;

        // Accept a single formId or multiple formIds (csv or array) for a bundle.
        $idsParam = $request->getParam('formIds');
        if ($idsParam !== null) {
            $ids = is_array($idsParam) ? $idsParam : explode(',', (string) $idsParam);
        } else {
            $ids = [$request->getRequiredParam('formId')];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));

        $definitions = [];
        foreach ($ids as $id) {
            $form = EasyForm::getInstance()->forms->getFormById($id);
            if ($form) {
                $definitions[] = $this->buildFormDefinition($form);
            }
        }

        if (empty($definitions)) {
            throw new NotFoundHttpException('Form not found');
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        if (count($definitions) === 1) {
            // Single form: keep the established { easyForm, form } shape.
            $handle = $definitions[0]['handle'];
            $payload = ['easyForm' => ['type' => 'form', 'version' => 1], 'form' => $definitions[0]];
            $response->headers->set('Content-Disposition', 'attachment; filename="easy-form-' . $handle . '.json"');
        } else {
            // Bundle of forms.
            $payload = ['easyForm' => ['type' => 'forms', 'version' => 1], 'forms' => $definitions];
            $response->headers->set('Content-Disposition', 'attachment; filename="easy-form-forms.json"');
        }

        $response->data = Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /**
     * Build the exportable definition array for a form.
     */
    private function buildFormDefinition(Form $form): array
    {
        return [
            'name' => $form->name,
            'handle' => $form->handle,
            'description' => $form->description,
            'fieldLayout' => $form->getNormalizedLayout(),
            'settings' => $form->getSettingsArray(),
            'notificationSettings' => $form->getNotificationSettingsArray(),
            'enabled' => $form->enabled,
            'successMessage' => $form->successMessage,
            'redirectUrl' => $form->redirectUrl,
            'hideFormOnSuccess' => $form->hideFormOnSuccess,
            'keepSuccessMessage' => $form->keepSuccessMessage,
            'successMessageDuration' => $form->successMessageDuration,
            'saveSpamSubmissions' => $form->saveSpamSubmissions,
            'autoApprove' => $form->autoApprove,
            'maxSubmissionsPerUser' => $form->maxSubmissionsPerUser,
            'rateLimit' => $form->rateLimit,
            'rateLimitWindow' => $form->rateLimitWindow,
            'captchaProvider' => $form->captchaProvider,
            'allowUrlPrefill' => $form->allowUrlPrefill,
            'showStepIndicator' => $form->showStepIndicator,
            'validateSteps' => $form->validateSteps,
            'webhookUrl' => $form->webhookUrl,
            'webhookPayload' => $form->webhookPayload,
        ];
    }

    /**
     * Import a form definition (uploaded file or pasted JSON) as a new form.
     */
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();

        // Accept an uploaded file or a pasted definition.
        $raw = '';
        $file = \yii\web\UploadedFile::getInstanceByName('file');
        if ($file && $file->tempName) {
            $raw = file_get_contents($file->tempName) ?: '';
        }
        if ($raw === '') {
            $raw = (string) Craft::$app->request->getBodyParam('definition', '');
        }

        $data = Json::decodeIfJson(trim($raw));

        // Accept a single form ({ form: {...} }) or a bundle ({ forms: [...] }).
        $defs = [];
        if (is_array($data)) {
            if (isset($data['forms']) && is_array($data['forms'])) {
                $defs = $data['forms'];
            } elseif (isset($data['form']) && is_array($data['form'])) {
                $defs = [$data['form']];
            }
        }

        $imported = [];
        foreach ($defs as $def) {
            $form = $this->importFormDefinition(is_array($def) ? $def : []);
            if ($form) {
                $imported[] = $form;
            }
        }

        if (empty($imported)) {
            Craft::$app->session->setError(Craft::t('easy-form', 'Invalid form definition.'));
            return $this->redirect('easy-form/forms');
        }

        if (count($imported) === 1) {
            Craft::$app->session->setNotice(Craft::t('easy-form', 'Form imported.'));
            return $this->redirect('easy-form/forms/' . $imported[0]->id);
        }

        Craft::$app->session->setNotice(Craft::t('easy-form', '{n} forms imported.', ['n' => count($imported)]));
        return $this->redirect('easy-form/forms');
    }

    /**
     * Create a form from an exported definition array. Returns the saved Form
     * or null if the definition is invalid / could not be saved.
     */
    private function importFormDefinition(array $def): ?Form
    {
        if (empty($def['name']) || !isset($def['fieldLayout'])) {
            return null;
        }

        $form = new Form();
        $form->name = (string) $def['name'];
        $form->handle = $this->uniqueHandle($def['handle'] ?? $this->generateHandle($form->name));
        $form->description = $def['description'] ?? null;
        $form->fieldLayout = is_array($def['fieldLayout']) ? $def['fieldLayout'] : EasyForm::getInstance()->formSchema->createEmptyLayout();
        $form->settings = is_array($def['settings'] ?? null) ? $def['settings'] : [];
        $form->siteSuccessMessages = $form->settings['siteSuccessMessages'] ?? [];
        $form->siteErrorMessages = $form->settings['siteErrorMessages'] ?? [];
        $form->notificationSettings = $def['notificationSettings'] ?? null;
        $form->enabled = (bool) ($def['enabled'] ?? true);
        $form->successMessage = $def['successMessage'] ?? null;
        $form->redirectUrl = $def['redirectUrl'] ?? null;
        $form->hideFormOnSuccess = (bool) ($def['hideFormOnSuccess'] ?? false);
        $form->keepSuccessMessage = (bool) ($def['keepSuccessMessage'] ?? true);
        $form->successMessageDuration = max(1, (int) ($def['successMessageDuration'] ?? 5));
        $form->saveSpamSubmissions = (bool) ($def['saveSpamSubmissions'] ?? false);
        $form->autoApprove = (bool) ($def['autoApprove'] ?? true);
        $form->maxSubmissionsPerUser = $def['maxSubmissionsPerUser'] ?? null;
        $form->rateLimit = max(0, (int) ($def['rateLimit'] ?? 0));
        $form->rateLimitWindow = max(1, (int) ($def['rateLimitWindow'] ?? 60));
        $form->allowUrlPrefill = (bool) ($def['allowUrlPrefill'] ?? false);
        $form->showStepIndicator = (bool) ($def['showStepIndicator'] ?? false);
        $form->validateSteps = (bool) ($def['validateSteps'] ?? true);
        $form->webhookUrl = $def['webhookUrl'] ?? null;
        $form->webhookPayload = ($def['webhookPayload'] ?? 'full') === 'data' ? 'data' : 'full';
        // Only keep a captcha provider that exists in this install.
        $provider = $def['captchaProvider'] ?? null;
        $form->captchaProvider = ($provider && EasyForm::getInstance()->captcha->getProvider($provider)) ? $provider : null;

        if (!EasyForm::getInstance()->forms->saveForm($form)) {
            EasyForm::log('Form import failed: ' . json_encode($form->getErrors()), 'error');
            return null;
        }

        return $form;
    }

    /**
     * Returns a unique form handle based on a desired handle.
     */
    private function uniqueHandle(string $desired): string
    {
        $forms = EasyForm::getInstance()->forms;

        return $forms->uniqueHandle(
            $this->generateHandle($desired),
            fn(string $handle): bool => (bool) $forms->getFormByHandle($handle),
        );
    }

    /**
     * Delete a form
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('id');
        $form = EasyForm::getInstance()->forms->getFormById($formId);

        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        // Submissions are orphaned (formId → NULL) on delete by default. The user
        // can opt in to also permanently delete them — which must happen *before*
        // the form delete, while submissions still carry this formId.
        $submissionsDeleted = 0;
        if ((bool) $request->getBodyParam('deleteSubmissions', false)) {
            $submissionsDeleted = EasyForm::getInstance()->submissions->deleteSubmissionsByFormId((int) $form->id);
        }

        if (!EasyForm::getInstance()->forms->deleteForm($form)) {
            return $this->asJson(['success' => false]);
        }

        return $this->asJson(['success' => true, 'submissionsDeleted' => $submissionsDeleted]);
    }
    
    /**
     * Generate a handle from a name
     */
    private function generateHandle(string $name): string
    {
        // Convert to lowercase and replace spaces with underscores
        $handle = strtolower($name);
        $handle = preg_replace('/[^a-z0-9_]/', '_', $handle);
        $handle = preg_replace('/_+/', '_', $handle);
        $handle = trim($handle, '_');
        
        // Ensure it starts with a letter
        if (!preg_match('/^[a-z]/', $handle)) {
            $handle = 'form_' . $handle;
        }
        
        return $handle;
    }

    /**
     * Human-readable label for a field's position, used in save error messages.
     * Prefers the field's label; falls back to its 1-based page/row/field position.
     */
    private function fieldLocationLabel(int $pageIndex, int $rowIndex, int $fieldIndex, string $label): string
    {
        $label = trim($label);
        if ($label !== '') {
            return Craft::t('easy-form', 'Field “{label}”', ['label' => $label]);
        }
        return Craft::t('easy-form', 'Field {n} (page {p}, row {r})', [
            'n' => $fieldIndex + 1,
            'p' => $pageIndex + 1,
            'r' => $rowIndex + 1,
        ]);
    }

    /**
     * Build a sanitized conditions array from POST data
     *
     * @param array $conditions Raw conditions from POST
     * @return array Sanitized conditions structure
     */
    private function buildConditions(array $conditions): array
    {
        $action = $conditions['action'] ?? 'show';
        if (!in_array($action, ['show', 'hide'])) {
            $action = 'show';
        }

        $logic = $conditions['logic'] ?? 'all';
        if (!in_array($logic, ['all', 'any'])) {
            $logic = 'all';
        }

        $rules = [];
        $allowedOperators = ['equals', 'notEquals', 'contains', 'notContains', 'isEmpty', 'isNotEmpty'];
        // Valid per-rule site scopes: 'all', or a known site handle. Anything
        // else falls back to 'all' so a rule is never silently dropped.
        $siteHandles = array_map(fn($s) => $s->handle, Craft::$app->getSites()->getAllSites());

        foreach ($conditions['rules'] ?? [] as $rule) {
            if (empty($rule['field'])) {
                continue;
            }

            $operator = $rule['operator'] ?? 'equals';
            if (!in_array($operator, $allowedOperators)) {
                $operator = 'equals';
            }

            $site = (string) ($rule['site'] ?? 'all');
            if ($site !== 'all' && !in_array($site, $siteHandles, true)) {
                $site = 'all';
            }

            $rules[] = [
                'field' => $rule['field'],
                'operator' => $operator,
                'value' => $rule['value'] ?? '',
                'site' => $site,
            ];
        }

        return [
            'action' => $action,
            'logic' => $logic,
            'rules' => $rules,
        ];
    }

    /**
     * Normalize a notification's conditional-send config. An empty action (or no
     * usable rules) means "always send", stored as null.
     */
    private function parseNotificationConditions($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $action = $raw['action'] ?? '';
        if ($action !== 'show' && $action !== 'hide') {
            return null;
        }

        $conditions = $this->buildConditions($raw);
        return empty($conditions['rules']) ? null : $conditions;
    }
}
