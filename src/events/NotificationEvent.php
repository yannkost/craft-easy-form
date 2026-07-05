<?php

namespace yannkost\easyform\events;

use craft\mail\Message;
use yannkost\easyform\models\Form;
use yannkost\easyform\models\Submission;
use yii\base\Event;

/**
 * Fired around a submission email notification, so integrators can rewrite the
 * subject/body/headers, change recipients, cancel the send, or react afterwards.
 *
 * ```php
 * use yii\base\Event;
 * use yannkost\easyform\services\Notifications;
 * use yannkost\easyform\events\NotificationEvent;
 *
 * Event::on(Notifications::class, Notifications::EVENT_BEFORE_SEND_NOTIFICATION, function (NotificationEvent $e) {
 *     $e->message->setSubject('[Prefixed] ' . $e->message->getSubject());
 *     // $e->recipients[] = 'ops@example.com';
 *     // $e->isValid = false;  // to cancel this notification
 * });
 * ```
 */
class NotificationEvent extends Event
{
    /** @var Submission The submission the notification is for */
    public Submission $submission;

    /** @var Form|null The submission's form (null if it was deleted) */
    public ?Form $form = null;

    /** @var array The notification configuration (name, subject, recipients, …) */
    public array $notification = [];

    /** @var Message The email message being sent — mutate subject/body/headers here */
    public Message $message;

    /** @var string[] Recipient addresses (mutable in the before-send event) */
    public array $recipients = [];

    /** @var bool Set false in the before-send event to cancel this notification */
    public bool $isValid = true;

    /** @var bool Whether the send succeeded (set on the after-send event) */
    public bool $isSuccessful = false;
}
