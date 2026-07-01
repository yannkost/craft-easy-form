<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\events\FormEvent;
use yannkost\easyform\models\Form;
use yannkost\easyform\records\FormRecord;
use yannkost\easyform\services\Forms;

/**
 * Covers the form lifecycle events that let integrators hook in (e.g. to
 * invalidate a static/full-page cache that embeds the form). Verifies each
 * event fires, carries the right payload, and that a before-save handler can
 * cancel the save.
 */
final class FormEventsTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the in-memory record stub between tests.
        FormRecord::$findOneResult = null;
        FormRecord::$saveResult = true;
        FormRecord::$deleteResult = true;
    }

    public function testAfterSaveFiresForNewFormWithIsNewTrue(): void
    {
        $forms = new Forms();
        $form = new Form();
        $form->id = null; // new

        $captured = [];
        $forms->on(Forms::EVENT_AFTER_SAVE_FORM, function (FormEvent $e) use (&$captured) {
            $captured[] = $e;
        });

        $result = $forms->saveForm($form, false);

        $this->assertTrue($result);
        $this->assertCount(1, $captured, 'afterSaveForm should fire exactly once');
        $this->assertInstanceOf(FormEvent::class, $captured[0]);
        $this->assertTrue($captured[0]->isNew, 'isNew should be true for a brand-new form');
        $this->assertSame($form, $captured[0]->form, 'event carries the saved Form model');
        // The save populated the model from the record.
        $this->assertSame(123, $form->id);
    }

    public function testBeforeAndAfterSaveFireForExistingFormWithIsNewFalse(): void
    {
        $forms = new Forms();
        $form = new Form();
        $form->id = 55; // existing

        // findOne() must return a record for the existing-form path.
        FormRecord::$findOneResult = new FormRecord();

        $order = [];
        $forms->on(Forms::EVENT_BEFORE_SAVE_FORM, function (FormEvent $e) use (&$order) {
            $order[] = 'before';
            $this->assertFalse($e->isNew);
        });
        $forms->on(Forms::EVENT_AFTER_SAVE_FORM, function (FormEvent $e) use (&$order) {
            $order[] = 'after';
            $this->assertFalse($e->isNew);
        });

        $this->assertTrue($forms->saveForm($form, false));
        $this->assertSame(['before', 'after'], $order, 'before fires then after');
    }

    public function testBeforeSaveHandlerCanCancelTheSave(): void
    {
        $forms = new Forms();
        $form = new Form();
        $form->id = null;

        $afterFired = false;
        $forms->on(Forms::EVENT_BEFORE_SAVE_FORM, function (FormEvent $e) {
            $e->isValid = false; // veto
        });
        $forms->on(Forms::EVENT_AFTER_SAVE_FORM, function () use (&$afterFired) {
            $afterFired = true;
        });

        $result = $forms->saveForm($form, false);

        $this->assertFalse($result, 'a vetoed save returns false');
        $this->assertFalse($afterFired, 'afterSaveForm must not fire when cancelled');
        $this->assertNull($form->id, 'the form was never written');
    }

    public function testDeleteFiresBeforeAndAfterWithFormModel(): void
    {
        $forms = new Forms();

        $record = new FormRecord();
        $record->id = 77;
        $record->handle = 'newsletter';
        FormRecord::$findOneResult = $record;

        $order = [];
        $handles = [];
        $forms->on(Forms::EVENT_BEFORE_DELETE_FORM, function (FormEvent $e) use (&$order, &$handles) {
            $order[] = 'before';
            $handles[] = $e->form->handle;
        });
        $forms->on(Forms::EVENT_AFTER_DELETE_FORM, function (FormEvent $e) use (&$order, &$handles) {
            $order[] = 'after';
            $handles[] = $e->form->handle;
        });

        $this->assertTrue($forms->deleteFormById(77));
        $this->assertSame(['before', 'after'], $order);
        // Handlers receive the resolved Form model — handle is available so an
        // integrator can target cache invalidation, not just an id.
        $this->assertSame(['newsletter', 'newsletter'], $handles);
    }

    public function testNoDeleteEventsWhenFormMissing(): void
    {
        $forms = new Forms();
        FormRecord::$findOneResult = null; // not found

        $fired = false;
        $forms->on(Forms::EVENT_BEFORE_DELETE_FORM, function () use (&$fired) {
            $fired = true;
        });

        $this->assertFalse($forms->deleteFormById(999));
        $this->assertFalse($fired, 'no event for a non-existent form');
    }

    public function testIntegratorCanHookSaveForCacheInvalidation(): void
    {
        // Mirrors the documented Blitz use case: a handler runs its own side
        // effect (here, recording that a refresh would happen) on form change.
        $forms = new Forms();
        $refreshedHandles = [];

        $invalidate = function (FormEvent $e) use (&$refreshedHandles) {
            $refreshedHandles[] = $e->form->handle;
        };
        $forms->on(Forms::EVENT_AFTER_SAVE_FORM, $invalidate);

        $form = new Form();
        $form->id = null;
        $form->handle = 'contact';
        $forms->saveForm($form, false);

        $this->assertSame(['contact'], $refreshedHandles);
    }
}
