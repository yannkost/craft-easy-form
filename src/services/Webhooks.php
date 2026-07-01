<?php

namespace yannkost\easyform\services;

use Craft;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;
use yannkost\easyform\EasyForm;
use yannkost\easyform\events\WebhookEvent;
use yannkost\easyform\models\Form;
use yannkost\easyform\models\Submission;
use yii\base\Component;

/**
 * Posts submissions to a per-form webhook URL.
 *
 * Payload shape is chosen per form:
 *   - 'full' → { handle, formId, submissionId, dateCreated, values, frontend, meta }
 *   - 'data' → { handle: value, … } (flat field values only)
 *
 * Authentication is intentionally not configured in the CP — register a
 * handler for EVENT_BEFORE_SEND to add Authorization/custom headers.
 */
class Webhooks extends Component
{
    /**
     * @event WebhookEvent Fired before the HTTP request; mutate headers/payload or cancel.
     */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    /**
     * Build the JSON payload for a submission according to the form's mode.
     */
    public function buildPayload(Form $form, Submission $submission): array
    {
        if ($form->webhookPayload === 'data') {
            return $submission->getFlatValues();
        }

        $dateCreated = $submission->dateCreated;
        if ($dateCreated instanceof \DateTimeInterface) {
            $dateCreated = $dateCreated->format(\DateTime::ATOM);
        } elseif ($dateCreated !== null) {
            $dateCreated = (string) $dateCreated;
        }

        return [
            'handle' => $form->handle,
            'formId' => $form->id,
            'submissionId' => $submission->id,
            'dateCreated' => $dateCreated,
            'siteId' => $submission->siteId,
            'values' => $submission->getValues(),
            'frontend' => $submission->getFrontendValues(),
            'meta' => $submission->getMeta(),
        ];
    }

    /**
     * Send the webhook for a submission. Returns true on a 2xx response (or if
     * there is nothing to send / it was cancelled), false on a real failure so
     * the queue job can retry.
     */
    public function send(Form $form, Submission $submission): bool
    {
        $url = trim((string) $form->webhookUrl);
        if ($url === '') {
            return true;
        }

        $event = new WebhookEvent([
            'form' => $form,
            'submission' => $submission,
            'url' => $url,
            'payload' => $this->buildPayload($form, $submission),
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEND)) {
            $this->trigger(self::EVENT_BEFORE_SEND, $event);
        }

        if (!$event->isValid) {
            EasyForm::debug("Webhook for submission #{$submission->id} cancelled by a before-send handler.");
            return true;
        }

        // SSRF guard: refuse to post to private/reserved/loopback hosts unless
        // explicitly allowed. A misconfiguration, not a transient failure.
        if (!$this->isHostAllowed($event->url)) {
            EasyForm::log("Webhook for submission #{$submission->id} blocked: {$event->url} resolves to a private/reserved address.", 'error');
            return true;
        }

        try {
            $client = Craft::createGuzzleClient(['timeout' => 10]);
            $response = $client->post($event->url, [
                'headers' => $event->headers,
                'body' => Json::encode($event->payload),
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                EasyForm::log("Webhook for submission #{$submission->id} → {$event->url} returned {$status}.", 'info');
                return true;
            }

            EasyForm::log("Webhook for submission #{$submission->id} → {$event->url} returned {$status}.", 'error');
            return false;
        } catch (GuzzleException | \Throwable $e) {
            EasyForm::log("Webhook for submission #{$submission->id} failed: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Whether a webhook URL's host is allowed. Blocks private / reserved /
     * loopback addresses (SSRF) unless the allowPrivateWebhookHosts setting is on.
     */
    private function isHostAllowed(string $url): bool
    {
        if (EasyForm::getInstance()->getSettings()->allowPrivateWebhookHosts) {
            return true;
        }

        return self::isHostPublic($url);
    }

    /**
     * Whether a URL's host resolves only to public IPs (no private / reserved /
     * loopback). Pure and DNS-injectable so it can be unit-tested without real
     * lookups: pass a resolver `fn(string $host): string[]` returning IPs.
     */
    public static function isHostPublic(string $url, ?callable $resolver = null): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolver ??= self::defaultResolver();
            $ips = $resolver($host);
        }

        if (!$ips) {
            // Can't resolve → treat as unsafe.
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The production DNS resolver: A + AAAA records, falling back to gethostbynamel.
     */
    private static function defaultResolver(): callable
    {
        return static function (string $host): array {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if (!$ips) {
                $ips = @gethostbynamel($host) ?: [];
            }
            return $ips;
        };
    }
}
