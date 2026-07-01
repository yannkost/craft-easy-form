<?php

namespace yannkost\easyform\controllers;

use Craft;
use craft\web\Controller;
use yannkost\easyform\EasyForm;

/**
 * Settings Controller
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('easy-form:manageForms');
        return true;
    }

    /**
     * Global settings page
     */
    public function actionIndex()
    {
        $settings = EasyForm::getInstance()->getSettings();

        return $this->renderTemplate('easy-form/settings/index', [
            'settings' => $settings,
            'forms' => EasyForm::getInstance()->forms->getAllForms(),
        ]);
    }

    /**
     * Queue an on-demand prune of submissions older than the configured retention period.
     */
    public function actionPruneNow()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $days = EasyForm::getInstance()->getSettings()->submissionRetentionDays;
        if ($days === null || (int) $days < 1) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('easy-form', 'Set a retention period first.'),
            ]);
        }

        try {
            Craft::$app->getQueue()->push(new \yannkost\easyform\jobs\PruneSubmissions([
                'days' => (int) $days,
            ]));
        } catch (\Throwable $e) {
            EasyForm::log('Could not queue prune job: ' . $e->getMessage(), 'error');
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('easy-form', 'Could not start pruning.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('easy-form', 'Pruning queued — old submissions will be removed in the background.'),
        ]);
    }

    /**
     * Queue a re-index of a form's promoted/search columns for existing submissions.
     */
    public function actionReindex()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $formId = (int) Craft::$app->request->getRequiredBodyParam('formId');
        $form = EasyForm::getInstance()->forms->getFormById($formId);
        if (!$form) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('easy-form', 'Form not found.'),
            ]);
        }

        try {
            Craft::$app->getQueue()->push(new \yannkost\easyform\jobs\ReindexSubmissions([
                'formId' => $form->id,
            ]));
        } catch (\Throwable $e) {
            EasyForm::log('Could not queue re-index job for form #' . $form->id . ': ' . $e->getMessage(), 'error');
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('easy-form', 'Could not start re-indexing.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('easy-form', 'Re-index queued for “{form}”.', ['form' => $form->name]),
        ]);
    }
    
    /**
     * Save global settings
     */
    public function actionSave()
    {
        $this->requirePostRequest();
        
        $settings = EasyForm::getInstance()->getSettings();
        $settings->setAttributes(Craft::$app->request->getBodyParams(), false);
        
        try {
            if (!Craft::$app->plugins->savePluginSettings(EasyForm::getInstance(), $settings->toArray())) {
                EasyForm::log('Could not save plugin settings: ' . json_encode($settings->getErrors()), 'error');
                Craft::$app->session->setError(Craft::t('easy-form', 'Could not save settings.'));
                return null;
            }
        } catch (\Throwable $e) {
            EasyForm::log('Exception while saving settings: ' . $e->getMessage(), 'error');
            Craft::$app->session->setError(Craft::t('easy-form', 'Could not save settings.'));
            return null;
        }
        
        Craft::$app->session->setNotice(Craft::t('easy-form', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
