<?php

namespace yannkost\easyform\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use yannkost\easyform\EasyForm;

/**
 * Exports hub: lists every form so submissions can be exported (with per-export
 * site / status / date filters chosen in a modal). The actual CSV is streamed by
 * SubmissionsController::actionExport.
 */
class ExportsController extends Controller
{
    use PaginatesTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // The count endpoint backs the delete confirmation, so gate it as delete.
        $this->requirePermission(match ($action->id) {
            'count', 'delete' => 'easy-form:deleteSubmissions',
            'saved', 'delete-saved' => 'easy-form:exportSubmissions',
            default => 'easy-form:viewSubmissions',
        });
        return true;
    }

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

        $counts = EasyForm::getInstance()->submissions->getCountsByFormIds(
            array_map(static fn($f) => (int) $f->id, $forms)
        );

        return $this->renderTemplate('easy-form/exports/index', [
            'forms' => $forms,
            'submissionCounts' => $counts,
            'sites' => Craft::$app->sites->getAllSites(),
            'search' => $search,
            'pages' => $pages,
            'limit' => $limit,
        ]);
    }

    /**
     * The "Saved exports" list: kept exports anyone with export access can
     * download or delete.
     */
    public function actionSaved(): Response
    {
        $limit = self::resolvePageSize(Craft::$app->getRequest()->getQueryParam('limit'));
        $exports = EasyForm::getInstance()->exports;

        $query = $exports->getSavedQuery();
        $pages = new \yii\data\Pagination([
            'totalCount' => (int) $query->count(),
            'defaultPageSize' => $limit,
            'pageSize' => $limit,
        ]);

        /** @var \yannkost\easyform\records\ExportRecord[] $records */
        $records = $query->offset($pages->offset)->limit($pages->limit)->all();

        $rows = array_map(function ($record) use ($exports) {
            return [
                'token' => $record->token,
                'formId' => $record->formId,
                'formName' => $record->formName,
                'filters' => $exports->decodeFilters($record),
                'filename' => $record->filename,
                'rowCount' => $record->rowCount,
                'fileSize' => $record->fileSize,
                'status' => $record->status,
                'createdBy' => $record->creator
                    ? (trim((string) $record->creator->fullName) ?: $record->creator->username)
                    : null,
                'dateCreated' => $record->dateCreated,
            ];
        }, $records);

        return $this->renderTemplate('easy-form/exports/saved', [
            'exports' => $rows,
            'pages' => $pages,
            'limit' => $limit,
        ]);
    }

    /**
     * Deletes a saved export (its file + row).
     */
    public function actionDeleteSaved(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $token = (string) Craft::$app->request->getRequiredBodyParam('token');
        $exports = EasyForm::getInstance()->exports;
        $record = $exports->getByToken($token);
        if ($record) {
            $exports->delete($record);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * How many submissions match the given form + filters (for the delete
     * confirmation step).
     */
    public function actionCount(): Response
    {
        $this->requireAcceptsJson();

        $formId = (int) Craft::$app->request->getRequiredParam('formId');
        [$status, $siteId, $dateFrom, $dateTo] = $this->filtersFromRequest();

        $count = EasyForm::getInstance()->submissions
            ->countSubmissionsByFilters($formId, $status, $siteId, $dateFrom, $dateTo);

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * Permanently deletes the submissions matching a form + filters.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $formId = (int) Craft::$app->request->getRequiredBodyParam('formId');
        [$status, $siteId, $dateFrom, $dateTo] = $this->filtersFromRequest();

        $deleted = EasyForm::getInstance()->submissions
            ->deleteSubmissionsByFilters($formId, $status, $siteId, $dateFrom, $dateTo);

        return $this->asJson(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Reads + validates the shared site / status / date-range filters.
     *
     * @return array{0:?string,1:?int,2:?string,3:?string} [status, siteId, dateFrom, dateTo]
     */
    private function filtersFromRequest(): array
    {
        $request = Craft::$app->request;

        $status = $request->getParam('status') ?: null;
        if (!in_array($status, ['pending', 'approved', 'spam', 'archived'], true)) {
            $status = null;
        }
        $siteId = (int) $request->getParam('siteId') ?: null;
        $dateFrom = $this->validIsoDate($request->getParam('dateFrom'));
        $dateTo = $this->validIsoDate($request->getParam('dateTo'));

        return [$status, $siteId, $dateFrom, $dateTo];
    }

    /**
     * Returns the value only if it is a strict YYYY-MM-DD date, else null.
     */
    private function validIsoDate($value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
