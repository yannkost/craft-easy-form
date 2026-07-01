<?php

namespace yannkost\easyform\events;

use yannkost\easyform\models\Form;
use yannkost\easyform\models\Submission;
use yii\base\Event;

/**
 * Fired right before a submission webhook is POSTed, so integrators can add
 * authentication headers, mutate the payload, or cancel the request.
 *
 * ```php
 * use yii\base\Event;
 * use yannkost\easyform\services\Webhooks;
 * use yannkost\easyform\events\WebhookEvent;
 *
 * Event::on(Webhooks::class, Webhooks::EVENT_BEFORE_SEND, function (WebhookEvent $e) {
 *     $e->headers['Authorization'] = 'Bearer ' . getenv('MY_WEBHOOK_TOKEN');
 *     // $e->isValid = false;  // to cancel this webhook
 * });
 * ```
 */
class WebhookEvent extends Event
{
    /** @var Form The form whose submission triggered the webhook */
    public Form $form;

    /** @var Submission The submission being sent */
    public Submission $submission;

    /** @var string The destination URL (mutable) */
    public string $url = '';

    /** @var array The JSON payload (mutable) */
    public array $payload = [];

    /** @var array<string,string> Outgoing HTTP headers (mutable — add auth here) */
    public array $headers = [];

    /** @var bool Set false to cancel the webhook */
    public bool $isValid = true;
}
