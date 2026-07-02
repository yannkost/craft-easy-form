<?php

/**
 * Test bootstrap.
 *
 * The service classes under test have only thin couplings to Craft/Yii:
 *   - they extend yii\base\Component
 *   - they call EasyForm::log()/debug() and EasyForm::getInstance()
 *   - SubmissionDataService references the Form/Submission models
 *
 * Rather than boot a full Craft application, we stub those seams so the real
 * service logic can be unit-tested in isolation with no external dependencies.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('Craft', false)) {
        /**
         * Minimal stand-in for the global Craft helper. Only Craft::t() is used by
         * the service logic under test (label strings), so that's all we provide.
         */
        class Craft
        {
            public static function t(string $category, string $message, array $params = []): string
            {
                if ($params === []) {
                    return $message;
                }
                $replace = [];
                foreach ($params as $key => $value) {
                    $replace['{' . $key . '}'] = (string) $value;
                }
                return strtr($message, $replace);
            }
        }
    }
}

namespace yii\base {
    if (!class_exists(Event::class, false)) {
        /**
         * Minimal stand-in for yii\base\Event. The real class extends
         * BaseObject, whose constructor assigns a config array to public
         * properties — which is how the plugin builds events
         * (`new FormEvent(['form' => $form, ...])`).
         */
        class Event
        {
            public function __construct(array $config = [])
            {
                foreach ($config as $name => $value) {
                    $this->$name = $value;
                }
            }
        }
    }

    if (!class_exists(Component::class, false)) {
        /**
         * Minimal stand-in for yii\base\Component providing just the event
         * machinery the services rely on (on / trigger / hasEventHandlers),
         * backed by a plain handler map. Enough to assert that plugin events
         * fire and that handlers can mutate the passed event object.
         */
        class Component
        {
            private array $_eventHandlers = [];

            public function on(string $name, callable $handler): void
            {
                $this->_eventHandlers[$name][] = $handler;
            }

            public function hasEventHandlers(string $name): bool
            {
                return !empty($this->_eventHandlers[$name]);
            }

            public function trigger(string $name, ?Event $event = null): void
            {
                foreach ($this->_eventHandlers[$name] ?? [] as $handler) {
                    $handler($event);
                }
            }
        }
    }
}

namespace yannkost\easyform {
    if (!class_exists(EasyForm::class, false)) {
        class EasyForm
        {
            /** @var object Service container exposing ->formSchema, ->conditionEvaluator, ->submissionData */
            public static object $container;

            public static function getInstance(): object
            {
                return self::$container;
            }

            public static function log(string $message, string $level = 'info'): void {}
            public static function debug(string $message, string $level = 'info'): void {}
        }
    }
}

namespace yannkost\easyform\models {
    if (!class_exists(Submission::class, false)) {
        class Submission
        {
            public const SCHEMA_VERSION = 1;
        }
    }

    if (!class_exists(Settings::class, false)) {
        /**
         * Minimal stand-in for the Craft-backed Settings model. resolve() here
         * returns the value verbatim (no env expansion) so provider logic can be
         * tested directly.
         */
        class Settings
        {
            public const BLOCKED_UPLOAD_EXTENSIONS = ['php', 'phar', 'exe'];

            public string $turnstileSiteKey = '';
            public string $turnstileSecret = '';
            public string $recaptchaV3SiteKey = '';
            public string $recaptchaV3Secret = '';
            public float $recaptchaV3ScoreThreshold = 0.5;
            public string $recaptchaV2SiteKey = '';
            public string $recaptchaV2Secret = '';

            public function resolve(string $value): string
            {
                return $value;
            }
        }
    }

    if (!class_exists(Form::class, false)) {
        /**
         * Minimal stand-in for the Craft-backed Form model. Tests assign a raw
         * layout; getNormalizedLayout() runs it through the real schema service.
         *
         * Allows dynamic properties so the Forms service can freely read/write
         * the model's many attributes without us declaring every one.
         */
        #[\AllowDynamicProperties]
        class Form
        {
            public array $layout = [];
            public ?int $id = null;
            public string $handle = 'testForm';
            public string $name = 'Test Form';
            public ?string $description = null;
            public mixed $fieldLayout = [];
            public mixed $settings = [];
            public mixed $notificationSettings = [];
            public array $siteSuccessMessages = [];
            public array $siteErrorMessages = [];
            public array $siteRedirectUrls = [];
            public ?string $submitButtonLabel = null;
            public array $siteSubmitButtonLabels = [];
            public bool $enabled = true;
            public ?string $successMessage = null;
            public ?string $redirectUrl = null;
            public bool $hideFormOnSuccess = false;
            public bool $keepSuccessMessage = true;
            public int $successMessageDuration = 5;
            public ?int $maxSubmissionsPerUser = null;
            public int $rateLimit = 0;
            public int $rateLimitWindow = 60;
            public bool $saveSpamSubmissions = false;
            public bool $autoApprove = true;
            public ?string $captchaProvider = null;
            public ?float $captchaScoreThreshold = null;
            public bool $rejectOnCaptchaFail = false;
            public bool $allowUrlPrefill = false;
            public bool $showStepIndicator = false;
            public bool $validateSteps = true;
            public ?string $webhookUrl = null;
            public string $webhookPayload = 'full';
            public ?string $dateCreated = null;
            public ?string $dateUpdated = null;
            public ?string $uid = null;

            /** Lets a test force validation failure. */
            public bool $valid = true;
            public array $errors = [];

            public function validate(): bool
            {
                return $this->valid;
            }

            public function getErrors(): array
            {
                return $this->errors;
            }

            public function getSettingsArray(): array
            {
                return is_array($this->settings) ? $this->settings : [];
            }

            public function getNormalizedLayout(): array
            {
                return \yannkost\easyform\EasyForm::getInstance()->formSchema->normalize($this->layout);
            }
        }
    }
}

namespace craft\helpers {
    if (!class_exists(Json::class, false)) {
        /** Minimal Json helper used by the Forms service on string inputs. */
        class Json
        {
            public static function encode($value): string
            {
                return json_encode($value);
            }

            public static function decodeIfJson($value)
            {
                if (!is_string($value)) {
                    return $value;
                }
                $decoded = json_decode($value, true);
                return $decoded === null ? $value : $decoded;
            }
        }
    }
}

namespace yannkost\easyform\records {
    if (!class_exists(FormRecord::class, false)) {
        /**
         * In-memory stand-in for the FormRecord ActiveRecord. Tests control
         * what findOne() returns and whether save()/delete() succeed, so the
         * Forms service runs without a database.
         */
        #[\AllowDynamicProperties]
        class FormRecord
        {
            public ?int $id = null;
            public ?string $dateCreated = null;
            public ?string $dateUpdated = null;
            public ?string $uid = null;
            public string $name = 'Test Form';
            public string $handle = 'testForm';
            public ?string $description = null;
            public mixed $fieldLayout = [];
            public mixed $settings = [];
            public mixed $notificationSettings = [];
            public bool $enabled = true;
            public ?string $successMessage = null;
            public ?string $redirectUrl = null;
            public bool $hideFormOnSuccess = false;
            public bool $keepSuccessMessage = true;
            public int $successMessageDuration = 5;
            public ?int $maxSubmissionsPerUser = null;
            public int $rateLimit = 0;
            public int $rateLimitWindow = 60;
            public bool $saveSpamSubmissions = false;
            public bool $autoApprove = true;
            public ?string $captchaProvider = null;
            public ?float $captchaScoreThreshold = null;
            public bool $rejectOnCaptchaFail = false;
            public bool $allowUrlPrefill = false;
            public bool $showStepIndicator = false;
            public bool $validateSteps = true;
            public ?string $webhookUrl = null;
            public string $webhookPayload = 'full';

            /** @var FormRecord|null What the next findOne() call returns. */
            public static ?FormRecord $findOneResult = null;
            public static bool $saveResult = true;
            public static bool $deleteResult = true;

            public static function findOne($criteria): ?FormRecord
            {
                return self::$findOneResult;
            }

            public function save(bool $runValidation = true): bool
            {
                if (self::$saveResult) {
                    $this->id ??= 123;
                    $this->dateCreated = '2026-06-20 00:00:00';
                    $this->dateUpdated = '2026-06-20 00:00:00';
                    $this->uid = 'form-uid-123';
                }
                return self::$saveResult;
            }

            public function delete(): bool
            {
                return self::$deleteResult;
            }

            public function getErrors(): array
            {
                return [];
            }
        }
    }
}

namespace {
    $src = dirname(__DIR__) . '/src/services';
    require_once $src . '/Sanitization.php';
    require_once $src . '/FormSchemaService.php';
    require_once $src . '/ConditionEvaluator.php';
    require_once $src . '/SubmissionDataService.php';
    require_once $src . '/Webhooks.php';
    require_once dirname(__DIR__) . '/src/events/FormEvent.php';
    require_once $src . '/Forms.php';

    $captcha = dirname(__DIR__) . '/src/captcha';
    require_once $captcha . '/CaptchaProviderInterface.php';
    require_once $captcha . '/BaseCaptchaProvider.php';
    require_once $captcha . '/TurnstileProvider.php';
    require_once $captcha . '/RecaptchaV2Provider.php';
    require_once $captcha . '/RecaptchaV3Provider.php';

    // Wire the fake service container.
    $container = new \stdClass();
    $container->formSchema = new \yannkost\easyform\services\FormSchemaService();
    $container->conditionEvaluator = new \yannkost\easyform\services\ConditionEvaluator();
    $container->submissionData = new \yannkost\easyform\services\SubmissionDataService();
    \yannkost\easyform\EasyForm::$container = $container;
}
