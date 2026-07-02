<?php

namespace yannkost\easyform\captcha;

use Craft;
use craft\helpers\Json;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Settings;

/**
 * Shared behavior for the built-in providers: holds the plugin settings and
 * implements the standard `siteverify`-style POST.
 */
abstract class BaseCaptchaProvider implements CaptchaProviderInterface
{
    /** @var float|null Score from the most recent verify() (score-based providers only). */
    protected ?float $lastScore = null;

    public function __construct(protected Settings $settings)
    {
    }

    public function getTokenParam(): string
    {
        return 'g-recaptcha-response';
    }

    public function getLastScore(): ?float
    {
        return $this->lastScore;
    }

    /**
     * POST the token to a provider verification endpoint and return the decoded
     * response. On transport failure returns ['success' => true, '_failOpen' =>
     * true] so a provider outage does not block legitimate users.
     */
    protected function siteVerify(string $endpoint, string $secret, ?string $token, ?string $ip): array
    {
        if ($secret === '' || $token === null || $token === '') {
            return ['success' => false];
        }

        try {
            $client = Craft::createGuzzleClient(['timeout' => 8]);
            $response = $client->post($endpoint, [
                'form_params' => array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]),
            ]);
            $data = Json::decodeIfJson((string) $response->getBody());
            return is_array($data) ? $data : ['success' => false];
        } catch (\Throwable $e) {
            // Fail open (allow) or closed (reject) per setting; default open.
            $failOpen = $this->settings->captchaFailOpen;
            EasyForm::log(
                'CAPTCHA verification transport error: ' . $e->getMessage()
                    . ($failOpen ? ' — allowing (fail-open).' : ' — rejecting (fail-closed).'),
                'warning'
            );
            return ['success' => $failOpen, '_failOpen' => $failOpen];
        }
    }
}
