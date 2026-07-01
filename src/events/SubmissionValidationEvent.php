<?php

namespace yannkost\easyform\events;

use yannkost\easyform\models\Form;
use yii\base\Event;

class SubmissionValidationEvent extends Event
{
    /**
     * @var Form The form model
     */
    public Form $form;

    /**
     * @var array The submitted field data
     */
    public array $submissionData;

    /**
     * @var bool Whether the submission is valid
     */
    public bool $isValid = true;

    /**
     * @var string|null Custom error message if submission is stopped
     */
    public ?string $message = null;
}
