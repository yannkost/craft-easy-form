<?php

namespace yannkost\easyform\services;

use craft\events\RegisterComponentTypesEvent;
use yii\base\Component;
use yannkost\easyform\captcha\CaptchaProviderInterface;
use yannkost\easyform\captcha\RecaptchaV2Provider;
use yannkost\easyform\captcha\RecaptchaV3Provider;
use yannkost\easyform\captcha\TurnstileProvider;
use yannkost\easyform\EasyForm;

/**
 * CAPTCHA registry.
 *
 * Resolves the built-in providers (plus any registered by other plugins) and
 * filters to those that have credentials configured. Forms select a provider
 * by handle.
 */
class Captcha extends Component
{
    /**
     * @event RegisterComponentTypesEvent Register additional CAPTCHA provider
     * classes (each must implement CaptchaProviderInterface).
     *
     * ```php
     * Event::on(Captcha::class, Captcha::EVENT_REGISTER_CAPTCHA_PROVIDERS,
     *     function(RegisterComponentTypesEvent $e) { $e->types[] = MyProvider::class; });
     * ```
     */
    public const EVENT_REGISTER_CAPTCHA_PROVIDERS = 'registerCaptchaProviders';

    /**
     * @var CaptchaProviderInterface[]|null handle => instance
     */
    private ?array $_providers = null;

    /**
     * The default, built-in provider classes.
     *
     * @return string[]
     */
    public function getDefaultProviderTypes(): array
    {
        return [
            TurnstileProvider::class,
            RecaptchaV3Provider::class,
            RecaptchaV2Provider::class,
        ];
    }

    /**
     * All registered providers, keyed by handle.
     *
     * @return CaptchaProviderInterface[]
     */
    public function getProviders(): array
    {
        if ($this->_providers !== null) {
            return $this->_providers;
        }

        $event = new RegisterComponentTypesEvent(['types' => $this->getDefaultProviderTypes()]);
        try {
            $this->trigger(self::EVENT_REGISTER_CAPTCHA_PROVIDERS, $event);
        } catch (\Throwable $e) {
            // A misbehaving registration handler must not break CAPTCHA resolution.
            EasyForm::log('Error registering CAPTCHA providers: ' . $e->getMessage(), 'error');
        }

        $settings = EasyForm::getInstance()->getSettings();
        $providers = [];
        foreach ($event->types as $class) {
            try {
                if (!is_subclass_of($class, CaptchaProviderInterface::class)) {
                    EasyForm::log('Ignoring CAPTCHA provider "' . (is_string($class) ? $class : gettype($class)) . '": not a ' . CaptchaProviderInterface::class, 'warning');
                    continue;
                }
                /** @var CaptchaProviderInterface $instance */
                $instance = new $class($settings);
                $providers[$class::handle()] = $instance;
            } catch (\Throwable $e) {
                // Skip a broken provider rather than failing the whole registry.
                EasyForm::log('Skipping CAPTCHA provider "' . (is_string($class) ? $class : gettype($class)) . '": ' . $e->getMessage(), 'error');
            }
        }

        return $this->_providers = $providers;
    }

    /**
     * Providers that have credentials configured (selectable per form).
     *
     * @return CaptchaProviderInterface[]
     */
    public function getConfiguredProviders(): array
    {
        return array_filter($this->getProviders(), fn(CaptchaProviderInterface $p) => $p->isConfigured());
    }

    /**
     * Resolve a provider by handle, or null.
     */
    public function getProvider(?string $handle): ?CaptchaProviderInterface
    {
        if (empty($handle)) {
            return null;
        }
        return $this->getProviders()[$handle] ?? null;
    }

    /**
     * Whether a form's selected provider is present and configured.
     */
    public function isUsable(?string $handle): bool
    {
        $provider = $this->getProvider($handle);
        return $provider !== null && $provider->isConfigured();
    }
}
