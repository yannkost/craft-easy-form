<?php

namespace yannkost\easyform;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Fields;
use craft\services\Gc;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use yii\base\Event;
use yii\log\FileTarget;
use yannkost\easyform\controllers\SubmissionsController;
use yannkost\easyform\events\SubmissionEvent;
use yannkost\easyform\fields\FormField;
use yannkost\easyform\models\Settings;
use yannkost\easyform\services\Captcha;
use yannkost\easyform\services\ConditionEvaluator;
use yannkost\easyform\services\Exports;
use yannkost\easyform\services\Forms;
use yannkost\easyform\services\FormSchemaService;
use yannkost\easyform\services\Notifications;
use yannkost\easyform\services\SubmissionDataService;
use yannkost\easyform\services\Submissions;
use yannkost\easyform\services\ValidationService;
use yannkost\easyform\services\Webhooks;
use yannkost\easyform\twigextensions\EasyFormTwigExtension;
use yannkost\easyform\jobs\SendNotificationJob;
use yannkost\easyform\jobs\SendWebhookJob;

/**
 * EasyForm plugin
 *
 * @method static EasyForm getInstance()
 * @property-read Forms $forms
 * @property-read Submissions $submissions
 * @property-read ValidationService $validation
 * @property-read Notifications $notifications
 * @property-read FormSchemaService $formSchema
 * @property-read ConditionEvaluator $conditionEvaluator
 * @property-read SubmissionDataService $submissionData
 * @property-read Captcha $captcha
 * @property-read Webhooks $webhooks
 */
class EasyForm extends Plugin
{
    public string $schemaVersion = '2.9.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        
        // Register custom logger
        $this->registerLogTarget();

        // Register components
        $this->setComponents([
            'forms' => Forms::class,
            'submissions' => Submissions::class,
            'validation' => ValidationService::class,
            'notifications' => Notifications::class,
            'formSchema' => FormSchemaService::class,
            'conditionEvaluator' => ConditionEvaluator::class,
            'submissionData' => SubmissionDataService::class,
            'captcha' => Captcha::class,
            'webhooks' => Webhooks::class,
            'exports' => Exports::class,
        ]);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'yannkost\\easyform\\console\\controllers';
        } else {
            $this->controllerNamespace = 'yannkost\\easyform\\controllers';
            
            // Explicitly map controllers to ensure routing works reliably
            $this->controllerMap = [
                'submissions' => SubmissionsController::class,
            ];
        }

        // Register Twig extension
        Craft::$app->view->registerTwigExtension(new EasyFormTwigExtension());

        $this->attachEventHandlers();

        // Auto-prune old submissions during Craft's garbage collection when a
        // retention period is configured. Runs off the request cycle, so it's a
        // no-op until `submissionRetentionDays` is set.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function () {
                try {
                    $days = EasyForm::getInstance()->getSettings()->submissionRetentionDays;
                    if ($days !== null && (int) $days >= 1) {
                        EasyForm::getInstance()->submissions->pruneOldSubmissions((int) $days);
                    }
                } catch (\Throwable $e) {
                    // Never let our handler break Craft's GC chain for other plugins.
                    EasyForm::log('Auto-prune during GC failed: ' . $e->getMessage(), 'error');
                }
            }
        );

        // Queue notifications after a new submission is saved.
        Event::on(
            Submissions::class,
            Submissions::EVENT_AFTER_SAVE_SUBMISSION,
            function (SubmissionEvent $event) {
                if (!$event->isNew) {
                    return;
                }
                $submission = $event->submission;

                // Skip spam for both notifications and webhooks — a saved spam
                // submission must not email admins (or, worse, a {email} dynamic
                // recipient the bot supplied) or fire outbound webhooks.
                if ($submission->status === 'spam') {
                    return;
                }

                try {
                    EasyForm::getInstance()->notifications->queueForSubmission($submission);
                } catch (\Throwable $e) {
                    // Never let a queue failure break a saved submission.
                    EasyForm::log('Could not queue notification job for submission #' . $submission->id . ': ' . $e->getMessage(), 'error');
                }

                // Queue a webhook when the form has one.
                $form = EasyForm::getInstance()->forms->getFormById($submission->formId);
                if ($form && trim((string) $form->webhookUrl) !== '') {
                    try {
                        Craft::$app->getQueue()->push(new SendWebhookJob([
                            'submissionId' => $submission->id,
                        ]));
                    } catch (\Throwable $e) {
                        EasyForm::log('Could not queue webhook job for submission #' . $submission->id . ': ' . $e->getMessage(), 'error');
                    }
                }
            }
        );

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function () {
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('easy-form/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register Craft variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('easyForm', EasyForm::class);
            }
        );

        // Register field types
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = FormField::class;
            }
        );

        // Register CP permissions (admins implicitly have all of them).
        Event::on(
            \craft\services\UserPermissions::class,
            \craft\services\UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (\craft\events\RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('easy-form', 'Easy Form'),
                    'permissions' => [
                        'easy-form:manageForms' => ['label' => Craft::t('easy-form', 'Manage forms & settings')],
                        'easy-form:viewSubmissions' => ['label' => Craft::t('easy-form', 'View submissions')],
                        'easy-form:editSubmissions' => ['label' => Craft::t('easy-form', 'Edit submissions')],
                        'easy-form:exportSubmissions' => ['label' => Craft::t('easy-form', 'Export submissions')],
                        'easy-form:deleteSubmissions' => ['label' => Craft::t('easy-form', 'Delete submissions')],
                    ],
                ];
            }
        );

        // Register CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['easy-form'] = 'easy-form/forms/index';
                $event->rules['easy-form/forms'] = 'easy-form/forms/index';
                $event->rules['easy-form/forms/new'] = 'easy-form/forms/edit';
                $event->rules['easy-form/forms/<formId:\\d+>'] = 'easy-form/forms/edit';
                $event->rules['easy-form/forms/<formId:\\d+>/submissions'] = 'easy-form/submissions/index';
                $event->rules['easy-form/forms/<formId:\\d+>/export'] = 'easy-form/submissions/export';
                $event->rules['easy-form/forms/log-client-error'] = 'easy-form/forms/log-client-error';
                $event->rules['easy-form/submissions'] = 'easy-form/submissions/all';
                $event->rules['easy-form/submissions/export'] = 'easy-form/submissions/export';
                $event->rules['easy-form/exports'] = 'easy-form/exports/index';
                $event->rules['easy-form/exports/saved'] = 'easy-form/exports/saved';
                $event->rules['easy-form/exports/status'] = 'easy-form/submissions/export-status';
                $event->rules['easy-form/exports/download'] = 'easy-form/submissions/export-download';
                $event->rules['easy-form/submissions/<submissionId:\\d+>'] = 'easy-form/submissions/view';
                $event->rules['easy-form/submissions/<submissionId:\\d+>/edit'] = 'easy-form/submissions/edit';
                $event->rules['easy-form/privacy'] = 'easy-form/privacy/index';
                $event->rules['easy-form/settings'] = 'easy-form/settings/index';
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Easy Forms';
        $item['url'] = 'easy-form';

        $item['subnav'] = [
            'forms' => [
                'label' => 'Forms',
                'url' => 'easy-form/forms',
            ],
            'submissions' => [
                'label' => 'Submissions',
                'url' => 'easy-form/submissions',
            ],
            'exports' => [
                'label' => 'Exports',
                'url' => 'easy-form/exports',
            ],
            'privacy' => [
                'label' => 'Privacy',
                'url' => 'easy-form/privacy',
            ],
            'settings' => [
                'label' => 'Settings',
                'url' => 'easy-form/settings',
            ],
        ];

        return $item;
    }

    /**
     * Registers the custom log target
     */
    private function registerLogTarget(): void
    {
        // One file per day (easy-form-YYYY-MM-DD.log) so logs stay searchable
        // instead of growing into a single unbounded file. The date is resolved
        // per request at init, so each day's entries land in their own file.
        // enableRotation is off — dating the filename is the rotation strategy,
        // and Yii's size-based rotation would fight it.
        Craft::getLogger()->dispatcher->targets[] = new FileTarget([
            'logFile' => '@storage/logs/easy-form-' . date('Y-m-d') . '.log',
            'enableRotation' => false,
            'categories' => ['easy-form'],
            'logVars' => [], // Disable logging of global variables (_GET, _POST, etc.)
            'prefix' => function() {
                return null; // Remove default prefix [IP][User][Session]
            },
        ]);
    }

    /**
     * Whether verbose debug logging is enabled.
     *
     * Controlled by the EASY_FORM_DEBUG environment variable (or constant).
     * When disabled, only warnings/errors are written and verbose info logs
     * (payloads, recipients, layouts) are suppressed.
     */
    public static function isDebugEnabled(): bool
    {
        $value = \craft\helpers\App::env('EASY_FORM_DEBUG');
        if ($value === null && defined('EASY_FORM_DEBUG')) {
            $value = EASY_FORM_DEBUG;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Logs a message to the custom log file.
     *
     * `info` messages are only written when debug logging is enabled, so the
     * plugin does not leak payloads/recipients/layouts by default.
     */
    public static function log(string $message, string $level = 'info'): void
    {
        $category = 'easy-form';

        switch ($level) {
            case 'error':
                Craft::error($message, $category);
                break;
            case 'warning':
                Craft::warning($message, $category);
                break;
            default:
                if (self::isDebugEnabled()) {
                    Craft::info($message, $category);
                }
                break;
        }
    }

    /**
     * Logs a verbose, opt-in debug message. No-op unless EASY_FORM_DEBUG is on.
     */
    public static function debug(string $message, string $level = 'info'): void
    {
        if (!self::isDebugEnabled()) {
            return;
        }
        Craft::info($message, 'easy-form');
    }
}
