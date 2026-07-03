<?php

namespace yannkost\easyform\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Form;
use yannkost\easyform\events\SubmissionValidationEvent;

/**
 * Submissions Controller
 */
class SubmissionsController extends Controller
{
    use PaginatesTrait;

    /**
     * @var array|int|bool Only the public submit endpoint is anonymous; all CP
     * actions (listing, view, export, delete, notifications) require auth.
     */
    protected array|int|bool $allowAnonymous = ['submit'];

    /**
     * @event SubmissionValidationEvent The event that is triggered before validation
     */
    public const EVENT_BEFORE_VALIDATE = 'beforeValidate';

    /**
     * @event SubmissionValidationEvent The event that is triggered after validation
     */
    public const EVENT_AFTER_VALIDATE = 'afterValidate';

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // The public submit endpoint stays open; CP actions are gated.
        if ($action->id === 'submit') {
            return true;
        }
        $this->requirePermission(match ($action->id) {
            'export', 'export-columns', 'export-status', 'export-check', 'export-download' => 'easy-form:exportSubmissions',
            'delete' => 'easy-form:deleteSubmissions',
            'edit', 'save-edit' => 'easy-form:editSubmissions',
            // Manually (re)sending a notification can forward a submission's full
            // contents to an arbitrary address, so it needs edit — not view — rights.
            'send-notification' => 'easy-form:editSubmissions',
            default => 'easy-form:viewSubmissions',
        });
        return true;
    }

    /**
     * View all submissions across all forms
     */
    public function actionAll()
    {
        $request = Craft::$app->request;
        $forms = EasyForm::getInstance()->forms->getAllForms();

        // Filters
        $formId = $request->getQueryParam('formId');
        $status = $request->getQueryParam('status') ?: null;
        if (!in_array($status, ['pending', 'approved', 'spam', 'archived'], true)) {
            $status = null;
        }
        $search = trim((string) $request->getQueryParam('search', ''));
        $dateFrom = trim((string) $request->getQueryParam('dateFrom', ''));
        $dateTo = trim((string) $request->getQueryParam('dateTo', ''));
        $limit = self::resolvePageSize($request->getQueryParam('limit'));

        // The special "orphaned" value targets submissions whose form was deleted
        // (formId → NULL). They keep their formHandle/formName snapshot, so they
        // stay viewable here instead of vanishing with the form.
        $orphaned = ($formId === 'orphaned');

        // Within orphaned, allow narrowing to a single deleted form by its
        // snapshotted handle. Only honored in orphaned mode, and only if it's a
        // handle we actually still hold submissions for.
        $orphanedForms = $orphaned ? EasyForm::getInstance()->submissions->getOrphanedForms() : [];
        $orphanedForm = $orphaned ? (trim((string) $request->getQueryParam('orphanedForm', '')) ?: null) : null;
        if ($orphanedForm !== null && !in_array($orphanedForm, array_column($orphanedForms, 'formHandle'), true)) {
            $orphanedForm = null;
        }

        // Build the filtered query.
        $query = EasyForm::getInstance()->submissions->getSubmissionsQuery(
            (!$orphaned && $formId) ? (int) $formId : null,
            $status
        );
        if ($orphaned) {
            $query->andWhere(['formId' => null]);
            if ($orphanedForm !== null) {
                $query->andWhere(['formHandle' => $orphanedForm]);
            }
        }
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'primaryEmail', $search],
                ['like', 'searchCol1', $search],
                ['like', 'searchCol2', $search],
                ['like', 'searchCol3', $search],
            ]);
        }
        // Date range filter (on the indexed dateCreated column).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->andWhere(['>=', 'dateCreated', $dateFrom . ' 00:00:00']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->andWhere(['<=', 'dateCreated', $dateTo . ' 23:59:59']);
        }

        $pages = new \yii\data\Pagination([
            'totalCount' => $query->count(),
            'defaultPageSize' => $limit,
            'pageSize' => $limit,
        ]);

        $records = $query->offset($pages->offset)->limit($pages->limit)->all();

        $submissions = [];
        foreach ($records as $record) {
            $submissions[] = EasyForm::getInstance()->submissions->createSubmissionFromRecord($record);
        }

        return $this->renderTemplate('easy-form/submissions/all', [
            'forms' => $forms,
            'submissions' => $submissions,
            'selectedFormId' => $formId,
            'selectedStatus' => $status,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'pages' => $pages,
            'limit' => $limit,
            'statuses' => ['pending', 'approved', 'spam', 'archived'],
            'orphanedForms' => $orphanedForms,
            'selectedOrphanedForm' => $orphanedForm,
        ]);
    }

    /**
     * Update a single submission's status (AJAX).
     */
    public function actionUpdateStatus(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->request->getRequiredBodyParam('id');
        $status = Craft::$app->request->getRequiredBodyParam('status');

        if (!in_array($status, ['pending', 'approved', 'spam', 'archived'], true)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid status']);
        }

        if (!EasyForm::getInstance()->submissions->updateStatus($id, $status)) {
            return $this->asJson(['success' => false, 'error' => 'Could not update status']);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Apply a bulk action (delete or set status) to many submissions (AJAX).
     */
    public function actionBulk(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->request;
        $action = $request->getRequiredBodyParam('bulkAction');
        $ids = array_filter(array_map('intval', (array) $request->getBodyParam('ids', [])));

        if (empty($ids)) {
            return $this->asJson(['success' => false, 'error' => 'No submissions selected']);
        }

        $submissions = EasyForm::getInstance()->submissions;
        $affected = 0;

        if ($action === 'delete') {
            $this->requirePermission('easy-form:deleteSubmissions');
            foreach ($ids as $id) {
                if ($submissions->deleteSubmissionById($id)) {
                    $affected++;
                }
            }
        } elseif ($action === 'status') {
            $status = $request->getRequiredBodyParam('status');
            if (!in_array($status, ['pending', 'approved', 'spam', 'archived'], true)) {
                return $this->asJson(['success' => false, 'error' => 'Invalid status']);
            }
            foreach ($ids as $id) {
                if ($submissions->updateStatus($id, $status)) {
                    $affected++;
                }
            }
        } else {
            return $this->asJson(['success' => false, 'error' => 'Unknown bulk action']);
        }

        return $this->asJson(['success' => true, 'affected' => $affected]);
    }
    
    /**
     * Per-form submissions route — redirect to the paginated all-submissions
     * list filtered by form, avoiding a second unpaginated listing.
     */
    public function actionIndex(): \yii\web\Response
    {
        $formId = (int) Craft::$app->request->getRequiredQueryParam('formId');
        $form = EasyForm::getInstance()->forms->getFormById($formId);

        if (!$form) {
            throw new \yii\web\NotFoundHttpException('Form not found');
        }

        return $this->redirect(UrlHelper::cpUrl('easy-form/submissions', ['formId' => $formId]));
    }
    
    /**
     * View a single submission
     */
    public function actionView(int $submissionId)
    {
        $submission = EasyForm::getInstance()->submissions->getSubmissionById($submissionId);
        
        if (!$submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }
        
        $form = $submission->getForm();

        return $this->renderTemplate('easy-form/submissions/view', [
            'submission' => $submission,
            'form' => $form,
        ]);
    }

    /**
     * Edit a single submission's field values (text only — never metadata
     * like the site, IP address or timestamps).
     */
    public function actionEdit(int $submissionId)
    {
        $submission = EasyForm::getInstance()->submissions->getSubmissionById($submissionId);

        if (!$submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }

        return $this->renderTemplate('easy-form/submissions/edit', [
            'submission' => $submission,
            'form' => $submission->getForm(),
        ]);
    }

    /**
     * Persist edited submission field values. Only handles posted under
     * `values[...]` are applied, and file fields are ignored — submitted
     * metadata is never touched here.
     */
    public function actionSaveEdit(): ?\yii\web\Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $submissionId = (int) $request->getRequiredBodyParam('submissionId');
        $submission = EasyForm::getInstance()->submissions->getSubmissionById($submissionId);

        if (!$submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }

        $posted = (array) $request->getBodyParam('values', []);

        // Never allow editing of file fields through this panel.
        $fileHandles = [];
        $snapshot = $submission->getFieldSnapshotArray();
        foreach (($snapshot['fields'] ?? []) as $snap) {
            if (($snap['type'] ?? null) === 'file' && !empty($snap['handle'])) {
                $fileHandles[$snap['handle']] = true;
            }
        }

        // Preserve the original shape: array-valued fields (e.g. checkbox groups)
        // are edited as newline-separated text and split back into an array.
        $original = $submission->getFlatValues();

        $edits = [];
        foreach ($posted as $handle => $value) {
            if (isset($fileHandles[$handle])) {
                continue;
            }
            if (is_array($original[$handle] ?? null) && is_string($value)) {
                $edits[$handle] = array_values(array_filter(
                    array_map('trim', preg_split('/\r\n|\r|\n/', $value)),
                    fn($v) => $v !== ''
                ));
            } else {
                $edits[$handle] = $value;
            }
        }

        $submission->applyFieldValueEdits($edits);

        if (!EasyForm::getInstance()->submissions->saveSubmission($submission)) {
            Craft::$app->getSession()->setError(Craft::t('easy-form', 'Couldn’t save submission.'));
            Craft::$app->getUrlManager()->setRouteParams(['submission' => $submission]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('easy-form', 'Submission saved.'));
        return $this->redirect(UrlHelper::cpUrl('easy-form/submissions/' . $submission->id));
    }
    
    /**
     * Stream a CSV export of a form's submissions.
     */
    /**
     * Queues a CSV export and returns the token + status page URL. All exports
     * are queued (no synchronous path) so behavior is identical and memory-safe
     * regardless of dataset size; the file is fetched later via export-download.
     */
    public function actionExport(?int $formId = null): \yii\web\Response
    {
        $this->requirePostRequest();

        // formId may arrive as a route param (…/forms/<id>/export) or body param,
        // and may be the string 'orphaned' rather than a numeric id.
        $formId = $formId ?? Craft::$app->request->getBodyParam('formId');
        $status = Craft::$app->request->getParam('status') ?: null;
        if (!in_array($status, ['pending', 'approved', 'spam', 'archived'], true)) {
            $status = null;
        }
        $siteId = (int) Craft::$app->request->getParam('siteId') ?: null;
        $dateFrom = trim((string) Craft::$app->request->getParam('dateFrom', ''));
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $dateTo = trim((string) Craft::$app->request->getParam('dateTo', ''));
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;
        $search = trim((string) Craft::$app->request->getParam('search', '')) ?: null;

        // Selected export columns (keys from Submissions::exportColumns). Null keeps
        // the default set (real form fields).
        $columns = Craft::$app->request->getParam('columns');
        $columns = is_array($columns)
            ? array_values(array_filter(array_map('strval', $columns), fn($c) => $c !== ''))
            : null;

        // Orphaned export: a specific deleted form is targeted by its handle
        // (heterogeneous "all deleted forms" export isn't supported — the
        // columns would be undefined across differing snapshots).
        $orphanedForm = trim((string) Craft::$app->request->getBodyParam('orphanedForm', '')) ?: null;
        $isOrphaned = $orphanedForm !== null || $formId === 'orphaned';

        if ($isOrphaned) {
            $match = null;
            foreach (EasyForm::getInstance()->submissions->getOrphanedForms() as $o) {
                if ($o['formHandle'] === $orphanedForm) {
                    $match = $o;
                    break;
                }
            }
            if (!$match) {
                throw new \yii\web\BadRequestHttpException('Choose a deleted form to export.');
            }
            $formName = $match['formName'] ?: $orphanedForm;
            $filename = ($orphanedForm ?: 'deleted-form') . '-submissions-' . date('Y-m-d--H-i-s') . '.csv';
            $recordFormId = null;
            $jobConfig = ['orphanedHandle' => $orphanedForm];
        } else {
            $form = EasyForm::getInstance()->forms->getFormById((int) $formId);
            if (!$form) {
                throw new \yii\web\NotFoundHttpException('Form not found');
            }
            $formName = $form->name;
            $filename = $form->handle . '-submissions-' . date('Y-m-d--H-i-s') . '.csv';
            $recordFormId = (int) $form->id;
            $jobConfig = ['formId' => (int) $form->id];
        }

        $token = Craft::$app->getSecurity()->generateRandomString(32);

        // "Save a copy on the server": kept exports never expire (deleted by
        // hand); ephemeral ones are garbage-collected after the TTL.
        $keep = (bool) Craft::$app->request->getBodyParam('keep', false);
        $dateExpires = $keep
            ? null
            : gmdate('Y-m-d H:i:s', time() + \yannkost\easyform\services\Exports::EPHEMERAL_TTL);

        EasyForm::getInstance()->exports->createRecord([
            'token' => $token,
            'formId' => $recordFormId,
            'formName' => $formName,
            'filters' => [
                'status' => $status,
                'siteId' => $siteId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'search' => $search,
                'columns' => $columns,
                'orphanedForm' => $orphanedForm,
            ],
            'filename' => $filename,
            'keep' => $keep,
            'dateExpires' => $dateExpires,
            'createdBy' => Craft::$app->getUser()->getId(),
        ]);

        Craft::$app->getQueue()->push(new \yannkost\easyform\jobs\ExportSubmissions($jobConfig + [
            'status' => $status,
            'siteId' => $siteId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'search' => $search,
            'columns' => $columns,
            'token' => $token,
        ]));

        $statusUrl = UrlHelper::cpUrl('easy-form/exports/status', ['key' => $token]);

        if (Craft::$app->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'token' => $token, 'statusUrl' => $statusUrl]);
        }
        return $this->redirect($statusUrl);
    }

    /**
     * Returns the selectable export columns for a form, so the export dialog can
     * render its column picker. GET, JSON.
     */
    public function actionExportColumns(): \yii\web\Response
    {
        $this->requireAcceptsJson();

        // Orphaned (deleted-form) columns are reconstructed from a stored snapshot.
        $orphanedForm = trim((string) Craft::$app->request->getParam('orphanedForm', '')) ?: null;
        if ($orphanedForm !== null) {
            $snapshot = EasyForm::getInstance()->submissions->getOrphanedFieldSnapshot($orphanedForm) ?? ['fields' => []];
            return $this->asJson([
                'columns' => EasyForm::getInstance()->submissions->exportColumnsFromSnapshot($snapshot),
            ]);
        }

        $formId = (int) Craft::$app->request->getRequiredParam('formId');
        $form = EasyForm::getInstance()->forms->getFormById($formId);
        if (!$form) {
            throw new \yii\web\NotFoundHttpException('Form not found');
        }

        return $this->asJson([
            'columns' => EasyForm::getInstance()->submissions->exportColumns($form),
        ]);
    }

    /**
     * The "preparing → ready" page the user lands on after queueing an export.
     */
    public function actionExportStatus(): \yii\web\Response
    {
        $token = $this->validExportToken(Craft::$app->request->getRequiredParam('key'));
        return $this->renderTemplate('easy-form/exports/status', ['token' => $token]);
    }

    /**
     * Polled by the status page: reports whether the export file is ready.
     */
    public function actionExportCheck(): \yii\web\Response
    {
        $this->requireAcceptsJson();
        $token = $this->validExportToken(Craft::$app->request->getRequiredParam('key'));

        $exports = EasyForm::getInstance()->exports;
        $record = $exports->getByToken($token);
        if (!$record) {
            return $this->asJson(['ready' => false]);
        }
        if ($record->status === 'failed') {
            return $this->asJson(['ready' => false, 'failed' => true, 'message' => (string) $record->message]);
        }
        if ($record->status === 'ready' && is_file($exports->filePath($token))) {
            return $this->asJson([
                'ready' => true,
                'downloadUrl' => UrlHelper::cpUrl('easy-form/exports/download', ['key' => $token]),
            ]);
        }
        return $this->asJson(['ready' => false]);
    }

    /**
     * Streams a finished export file as an attachment. Left on disk for
     * re-download until the job's TTL garbage-collects it.
     */
    public function actionExportDownload(): \yii\web\Response
    {
        $token = $this->validExportToken(Craft::$app->request->getRequiredParam('key'));
        $exports = EasyForm::getInstance()->exports;
        $csvPath = $exports->filePath($token);
        if (!is_file($csvPath)) {
            throw new \yii\web\NotFoundHttpException('Export not found or expired');
        }

        $record = $exports->getByToken($token);
        $filename = $record->filename ?? 'submissions.csv';

        return Craft::$app->getResponse()->sendFile($csvPath, $filename, [
            'mimeType' => 'text/csv',
            'inline' => false,
        ]);
    }

    /** Validate an export token (opaque random string), 400 on anything else. */
    private function validExportToken($value): string
    {
        $token = (string) $value;
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            throw new \yii\web\BadRequestHttpException('Invalid export token');
        }
        return $token;
    }

    /**
     * Submit a form from the front-end
     */
    public function actionSubmit()
    {
        $this->requirePostRequest();

        // Remember the request's site so we can restore it after temporarily
        // switching to the submission's site (see below).
        $previousSite = Craft::$app->sites->getCurrentSite();

        // Tracked so the finally block can clean up uploaded files/assets if the
        // submission is ultimately not saved (validation failure, exception, …).
        $uploadedAssets = ['assets' => [], 'errors' => []];
        $saved = false;

        try {
        $formId = Craft::$app->request->getRequiredBodyParam('formId');
        $form = EasyForm::getInstance()->forms->getFormById((int)$formId);
        
        if (!$form) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => 'Form not found']);
            }
            throw new \yii\web\NotFoundHttpException('Form not found');
        }
        
        if (!$form->enabled) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => 'Form is not enabled']);
            }
            throw new \yii\web\ForbiddenHttpException('Form is not enabled');
        }

        // Enforce per-user submission limit (logged-in users only).
        $userId = Craft::$app->user->id;
        if ($form->maxSubmissionsPerUser && $userId) {
            $count = EasyForm::getInstance()->submissions->getSubmissionCountByUser($form->id, $userId);
            if ($count >= $form->maxSubmissionsPerUser) {
                $limitMessage = Craft::t('easy-form', 'You have reached the maximum number of submissions for this form.');
                if (Craft::$app->request->getAcceptsJson()) {
                    return $this->asJson(['success' => false, 'error' => $limitMessage]);
                }
                Craft::$app->session->setError($limitMessage);
                return null;
            }
        }

        // Per-IP rate limit (opt-in per form). A sliding window keyed by form+IP.
        if ($form->rateLimit > 0) {
            $cache = Craft::$app->getCache();
            $key = 'easyform:rl:' . $form->id . ':' . md5((string) Craft::$app->request->userIP);
            $hits = (int) $cache->get($key);
            if ($hits >= $form->rateLimit) {
                $rateMessage = Craft::t('easy-form', 'Too many submissions. Please try again later.');
                if (Craft::$app->request->getAcceptsJson()) {
                    Craft::$app->response->setStatusCode(429);
                    return $this->asJson(['success' => false, 'error' => $rateMessage]);
                }
                Craft::$app->session->setError($rateMessage);
                return null;
            }
            $cache->set($key, $hits + 1, max(1, (int) $form->rateLimitWindow));
        }

        // Check honeypot (always enabled)
        $honeypot = Craft::$app->request->getBodyParam('honeypot');
        $isSpam = !empty($honeypot);
        $spamReason = $isSpam ? 'honeypot filled' : null;
        // Blocked email domains / keywords are evaluated below, once the site
        // context is known (the rejection message is per-site).

        // Determine site context. A posted siteId is only honored when it maps
        // to a real, enabled site; anything else falls back to the request site.
        $siteId = Craft::$app->request->getBodyParam('siteId');
        $currentSite = null;
        if ($siteId) {
            $candidate = Craft::$app->sites->getSiteById((int)$siteId);
            if ($candidate && $candidate->enabled) {
                $currentSite = $candidate;
            }
        }
        if (!$currentSite) {
            $currentSite = $previousSite;
        }

        // Set the current site context for services/validation (restored in the
        // finally block so the switch never leaks into the rest of the request).
        Craft::$app->sites->setCurrentSite($currentSite);
        
        $currentSiteHandle = trim($currentSite->handle);
        $siteSuccessMessages = $form->getSiteSuccessMessagesArray();
        $siteErrorMessages = $form->getSiteErrorMessagesArray();

        $settings = EasyForm::getInstance()->getSettings();
        $defaultSuccess = $form->successMessage
            ?: ($settings->defaultSuccessMessage ?: Craft::t('easy-form', 'Form submitted successfully.'));
        $successMessage = $siteSuccessMessages[$currentSiteHandle] ?? $defaultSuccess;
        $errorMessage = $siteErrorMessages[$currentSiteHandle] ?? Craft::t('easy-form', 'Could not submit form.');
        // Per-site redirect, falling back to the form's default redirect URL.
        $redirectUrl = $form->getRedirectUrlForSite($currentSiteHandle);

        // Blocked email domains / keywords. By default these are filed silently as
        // spam — the sender gets a normal success response, so they can't probe the
        // block list. With "silently reject" turned off, the submission is instead
        // refused up front with a visible, per-site message.
        if (!$isSpam) {
            $postedFields = Craft::$app->request->getBodyParam('fields', []);
            $blockedReason = $this->containsBlockedEmailDomain($postedFields)
                ? 'blocked email domain'
                : ($this->containsBlockedKeyword($postedFields) ? 'blocked keyword' : null);

            if ($blockedReason !== null) {
                if ($settings->silentlyRejectBlocked) {
                    $isSpam = true;
                    $spamReason = $blockedReason;
                } else {
                    EasyForm::log(sprintf(
                        'Submission to form #%d (%s) rejected (%s).',
                        $form->id,
                        $form->handle,
                        $blockedReason
                    ), 'info');
                    $blockedMessage = $settings->getBlockedSubmissionMessageForSite($currentSiteHandle);
                    if (Craft::$app->request->getAcceptsJson()) {
                        return $this->asJson(['success' => false, 'error' => $blockedMessage]);
                    }
                    Craft::$app->session->setError($blockedMessage);
                    return null;
                }
            }
        }

        // CAPTCHA verification (per-form provider). A failure is filed silently
        // as spam — like the other spam signals above — unless the form opts in
        // to "reject on CAPTCHA failure", in which case it is refused up front.
        $captchaScore = null;
        if ($form->captchaProvider) {
            $captcha = EasyForm::getInstance()->captcha->getProvider($form->captchaProvider);
            if ($captcha && $captcha->isConfigured()) {
                $token = Craft::$app->request->getBodyParam($captcha->getTokenParam());
                // Per-form score threshold overrides the global setting when set.
                $context = [];
                if ($form->captchaScoreThreshold !== null) {
                    $context['scoreThreshold'] = $form->captchaScoreThreshold;
                }
                $passed = $captcha->verify($token, Craft::$app->request->userIP, $context);
                // Score-based providers (v3) expose the resolved score for storage.
                $captchaScore = $captcha->getLastScore();

                // Always log the outcome so every rejection is visible.
                if ($captchaScore !== null) {
                    $threshold = $form->captchaScoreThreshold
                        ?? (float) EasyForm::getInstance()->getSettings()->recaptchaV3ScoreThreshold;
                    EasyForm::log(sprintf(
                        "CAPTCHA '%s' for form '%s': %s (score %.2f vs threshold %.2f)",
                        $form->captchaProvider,
                        $form->handle,
                        $passed ? 'passed' : 'failed',
                        $captchaScore,
                        (float) $threshold
                    ), $passed ? 'info' : 'warning');
                } else {
                    EasyForm::log(sprintf(
                        "CAPTCHA '%s' for form '%s': %s",
                        $form->captchaProvider,
                        $form->handle,
                        $passed ? 'passed' : 'failed'
                    ), $passed ? 'info' : 'warning');
                }

                if (!$passed) {
                    if ($form->rejectOnCaptchaFail) {
                        $captchaError = Craft::t('easy-form', 'CAPTCHA verification failed. Please try again.');
                        if (Craft::$app->request->getAcceptsJson()) {
                            return $this->asJson(['success' => false, 'error' => $captchaError]);
                        }
                        Craft::$app->session->setError($captchaError);
                        return null;
                    }
                    // Default: treat like any other spam signal (filed silently).
                    $isSpam = true;
                    $spamReason = $spamReason ?? 'captcha failed';
                }
            } else {
                // Selected provider is no longer configured — fail open.
                EasyForm::log("Form {$form->id} requests CAPTCHA '{$form->captchaProvider}' but it is not configured; allowing submission.", 'warning');
            }
        }

        if ($isSpam && !$form->saveSpamSubmissions) {
            // Spam detected and we don't want to save it - silently succeed.
            // Leave a trace so a dropped submission isn't invisible (a real
            // visitor's autofill extension can fill the honeypot — see false
            // positives without this line).
            EasyForm::log(sprintf(
                'Submission to form #%d (%s) dropped as spam (%s); not saved.',
                $form->id,
                $form->handle,
                $spamReason ?? 'spam'
            ), 'warning');

            // The response must mirror a genuine success (same hide/message
            // flags) so spam submissions are indistinguishable to the client;
            // otherwise the form would skip hiding/auto-hide for flagged users.
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'message' => $successMessage,
                    'redirect' => $redirectUrl,
                    'hideForm' => $form->hideFormOnSuccess,
                    'keepMessage' => $form->keepSuccessMessage,
                    'messageDuration' => $form->successMessageDuration,
                    // Emit a throwaway id so the response is byte-for-byte shaped
                    // like a genuine success; without it a bot could detect that
                    // its submission was silently classified as spam.
                    'submissionId' => random_int(100000, 9999999),
                ]);
            }
            if ($redirectUrl) {
                return $this->redirect($redirectUrl);
            }
            return $this->redirectToPostedUrl();
        }

        // Get submitted field data
        $fields = Craft::$app->request->getBodyParam('fields', []);
        
        // Handle file uploads
        $uploadedAssets = $this->handleFileUploads($form->fieldLayoutArray, $form);
        if ($uploadedAssets['errors']) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => 'File upload error',
                    'errors' => $uploadedAssets['errors']
                ]);
            }
            Craft::$app->session->setError('File upload error');
            return null;
        }
        
        // Merge uploaded asset IDs into fields
        $fields = array_merge($fields, $uploadedAssets['assets']);
        
        // Trigger beforeValidate event
        $event = new SubmissionValidationEvent([
            'form' => $form,
            'submissionData' => $fields,
        ]);
        $this->trigger(self::EVENT_BEFORE_VALIDATE, $event);

        // Apply any changes a beforeValidate handler made, so they flow through
        // condition evaluation, validation and canonicalization (allow-list +
        // sanitization) like normal submitted data.
        if (is_array($event->submissionData)) {
            $fields = $event->submissionData;
        }

        if (!$event->isValid) {
            $errorMessage = $event->message ?? 'Submission stopped by beforeValidate event.';
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $errorMessage]);
            }
            Craft::$app->session->setError($errorMessage);
            return null;
        }
        
        // Resolve which known fields are visible (condition-aware) once, and
        // reuse it for both validation and canonicalization.
        $normalizedLayout = $form->getNormalizedLayout();
        $visibleHandles = EasyForm::getInstance()->conditionEvaluator
            ->getVisibleFieldHandles($normalizedLayout, $fields, $currentSiteHandle);

        // Validate field data against form field layout
        $validationErrors = EasyForm::getInstance()->validation->validateSubmission(
            $fields,
            $normalizedLayout,
            $visibleHandles
        );

        // Trigger afterValidate event. This is an inspect/cancel hook: its
        // submissionData is read-only (changes are NOT applied — mutating data
        // here would bypass validation and desync the visible-field map). To
        // transform data, use beforeValidate; for full control over the final
        // stored model, use Submissions::EVENT_BEFORE_SAVE_SUBMISSION.
        $event = new SubmissionValidationEvent([
            'form' => $form,
            'submissionData' => $fields,
        ]);
        $this->trigger(self::EVENT_AFTER_VALIDATE, $event);

        if (!$event->isValid) {
            $errorMessage = $event->message ?? 'Submission stopped by afterValidate event.';
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $errorMessage]);
            }
            Craft::$app->session->setError($errorMessage);
            return null;
        }

        // NB: afterValidate is inspect/cancel only — we deliberately do NOT copy
        // $event->submissionData back into $fields. Validation already ran above,
        // so applying post-validation mutations here would bypass it (and desync
        // the visible-field map). Use beforeValidate to transform data, or
        // Submissions::EVENT_BEFORE_SAVE_SUBMISSION to alter the stored model.

        if (!empty($validationErrors)) {
            $errorMessage = 'Please correct the following errors:';
            
            // AJAX response with validation errors
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $validationErrors
                ]);
            }
            
            // Traditional form submission
            Craft::$app->session->setError($errorMessage);
            Craft::$app->urlManager->setRouteParams([
                'errors' => $validationErrors,
                'values' => $fields
            ]);
            return null;
        }
        
        // Build the canonical, operationally-safe payload.
        $normalized = EasyForm::getInstance()->submissionData->build($form, $fields, $visibleHandles, $currentSiteHandle);

        // Surface any frontend (allowlist) coercion errors as field errors.
        if (!empty($normalized['frontendErrors'])) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => 'Please correct the following errors:',
                    'errors' => $normalized['frontendErrors'],
                ]);
            }
            Craft::$app->session->setError(Craft::t('easy-form', 'Please correct the form errors.'));
            Craft::$app->urlManager->setRouteParams([
                'errors' => $normalized['frontendErrors'],
                'values' => $fields,
            ]);
            return null;
        }

        // Enforce admin-marked "unique" fields: reject if another live submission
        // of this form already stores the same value for that field (e.g. one
        // entry per email). Only visible, non-empty scalar values are checked.
        $schemaService = EasyForm::getInstance()->formSchema;
        $promotedMap = $schemaService->getPromotedFields($normalizedLayout);
        $uniqueErrors = [];
        foreach ($schemaService->getAllFields($normalizedLayout) as $f) {
            $handle = $f['handle'] ?? '';
            if (empty($f['unique']) || $handle === '' || !in_array($handle, $visibleHandles, true)) {
                continue;
            }
            $value = $normalized['data']['values'][$handle] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            if (EasyForm::getInstance()->submissions->valueExistsForField($form->id, $handle, (string) $value, $promotedMap)) {
                $label = $form->resolveFieldLabel($handle, $currentSiteHandle);
                // Per-site custom "unique" message, falling back to the default.
                $custom = trim((string) ($f['siteUniqueMessages'][$currentSiteHandle] ?? ''));
                $uniqueErrors[$handle] = [
                    $custom !== ''
                        ? $custom
                        : Craft::t('easy-form', '{label} has already been used.', ['label' => $label]),
                ];
            }
        }
        if (!empty($uniqueErrors)) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => 'Please correct the following errors:',
                    'errors' => $uniqueErrors,
                ]);
            }
            Craft::$app->session->setError(Craft::t('easy-form', 'Please correct the form errors.'));
            Craft::$app->urlManager->setRouteParams(['errors' => $uniqueErrors, 'values' => $fields]);
            return null;
        }

        // Create submission model
        $submission = new \yannkost\easyform\models\Submission();
        $submission->formId = $form->id;
        $submission->formHandle = $form->handle;
        $submission->formName = $form->name;
        $submission->siteId = $currentSite->id;
        $submission->data = $normalized['data'];
        $submission->fieldSnapshot = $normalized['fieldSnapshot'];
        $submission->primaryEmail = $normalized['promoted']['primaryEmail'];
        $submission->searchCol1 = $normalized['promoted']['searchCol1'];
        $submission->searchCol2 = $normalized['promoted']['searchCol2'];
        $submission->searchCol3 = $normalized['promoted']['searchCol3'];
        $submission->userId = $userId;
        // Privacy: process IP per settings (off / full / anonymized / hashed).
        $submission->ipAddress = $settings->processIpAddress(Craft::$app->request->userIP);
        $submission->userAgent = $settings->storeIpAddresses ? Craft::$app->request->userAgent : null;
        $submission->status = $isSpam ? 'spam' : ($form->autoApprove ? 'approved' : 'pending');
        $submission->honeypotValue = $isSpam ? $honeypot : null;
        $submission->spamReason = $isSpam ? $spamReason : null;
        $submission->captchaScore = $captchaScore;

        // Save submission
        $saved = EasyForm::getInstance()->submissions->saveSubmission($submission);
        
        if ($saved) {
            // AJAX response
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'message' => $successMessage,
                    'redirect' => $redirectUrl,
                    'hideForm' => $form->hideFormOnSuccess,
                    'keepMessage' => $form->keepSuccessMessage,
                    'messageDuration' => $form->successMessageDuration,
                    'submissionId' => $submission->id
                ]);
            }
            
            // Traditional form submission
            Craft::$app->session->setNotice($successMessage);

            if ($redirectUrl) {
                return $this->redirect($redirectUrl);
            }
        } else {
            $errors = $submission->getErrors();
            
            // AJAX response
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $errorMessage,
                    'errors' => $errors
                ]);
            }
            
            // Traditional form submission
            Craft::$app->session->setError($errorMessage);
        }

        return $this->redirectToPostedUrl();

        } catch (\yii\web\HttpException $e) {
            // Intentional control-flow exceptions (404/403/400) — let them through.
            throw $e;
        } catch (\Throwable $e) {
            EasyForm::log('Unexpected error handling submission: ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            $genericError = Craft::t('easy-form', 'Something went wrong. Please try again.');
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $genericError]);
            }
            Craft::$app->session->setError($genericError);
            return null;
        } finally {
            // Clean up any uploaded files/assets if the submission wasn't saved,
            // so a later validation failure can't leave orphans.
            if (!$saved && !empty($uploadedAssets['assets'])) {
                $this->deleteUploadedAssets($uploadedAssets['assets']);
            }
            // Always restore the request's original site.
            if ($previousSite) {
                Craft::$app->sites->setCurrentSite($previousSite);
            }
        }
    }

    /**
     * Whether any submitted value contains a globally blocked keyword.
     *
     * Keywords come from the plugin settings (one per line or comma-separated)
     * and are matched case-insensitively against all scalar submitted values.
     */
    private function containsBlockedKeyword($fields): bool
    {
        $raw = trim(EasyForm::getInstance()->getSettings()->blockedKeywords);
        if ($raw === '' || !is_array($fields)) {
            return false;
        }

        $keywords = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw)));
        if (!$keywords) {
            return false;
        }

        // Flatten all submitted values into one searchable string.
        $haystack = '';
        array_walk_recursive($fields, function ($value) use (&$haystack) {
            if (is_scalar($value)) {
                $haystack .= ' ' . $value;
            }
        });
        $haystack = mb_strtolower($haystack);

        foreach ($keywords as $keyword) {
            if (mb_strpos($haystack, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any submitted value is an email address whose domain is on the
     * configured block list (case-insensitive). Mirrors containsBlockedKeyword,
     * but matches on the domain part rather than a substring.
     */
    private function containsBlockedEmailDomain($fields): bool
    {
        $blocked = EasyForm::getInstance()->getSettings()->getBlockedEmailDomainsArray();
        if (!$blocked) {
            return false;
        }

        $hit = false;
        array_walk_recursive($fields, function ($value) use ($blocked, &$hit) {
            if ($hit || !is_scalar($value)) {
                return;
            }
            $at = strrpos((string) $value, '@');
            if ($at === false) {
                return;
            }
            $domain = mb_strtolower(trim(substr((string) $value, $at + 1)));
            if ($domain !== '' && in_array($domain, $blocked, true)) {
                $hit = true;
            }
        });

        return $hit;
    }

    /**
     * Handle file uploads and create Craft Assets.
     *
     * Uploaded files are resolved via Craft's normalized UploadedFile API
     * (no $_FILES parsing). Asset IDs are stored on the submission under the
     * field handle (single ID, or an array when the field allows multiple).
     */
    private function handleFileUploads(array $fieldLayout, ?Form $form = null): array
    {
        $assets = [];
        $errors = [];

        // Forms may override the global file-handling configuration.
        $settings = $form !== null
            ? $form->getEffectiveUploadSettings()
            : EasyForm::getInstance()->getSettings();
        $mode = $settings->getUploadMode();

        $schemaService = EasyForm::getInstance()->formSchema;
        $validation = EasyForm::getInstance()->validation;
        $normalized = $schemaService->normalize($fieldLayout);

        $volume = $mode === 'asset' ? $settings->getUploadVolume() : null;
        $folderId = $volume ? $this->resolveUploadFolderId($volume, $settings->uploadSubfolder) : null;

        foreach ($schemaService->getAllFields($normalized) as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            $handle = $field['handle'] ?? '';
            if (!$handle) {
                continue;
            }

            $files = $validation->getUploadedFiles($handle);
            if (empty($files)) {
                continue;
            }

            // Validate size / type / blocklist BEFORE persisting, so an invalid
            // file never gets written to a volume or the filesystem.
            $preflight = $this->preflightUploadErrors($field, $files, $settings);
            if ($preflight !== null) {
                $errors[$handle] = $preflight;
                continue;
            }

            // Filesystem mode: store files directly, no Craft Asset element.
            if ($mode === 'filesystem') {
                [$value, $error] = $this->storeFilesOnFilesystem($files, $field, $settings);
                if ($error !== null) {
                    $errors[$handle] = $error;
                }
                if ($value !== null) {
                    $assets[$handle] = $value;
                }
                continue;
            }

            // Asset mode.
            if (!$volume) {
                $errors[$handle] = Craft::t('easy-form', 'File uploads are not configured. Set an upload volume in Easy Form settings.');
                continue;
            }

            $isMultiple = (bool) ($field['allowMultiple'] ?? false);
            $assetIds = [];

            foreach ($files as $file) {
                try {
                    $asset = new \craft\elements\Asset();
                    $asset->tempFilePath = $file->tempName;
                    $asset->setFilename($file->name);
                    $asset->newFolderId = $folderId;
                    $asset->setVolumeId($volume->id);
                    $asset->avoidFilenameConflicts = true;

                    if (Craft::$app->elements->saveElement($asset)) {
                        $assetIds[] = $asset->id;
                    } else {
                        $errors[$handle] = 'Failed to save file: ' . implode(', ', $asset->getErrorSummary(true));
                    }
                } catch (\Throwable $e) {
                    EasyForm::log('File upload error for field ' . $handle . ': ' . $e->getMessage(), 'error');
                    $errors[$handle] = Craft::t('easy-form', 'File upload error.');
                }
            }

            if (!empty($assetIds)) {
                $assets[$handle] = $isMultiple ? $assetIds : $assetIds[0];
            }
        }

        return ['assets' => $assets, 'errors' => $errors];
    }

    /**
     * Validate a field's uploaded files (size / blocked extension / allowed
     * types) before anything is persisted. Returns the first error, or null.
     */
    private function preflightUploadErrors(array $field, array $files, ?\yannkost\easyform\models\Settings $settings = null): ?string
    {
        $settings ??= EasyForm::getInstance()->getSettings();

        $maxFileSize = $field['maxFileSize'] ?? null;
        if (empty($maxFileSize) || !is_numeric($maxFileSize)) {
            $maxFileSize = $settings->maxFileSize;
        }
        $maxBytes = (float) $maxFileSize * 1024 * 1024;

        // Combined (total) upload size — checked here, before any file is
        // persisted, so an oversized batch never leaves orphaned uploads.
        $maxTotalSize = $field['maxTotalSize'] ?? null;
        if (empty($maxTotalSize) || !is_numeric($maxTotalSize)) {
            $maxTotalSize = $settings->maxTotalUploadSize;
        }
        if (!empty($maxTotalSize) && is_numeric($maxTotalSize) && (int) $maxTotalSize > 0) {
            $totalBytes = array_sum(array_map(fn($f) => $f->size, $files));
            if ($totalBytes > (float) $maxTotalSize * 1024 * 1024) {
                return Craft::t('easy-form', 'Combined upload size exceeds the {max}MB limit.', ['max' => (int) $maxTotalSize]);
            }
        }

        $allowed = !empty($field['allowedFileTypes'])
            ? array_filter(array_map('trim', explode(',', strtolower($field['allowedFileTypes']))))
            : [];
        $blocked = \yannkost\easyform\models\Settings::BLOCKED_UPLOAD_EXTENSIONS;

        foreach ($files as $file) {
            $name = $file->name;
            $ext = strtolower($file->getExtension() ?: pathinfo($name, PATHINFO_EXTENSION));

            if ($file->size > $maxBytes) {
                return Craft::t('easy-form', 'File “{name}” exceeds maximum size of {max}MB.', ['name' => $name, 'max' => $maxFileSize]);
            }
            if ($ext === '' || in_array($ext, $blocked, true)) {
                return Craft::t('easy-form', 'File type “.{ext}” is not allowed.', ['ext' => $ext]);
            }
            if (!empty($allowed) && !in_array($ext, $allowed, true)) {
                return Craft::t('easy-form', 'File “{name}” has an invalid file type.', ['name' => $name]);
            }
        }

        return null;
    }

    /**
     * Delete files/assets created during a submission that ultimately failed,
     * so a rejected submission never leaves orphaned uploads. Shares the same
     * cleanup used when an existing submission is deleted.
     */
    private function deleteUploadedAssets(array $assets): void
    {
        $submissions = EasyForm::getInstance()->submissions;
        foreach ($assets as $value) {
            $submissions->deleteFileValue($value);
        }
    }

    /**
     * Store uploaded files on the filesystem (no Craft Asset element).
     *
     * Returns [value, error] where value is a structured metadata array:
     *   { storage: 'filesystem', files: [ { filename, path, url, size, mimeType } ] }
     *
     * Security: extension allow list (+ a hard block list of executable types),
     * randomized collision-safe filenames, and basename-only paths (no traversal).
     *
     * @param \yii\web\UploadedFile[] $files
     * @return array{0: ?array, 1: ?string}
     */
    private function storeFilesOnFilesystem(array $files, array $field, \yannkost\easyform\models\Settings $settings): array
    {
        $subPath = $settings->uploadDateSubfolders ? date('Y') . '/' . date('m') : '';
        $targetDir = $settings->getResolvedFilesystemPath() . ($subPath !== '' ? '/' . $subPath : '');

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            EasyForm::log('Could not create upload directory: ' . $targetDir, 'error');
            return [null, Craft::t('easy-form', 'File upload error.')];
        }

        $allowed = !empty($field['allowedFileTypes'])
            ? array_filter(array_map('trim', explode(',', strtolower($field['allowedFileTypes']))))
            : [];
        $blocked = \yannkost\easyform\models\Settings::BLOCKED_UPLOAD_EXTENSIONS;

        $stored = [];
        try {
            foreach ($files as $file) {
                $ext = strtolower($file->getExtension() ?: pathinfo($file->name, PATHINFO_EXTENSION));
                // Strip anything that isn't a plain alphanumeric so a crafted
                // extension (trailing dots/spaces/null bytes) can't slip past the
                // blocklist or land in the on-disk filename.
                $ext = preg_replace('/[^a-z0-9]/', '', $ext);

                if ($ext === '' || in_array($ext, $blocked, true) || (!empty($allowed) && !in_array($ext, $allowed, true))) {
                    return [null, Craft::t('easy-form', 'File type “.{ext}” is not allowed.', ['ext' => $ext])];
                }

                $base = $this->sanitizeFilename(pathinfo($file->name, PATHINFO_FILENAME));
                $name = $base . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                $targetPath = $targetDir . '/' . $name;

                if (!$file->saveAs($targetPath)) {
                    EasyForm::log('Failed to store uploaded file at ' . $targetPath, 'error');
                    return [null, Craft::t('easy-form', 'File upload error.')];
                }
                @chmod($targetPath, 0644);

                $relative = ($subPath !== '' ? $subPath . '/' : '') . $name;
                $stored[] = [
                    'filename' => $file->name,
                    'path' => $relative,
                    'url' => $settings->getResolvedBaseUrl() . '/' . $relative,
                    'size' => $file->size,
                    'mimeType' => $file->type,
                ];
            }
        } catch (\Throwable $e) {
            EasyForm::log('Filesystem upload error: ' . $e->getMessage(), 'error');
            return [null, Craft::t('easy-form', 'File upload error.')];
        }

        return empty($stored) ? [null, null] : [['storage' => 'filesystem', 'files' => $stored], null];
    }

    /**
     * Reduce a filename to a safe, collision-friendly base segment.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = trim($name, '-');
        $name = substr($name, 0, 60);
        return $name !== '' ? $name : 'file';
    }

    /**
     * Resolve (creating if necessary) the target upload folder id.
     */
    private function resolveUploadFolderId($volume, ?string $subfolder): int
    {
        $rootFolder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);
        if (empty($subfolder)) {
            return $rootFolder->id;
        }

        $path = trim($subfolder, '/') . '/';
        $folder = Craft::$app->assets->findFolder(['volumeId' => $volume->id, 'path' => $path]);

        if (!$folder) {
            $folder = new \craft\models\VolumeFolder();
            $folder->volumeId = $volume->id;
            $folder->parentId = $rootFolder->id;
            $folder->path = $path;
            $folder->name = trim($subfolder, '/');
            Craft::$app->assets->createFolder($folder);
        }

        return $folder->id;
    }
    
    /**
     * Delete a submission
     */
    public function actionDelete(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $submissionId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $submission = EasyForm::getInstance()->submissions->getSubmissionById($submissionId);

        if (!$submission) {
            return $this->asJson([
                'success' => false,
                'error' => 'Submission not found'
            ]);
        }

        if (!EasyForm::getInstance()->submissions->deleteSubmissionById($submissionId)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Could not delete submission'
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Send a notification manually
     */
    public function actionSendNotification(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $submissionId = Craft::$app->getRequest()->getRequiredBodyParam('submissionId');
        $notificationIndex = Craft::$app->getRequest()->getRequiredBodyParam('notificationIndex');
        $recipientOverride = Craft::$app->getRequest()->getBodyParam('recipientOverride');

        EasyForm::debug("Manual notification request: Submission=$submissionId, Index=$notificationIndex, Override=" . ($recipientOverride ?: 'NULL'));

        $submission = EasyForm::getInstance()->submissions->getSubmissionById($submissionId);

        if (!$submission) {
            return $this->asJson([
                'success' => false,
                'error' => 'Submission not found'
            ]);
        }

        // Push to queue
        Craft::$app->getQueue()->push(new \yannkost\easyform\jobs\SendNotificationJob([
            'submissionId' => $submissionId,
            'notificationIndex' => (int)$notificationIndex,
            'recipientOverride' => $recipientOverride,
        ]));

        return $this->asJson(['success' => true, 'message' => 'Notification queued for sending.']);
    }
}
