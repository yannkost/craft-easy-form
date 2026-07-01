<?php

namespace yannkost\easyform\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yannkost\easyform\EasyForm;
use yii\web\Response;

/**
 * GDPR / privacy tooling: export or erase all submissions for an email address.
 */
class PrivacyController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Erasing personal data needs delete; reading/exporting needs view.
        $this->requirePermission(
            $action->id === 'erase' ? 'easy-form:deleteSubmissions' : 'easy-form:viewSubmissions'
        );
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('easy-form/privacy/index', [
            'email' => Craft::$app->request->getQueryParam('email', ''),
        ]);
    }

    /**
     * Download a JSON export of everything held for an email address.
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $email = trim((string) Craft::$app->request->getRequiredBodyParam('email'));

        if ($email === '') {
            Craft::$app->session->setError(Craft::t('easy-form', 'Please enter an email address.'));
            return $this->redirect('easy-form/privacy');
        }

        $data = EasyForm::getInstance()->submissions->exportDataForEmail($email);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="easy-form-data-' . preg_replace('/[^a-z0-9]+/i', '-', $email) . '.json"');
        $response->data = Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /**
     * Permanently erase all submissions for an email address.
     */
    public function actionErase(): Response
    {
        $this->requirePostRequest();
        $email = trim((string) Craft::$app->request->getRequiredBodyParam('email'));

        if ($email === '') {
            Craft::$app->session->setError(Craft::t('easy-form', 'Please enter an email address.'));
            return $this->redirect('easy-form/privacy');
        }

        $count = EasyForm::getInstance()->submissions->deleteSubmissionsForEmail($email);

        Craft::$app->session->setNotice(Craft::t('easy-form', 'Erased {count} submission(s).', ['count' => $count]));
        return $this->redirect('easy-form/privacy');
    }
}
