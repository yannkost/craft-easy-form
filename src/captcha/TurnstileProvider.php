<?php

namespace yannkost\easyform\captcha;

use craft\helpers\Html;

/**
 * Cloudflare Turnstile provider.
 *
 * @see https://developers.cloudflare.com/turnstile/
 */
class TurnstileProvider extends BaseCaptchaProvider
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function handle(): string
    {
        return 'turnstile';
    }

    public function displayName(): string
    {
        return 'Cloudflare Turnstile';
    }

    public function getSiteKey(): string
    {
        return $this->settings->resolve($this->settings->turnstileSiteKey);
    }

    public function isConfigured(): bool
    {
        return $this->getSiteKey() !== '' && $this->settings->resolve($this->settings->turnstileSecret) !== '';
    }

    public function getTokenParam(): string
    {
        return 'cf-turnstile-response';
    }

    public function renderWidget(string $formId): string
    {
        // Implicit rendering: the api.js auto-renders any .cf-turnstile element
        // and injects a hidden cf-turnstile-response input into the form.
        $script = Html::jsFile('https://challenges.cloudflare.com/turnstile/v0/api.js', ['async' => true, 'defer' => true]);
        $widget = Html::tag('div', '', [
            'class' => 'cf-turnstile easy-form-captcha-widget',
            'data-sitekey' => $this->getSiteKey(),
        ]);
        return $script . $widget;
    }

    public function verify(?string $token, ?string $ip = null, array $context = []): bool
    {
        $result = $this->siteVerify(self::VERIFY_URL, $this->settings->resolve($this->settings->turnstileSecret), $token, $ip);
        return (bool) ($result['success'] ?? false);
    }
}
