<?php

namespace yannkost\easyform\events;

use yannkost\easyform\models\Submission;
use yii\base\Event;

class SubmissionEvent extends Event
{
    /**
     * @var Submission The submission model
     */
    public Submission $submission;

    /**
     * @var bool Whether this is a new submission
     */
    public bool $isNew = false;

    /**
     * @var bool Whether the submission is valid
     */
    public bool $isValid = true;

    /**
     * @var string|null Custom error message if save is stopped
     */
    public ?string $message = null;
}
