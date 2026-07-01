<?php

namespace yannkost\easyform\captcha;

/**
 * A CAPTCHA provider that can render a client widget and verify a token.
 *
 * Built-in providers (Turnstile, reCAPTCHA v2/v3) implement this; sites may
 * register their own via Captcha::EVENT_REGISTER_CAPTCHA_PROVIDERS and then
 * select them per form.
 */
interface CaptchaProviderInterface
{
    /** Stable handle stored on the form, e.g. "turnstile". */
    public static function handle(): string;

    /** Human-readable name for the per-form provider dropdown. */
    public function displayName(): string;

    /** Whether the required credentials are configured. */
    public function isConfigured(): bool;

    /** Public site key used by the client widget. */
    public function getSiteKey(): string;

    /** Request parameter name carrying the client token. */
    public function getTokenParam(): string;

    /** HTML to render the widget inside the form (script + element). */
    public function renderWidget(string $formId): string;

    /**
     * Verify a client token. Returns true when the submission should proceed.
     *
     * @param string|null $token
     * @param string|null $ip Remote IP, when available.
     */
    public function verify(?string $token, ?string $ip = null): bool;
}
