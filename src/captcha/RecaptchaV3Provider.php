<?php

namespace yannkost\easyform\captcha;

use Craft;
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

    /** The action the frontend requests via grecaptcha.execute(); must match on verify. */
    private const EXPECTED_ACTION = 'submit';

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

        // 'inline' hides Google's floating badge and shows the required
        // disclosure text instead — always visible and Terms-compliant.
        $extra = '';
        if ($this->settings->recaptchaV3Badge === 'inline') {
            $extra = Html::tag('style', '.grecaptcha-badge{visibility:hidden !important;}')
                . Html::tag('p', $this->disclosureHtml(), [
                    'class' => 'easy-form-recaptcha-disclosure',
                ]);
        }

        return $script . $input . $extra;
    }

    /**
     * The reCAPTCHA disclosure text (with links) that Google's Terms require
     * when the floating badge is hidden. All parts are trusted (plugin strings
     * + fixed Google URLs), so the markup is emitted as-is.
     */
    private function disclosureHtml(): string
    {
        $privacy = Html::a(Craft::t('easy-form', 'Privacy Policy'), 'https://policies.google.com/privacy', [
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
        $terms = Html::a(Craft::t('easy-form', 'Terms of Service'), 'https://policies.google.com/terms', [
            'target' => '_blank',
            'rel' => 'noopener',
        ]);

        return Craft::t('easy-form', 'This site is protected by reCAPTCHA and the Google {privacyPolicy} and {termsOfService} apply.', [
            'privacyPolicy' => $privacy,
            'termsOfService' => $terms,
        ]);
    }

    public function verify(?string $token, ?string $ip = null, array $context = []): bool
    {
        $result = $this->siteVerify(self::VERIFY_URL, $this->settings->resolve($this->settings->recaptchaV3Secret), $token, $ip);
        $threshold = isset($context['scoreThreshold'])
            ? (float) $context['scoreThreshold']
            : (float) $this->settings->recaptchaV3ScoreThreshold;
        return $this->passesScore($result, $threshold);
    }

    /**
     * v3 returns a score in [0,1]; require it to meet the threshold. Records the
     * score on the instance (see getLastScore()) so callers can log/store it.
     * Exposed for unit testing the score gate without HTTP.
     *
     * @param float|null $threshold Effective threshold; falls back to the global
     *                              setting when null.
     */
    public function passesScore(array $result, ?float $threshold = null): bool
    {
        $this->lastScore = null;
        if (empty($result['success'])) {
            return false;
        }
        // Transport fail-open: allow without a score.
        if (!empty($result['_failOpen'])) {
            return true;
        }
        // Reject a token minted for a different action (anti-replay). Only
        // enforced when Google returned an action, so fail-open/legacy results
        // still pass.
        if (isset($result['action']) && $result['action'] !== self::EXPECTED_ACTION) {
            return false;
        }
        $threshold ??= (float) $this->settings->recaptchaV3ScoreThreshold;
        $score = isset($result['score']) ? (float) $result['score'] : 0.0;
        $this->lastScore = $score;
        return $score >= $threshold;
    }
}
