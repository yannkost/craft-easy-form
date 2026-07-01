<?php

namespace yannkost\easyform\events;

use yannkost\easyform\models\Form;
use yii\base\Event;

class FormEvent extends Event
{
    /**
     * @var Form The form model
     */
    public Form $form;

    /**
     * @var bool Whether this is a new form
     */
    public bool $isNew = false;

    /**
     * @var bool Whether the form is valid. Set to false in a
     * EVENT_BEFORE_SAVE_FORM handler to stop the save.
     */
    public bool $isValid = true;
}
