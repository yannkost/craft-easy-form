<?php

namespace yannkost\easyform\captcha;

use craft\helpers\Html;

/**
 * Google reCAPTCHA v3 (invisible, score-based) provider.
 *
 * The widget loads the API and a hidden token input; the frontend script calls
 * grecaptcha.execute() on submit to populate it. Verification additionally
 * requires the returned score to meet the configured threshold.
 *
 * @see https://developers.google.com/recaptcha/docs/v3
 */
class RecaptchaV3Provider extends BaseCaptchaProvider
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public static function handle(): string
    {
        return 'recaptchaV3';
    }

    public function displayName(): string
    {
        return 'reCAPTCHA v3';
    }

    public function getSiteKey(): string
    {
        return $this->settings->resolve($this->settings->recaptchaV3SiteKey);
    }

    public function isConfigured(): bool
    {
        return $this->getSiteKey() !== '' && $this->settings->resolve($this->settings->recaptchaV3Secret) !== '';
    }

    public function renderWidget(string $formId): string
    {
        // Invisible: load the API and a hidden input the frontend script fills.
        $script = Html::jsFile('https://www.google.com/recaptcha/api.js?render=' . urlencode($this->getSiteKey()), ['async' => true, 'defer' => true]);
        $input = Html::tag('input', '', [
            'type' => 'hidden',
            'name' => 'g-recaptcha-response',
            'class' => 'easy-form-recaptcha-token',
        ]);
        return $script . $input;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        $result = $this->siteVerify(self::VERIFY_URL, $this->settings->resolve($this->settings->recaptchaV3Secret), $token, $ip);
        return $this->passesScore($result);
    }

    /**
     * v3 returns a score in [0,1]; require it to meet the threshold.
     * Exposed for unit testing the score gate without HTTP.
     */
    public function passesScore(array $result): bool
    {
        if (empty($result['success'])) {
            return false;
        }
        // Transport fail-open: allow without a score.
        if (!empty($result['_failOpen'])) {
            return true;
        }
        $threshold = $this->settings->recaptchaV3ScoreThreshold;
        $score = isset($result['score']) ? (float) $result['score'] : 0.0;
        return $score >= $threshold;
    }
}
