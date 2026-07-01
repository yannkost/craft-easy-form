<?php

namespace yannkost\easyform\captcha;

use craft\helpers\Html;

/**
 * Google reCAPTCHA v2 ("I'm not a robot" checkbox) provider.
 *
 * @see https://developers.google.com/recaptcha/docs/display
 */
class RecaptchaV2Provider extends BaseCaptchaProvider
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public static function handle(): string
    {
        return 'recaptchaV2';
    }

    public function displayName(): string
    {
        return 'reCAPTCHA v2';
    }

    public function getSiteKey(): string
    {
        return $this->settings->resolve($this->settings->recaptchaV2SiteKey);
    }

    public function isConfigured(): bool
    {
        return $this->getSiteKey() !== '' && $this->settings->resolve($this->settings->recaptchaV2Secret) !== '';
    }

    public function renderWidget(string $formId): string
    {
        // Implicit rendering: api.js auto-renders .g-recaptcha and injects a
        // hidden g-recaptcha-response input into the form.
        $script = Html::jsFile('https://www.google.com/recaptcha/api.js', ['async' => true, 'defer' => true]);
        $widget = Html::tag('div', '', [
            'class' => 'g-recaptcha easy-form-captcha-widget',
            'data-sitekey' => $this->getSiteKey(),
        ]);
        return $script . $widget;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        $result = $this->siteVerify(self::VERIFY_URL, $this->settings->resolve($this->settings->recaptchaV2Secret), $token, $ip);
        return (bool) ($result['success'] ?? false);
    }
}
