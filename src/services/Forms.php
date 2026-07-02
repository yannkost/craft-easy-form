<?php

namespace yannkost\easyform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use yii\base\Component;
use yannkost\easyform\EasyForm;
use yannkost\easyform\events\FormEvent;
use yannkost\easyform\models\Form;
use yannkost\easyform\records\FormRecord;

/**
 * Forms service
 */
class Forms extends Component
{
    /**
     * @event FormEvent The event that is triggered before a form is saved.
     * Set `$event->isValid = false` to stop the save.
     */
    public const EVENT_BEFORE_SAVE_FORM = 'beforeSaveForm';

    /**
     * @event FormEvent The event that is triggered after a form is saved.
     * Useful for invalidating static/full-page caches that embed the form.
     */
    public const EVENT_AFTER_SAVE_FORM = 'afterSaveForm';

    /**
     * @event FormEvent The event that is triggered before a form is deleted.
     */
    public const EVENT_BEFORE_DELETE_FORM = 'beforeDeleteForm';

    /**
     * @event FormEvent The event that is triggered after a form is deleted.
     * Useful for invalidating static/full-page caches that embed the form.
     */
    public const EVENT_AFTER_DELETE_FORM = 'afterDeleteForm';

    /**
     * Returns all forms
     *
     * @return Form[]
     */
    public function getAllForms(): array
    {
        $records = FormRecord::find()
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $forms = [];
        foreach ($records as $record) {
            $forms[] = $this->createFormFromRecord($record);
        }

        return $forms;
    }

    /**
     * Returns a query for forms, optionally filtered by a name/handle search.
     */
    public function getFormsQuery(?string $search = null): \yii\db\ActiveQuery
    {
        $query = FormRecord::find()->orderBy(['name' => SORT_ASC]);

        if ($search !== null && $search !== '') {
            $query->andWhere(['or', ['like', 'name', $search], ['like', 'handle', $search]]);
        }

        return $query;
    }

    /**
     * Hydrates a Form model from a record (exposed for paginated listings).
     */
    public function formFromRecord(FormRecord $record): Form
    {
        return $this->createFormFromRecord($record);
    }

    /**
     * Returns enabled forms only
     *
     * @return Form[]
     */
    public function getEnabledForms(): array
    {
        $records = FormRecord::find()
            ->where(['enabled' => true])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $forms = [];
        foreach ($records as $record) {
            $forms[] = $this->createFormFromRecord($record);
        }

        return $forms;
    }

    /**
     * Returns a form by its ID
     *
     * @param int $id
     * @return Form|null
     */
    public function getFormById(int $id): ?Form
    {
        $record = FormRecord::findOne($id);

        if (!$record) {
            return null;
        }

        return $this->createFormFromRecord($record);
    }

    /**
     * Returns a form by its handle
     *
     * @param string $handle
     * @return Form|null
     */
    public function getFormByHandle(string $handle): ?Form
    {
        $record = FormRecord::findOne(['handle' => $handle]);

        if (!$record) {
            return null;
        }

        return $this->createFormFromRecord($record);
    }

    /**
     * Saves a form
     *
     * @param Form $form
     * @param bool $runValidation
     * @return bool
     */
    public function saveForm(Form $form, bool $runValidation = true): bool
    {
        if ($runValidation && !$form->validate()) {
            EasyForm::debug('Form not saved due to validation error: ' . Json::encode($form->getErrors()));
            return false;
        }

        $isNew = !$form->id;

        // Fire a 'beforeSaveForm' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_FORM)) {
            $event = new FormEvent([
                'form' => $form,
                'isNew' => $isNew,
            ]);
            $this->trigger(self::EVENT_BEFORE_SAVE_FORM, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        if (!$isNew) {
            $record = FormRecord::findOne($form->id);
            if (!$record) {
                throw new \Exception('Invalid form ID: ' . $form->id);
            }
        } else {
            $record = new FormRecord();
        }

        // Set attributes
        $record->name = $form->name;
        $record->handle = $form->handle;
        $record->description = $form->description;
        // Hand arrays to JSON columns directly — Craft/Yii encodes them
        // automatically, so pre-encoding would double-encode.
        $record->fieldLayout = is_string($form->fieldLayout)
            ? (Json::decodeIfJson($form->fieldLayout) ?: [])
            : $form->fieldLayout;

        // Persist site-specific messages in settings
        $settings = $form->getSettingsArray();
        $settings['siteSuccessMessages'] = $form->siteSuccessMessages;
        $settings['siteErrorMessages'] = $form->siteErrorMessages;
        $settings['siteRedirectUrls'] = $form->siteRedirectUrls;
        $settings['submitButtonLabel'] = $form->submitButtonLabel;
        $settings['siteSubmitButtonLabels'] = $form->siteSubmitButtonLabels;
        $form->settings = $settings;

        $record->settings = is_string($form->settings)
            ? Json::decodeIfJson($form->settings)
            : $form->settings;
        $record->notificationSettings = is_string($form->notificationSettings)
            ? Json::decodeIfJson($form->notificationSettings)
            : $form->notificationSettings;
        $record->enabled = $form->enabled;
        $record->successMessage = $form->successMessage;
        $record->redirectUrl = $form->redirectUrl;
        $record->hideFormOnSuccess = $form->hideFormOnSuccess;
        $record->keepSuccessMessage = $form->keepSuccessMessage;
        $record->successMessageDuration = max(1, (int) $form->successMessageDuration);
        $record->maxSubmissionsPerUser = $form->maxSubmissionsPerUser;
        $record->rateLimit = max(0, (int) $form->rateLimit);
        $record->rateLimitWindow = max(1, (int) $form->rateLimitWindow);
        $record->saveSpamSubmissions = $form->saveSpamSubmissions;
        $record->autoApprove = $form->autoApprove;
        $record->captchaProvider = $form->captchaProvider ?: null;
        $record->captchaScoreThreshold = $form->captchaScoreThreshold;
        $record->rejectOnCaptchaFail = $form->rejectOnCaptchaFail;
        $record->allowUrlPrefill = $form->allowUrlPrefill;
        $record->showStepIndicator = $form->showStepIndicator;
        $record->validateSteps = $form->validateSteps;
        $record->webhookUrl = $form->webhookUrl ?: null;
        $record->webhookPayload = in_array($form->webhookPayload, ['full', 'data'], true) ? $form->webhookPayload : 'full';

        try {
            if (!$record->save(false)) {
                EasyForm::log('Could not save form: ' . Json::encode($record->getErrors()), 'error');
                return false;
            }
        } catch (\Throwable $e) {
            EasyForm::log('Exception while saving form: ' . $e->getMessage(), 'error');
            EasyForm::debug($e->getTraceAsString());
            return false;
        }

        // Update the model with the saved record's data
        $form->id = $record->id;
        $form->dateCreated = $record->dateCreated;
        $form->dateUpdated = $record->dateUpdated;
        $form->uid = $record->uid;

        // Fire an 'afterSaveForm' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_FORM)) {
            $this->trigger(self::EVENT_AFTER_SAVE_FORM, new FormEvent([
                'form' => $form,
                'isNew' => $isNew,
            ]));
        }

        return true;
    }

    /**
     * Deletes a form by its ID
     *
     * @param int $id
     * @return bool
     */
    public function deleteFormById(int $id): bool
    {
        $record = FormRecord::findOne($id);

        if (!$record) {
            return false;
        }

        // Build the model up front so event handlers get the form's handle
        // (needed to target cache invalidation), not just its id.
        $form = $this->createFormFromRecord($record);

        // Fire a 'beforeDeleteForm' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_FORM)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_FORM, new FormEvent([
                'form' => $form,
            ]));
        }

        try {
            if (!$record->delete()) {
                return false;
            }
        } catch (\Throwable $e) {
            EasyForm::log('Could not delete form #' . $id . ': ' . $e->getMessage(), 'error');
            return false;
        }

        // Fire an 'afterDeleteForm' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_FORM)) {
            $this->trigger(self::EVENT_AFTER_DELETE_FORM, new FormEvent([
                'form' => $form,
            ]));
        }

        return true;
    }

    /**
     * Deletes a form
     *
     * @param Form $form
     * @return bool
     */
    public function deleteForm(Form $form): bool
    {
        if (!$form->id) {
            return false;
        }

        return $this->deleteFormById($form->id);
    }

    /**
     * Returns the total number of forms
     *
     * @return int
     */
    public function getTotalForms(): int
    {
        return FormRecord::find()->count();
    }

    /**
     * Returns the total number of enabled forms
     *
     * @return int
     */
    public function getTotalEnabledForms(): int
    {
        return FormRecord::find()->where(['enabled' => true])->count();
    }

    /**
     * Creates a Form model from a FormRecord
     *
     * @param FormRecord $record
     * @return Form
     */
    private function createFormFromRecord(FormRecord $record): Form
    {
        $form = new Form();
        $form->id = $record->id;
        $form->name = $record->name;
        $form->handle = $record->handle;
        $form->description = $record->description;
        // Normalize fieldLayout to latest schema version on load
        $rawLayout = $record->fieldLayout;
        if (is_string($rawLayout)) {
            $rawLayout = Json::decodeIfJson($rawLayout);
        }
        if (is_array($rawLayout)) {
            $form->fieldLayout = EasyForm::getInstance()->formSchema->normalize($rawLayout);
        } else {
            $form->fieldLayout = EasyForm::getInstance()->formSchema->createEmptyLayout();
        }
        $form->settings = $record->settings;
        
        // Populate site-specific messages from settings
        $settings = $form->getSettingsArray();
        $form->siteSuccessMessages = $settings['siteSuccessMessages'] ?? [];
        $form->siteErrorMessages = $settings['siteErrorMessages'] ?? [];
        $form->siteRedirectUrls = $settings['siteRedirectUrls'] ?? [];
        $form->submitButtonLabel = $settings['submitButtonLabel'] ?? null;
        $form->siteSubmitButtonLabels = $settings['siteSubmitButtonLabels'] ?? [];
        
        $form->notificationSettings = $record->notificationSettings;
        $form->enabled = (bool) $record->enabled;
        $form->successMessage = $record->successMessage;
        $form->redirectUrl = $record->redirectUrl;
        $form->hideFormOnSuccess = (bool) $record->hideFormOnSuccess;
        $form->keepSuccessMessage = (bool) $record->keepSuccessMessage;
        $form->successMessageDuration = (int) $record->successMessageDuration;
        $form->maxSubmissionsPerUser = $record->maxSubmissionsPerUser;
        $form->rateLimit = (int) $record->rateLimit;
        $form->rateLimitWindow = (int) $record->rateLimitWindow;
        $form->saveSpamSubmissions = (bool) $record->saveSpamSubmissions;
        $form->autoApprove = (bool) $record->autoApprove;
        $form->captchaProvider = $record->captchaProvider;
        $form->captchaScoreThreshold = $record->captchaScoreThreshold !== null ? (float) $record->captchaScoreThreshold : null;
        $form->rejectOnCaptchaFail = (bool) $record->rejectOnCaptchaFail;
        $form->allowUrlPrefill = (bool) $record->allowUrlPrefill;
        $form->showStepIndicator = (bool) $record->showStepIndicator;
        $form->validateSteps = (bool) $record->validateSteps;
        $form->webhookUrl = $record->webhookUrl;
        $form->webhookPayload = $record->webhookPayload ?: 'full';
        $form->dateCreated = $record->dateCreated;
        $form->dateUpdated = $record->dateUpdated;
        $form->uid = $record->uid;

        return $form;
    }

    /**
     * Returns forms as options for select inputs
     *
     * @return array
     */
    public function getFormOptions(): array
    {
        $forms = $this->getAllForms();
        $options = [];

        foreach ($forms as $form) {
            $options[] = [
                'label' => $form->name,
                'value' => $form->id,
            ];
        }

        return $options;
    }

    /**
     * Returns the first available handle in the series `$base`, `"{$base}1"`,
     * `"{$base}2"`, … using the given existence check.
     *
     * Used by form duplication, where the copy needs a fresh, collision-free
     * handle. The existence check is injected so the loop can be unit-tested
     * without the database.
     *
     * @param callable(string):bool $handleExists returns true when a form already uses the handle
     */
    public function uniqueHandle(string $base, callable $handleExists): string
    {
        $handle = $base;
        $counter = 1;

        while ($handleExists($handle)) {
            $handle = $base . $counter;
            $counter++;
        }

        return $handle;
    }

    /**
     * Copies every duplicable setting from one form onto another: the field
     * layout plus all behavior, per-site message, spam, notification and
     * webhook configuration.
     *
     * Identity attributes (id, name, handle, uid, timestamps) are intentionally
     * left untouched — the caller owns those.
     */
    public function copyDuplicableSettings(Form $source, Form $target): void
    {
        $target->description = $source->description;
        $target->fieldLayout = $source->fieldLayout;
        $target->settings = $source->settings;
        $target->notificationSettings = $source->notificationSettings;
        $target->enabled = $source->enabled;
        $target->successMessage = $source->successMessage;
        $target->siteSuccessMessages = $source->siteSuccessMessages;
        $target->siteErrorMessages = $source->siteErrorMessages;
        $target->siteRedirectUrls = $source->siteRedirectUrls;
        $target->submitButtonLabel = $source->submitButtonLabel;
        $target->siteSubmitButtonLabels = $source->siteSubmitButtonLabels;
        $target->redirectUrl = $source->redirectUrl;
        $target->hideFormOnSuccess = $source->hideFormOnSuccess;
        $target->keepSuccessMessage = $source->keepSuccessMessage;
        // Clamp like the save/import paths do — legacy rows may hold 0, which
        // would fail the duplicate's `min => 1` validation on save.
        $target->successMessageDuration = max(1, (int) $source->successMessageDuration);
        $target->maxSubmissionsPerUser = $source->maxSubmissionsPerUser;
        $target->rateLimit = $source->rateLimit;
        $target->rateLimitWindow = $source->rateLimitWindow;
        $target->saveSpamSubmissions = $source->saveSpamSubmissions;
        $target->autoApprove = $source->autoApprove;
        $target->captchaProvider = $source->captchaProvider;
        $target->captchaScoreThreshold = $source->captchaScoreThreshold;
        $target->rejectOnCaptchaFail = $source->rejectOnCaptchaFail;
        $target->allowUrlPrefill = $source->allowUrlPrefill;
        $target->showStepIndicator = $source->showStepIndicator;
        $target->validateSteps = $source->validateSteps;
        $target->webhookUrl = $source->webhookUrl;
        $target->webhookPayload = $source->webhookPayload;
    }
}
