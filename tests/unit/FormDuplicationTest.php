<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\models\Form;
use yannkost\easyform\services\Forms;

/**
 * Covers the two pure building blocks of form duplication
 * (FormsController::actionDuplicate): generating a collision-free handle and
 * deep-copying every duplicable setting onto the new form.
 *
 * These ran only via the opt-in e2e suite before; here they're guarded in the
 * unit suite that CI runs on every push. The copy test also pins the per-site
 * message regression: those were previously dropped when duplicating.
 */
final class FormDuplicationTest extends TestCase
{
    public function testUniqueHandleReturnsBaseWhenFree(): void
    {
        $forms = new Forms();
        $never = fn(string $handle): bool => false;

        $this->assertSame('contact', $forms->uniqueHandle('contact', $never));
    }

    public function testUniqueHandleAppendsCounterOnCollision(): void
    {
        $forms = new Forms();
        $taken = ['contact'];
        $exists = fn(string $handle): bool => in_array($handle, $taken, true);

        $this->assertSame('contact1', $forms->uniqueHandle('contact', $exists));
    }

    public function testUniqueHandleSkipsConsecutiveCollisions(): void
    {
        $forms = new Forms();
        $taken = ['contact', 'contact1', 'contact2'];
        $exists = fn(string $handle): bool => in_array($handle, $taken, true);

        // Walks contact → contact1 → contact2 → contact3 (first one free).
        $this->assertSame('contact3', $forms->uniqueHandle('contact', $exists));
    }

    public function testCopyDuplicableSettingsCopiesEverySetting(): void
    {
        $forms = new Forms();

        $source = new Form();
        $source->description = 'Original description';
        $source->fieldLayout = [['rows' => [['fields' => []]]]];
        $source->settings = ['foo' => 'bar'];
        $source->notificationSettings = ['notify' => true];
        $source->enabled = false;
        $source->successMessage = 'Thanks!';
        $source->siteSuccessMessages = [1 => 'Merci'];
        $source->siteErrorMessages = [1 => 'Erreur'];
        $source->redirectUrl = '/done';
        $source->hideFormOnSuccess = true;
        $source->keepSuccessMessage = false;
        $source->successMessageDuration = 12;
        $source->maxSubmissionsPerUser = 3;
        $source->rateLimit = 5;
        $source->rateLimitWindow = 120;
        $source->saveSpamSubmissions = true;
        $source->captchaProvider = 'turnstile';
        $source->allowUrlPrefill = true;
        $source->showStepIndicator = true;
        $source->validateSteps = false;
        $source->webhookUrl = 'https://example.test/hook';
        $source->webhookPayload = 'minimal';

        $target = new Form();
        $forms->copyDuplicableSettings($source, $target);

        $this->assertSame('Original description', $target->description);
        $this->assertSame([['rows' => [['fields' => []]]]], $target->fieldLayout);
        $this->assertSame(['foo' => 'bar'], $target->settings);
        $this->assertSame(['notify' => true], $target->notificationSettings);
        $this->assertFalse($target->enabled);
        $this->assertSame('Thanks!', $target->successMessage);
        $this->assertSame('/done', $target->redirectUrl);
        $this->assertTrue($target->hideFormOnSuccess);
        $this->assertFalse($target->keepSuccessMessage);
        $this->assertSame(12, $target->successMessageDuration);
        $this->assertSame(3, $target->maxSubmissionsPerUser);
        $this->assertSame(5, $target->rateLimit);
        $this->assertSame(120, $target->rateLimitWindow);
        $this->assertTrue($target->saveSpamSubmissions);
        $this->assertSame('turnstile', $target->captchaProvider);
        $this->assertTrue($target->allowUrlPrefill);
        $this->assertTrue($target->showStepIndicator);
        $this->assertFalse($target->validateSteps);
        $this->assertSame('https://example.test/hook', $target->webhookUrl);
        $this->assertSame('minimal', $target->webhookPayload);
    }

    public function testCopyDuplicableSettingsCarriesPerSiteMessages(): void
    {
        // Regression: per-site success/error messages used to be lost on
        // duplicate because actionDuplicate copied the settings blob but not
        // these explicit properties, which saveForm() then re-folded as empty.
        $forms = new Forms();

        $source = new Form();
        $source->siteSuccessMessages = [1 => 'Merci', 2 => 'Danke'];
        $source->siteErrorMessages = [1 => 'Erreur', 2 => 'Fehler'];

        $target = new Form();
        $forms->copyDuplicableSettings($source, $target);

        $this->assertSame([1 => 'Merci', 2 => 'Danke'], $target->siteSuccessMessages);
        $this->assertSame([1 => 'Erreur', 2 => 'Fehler'], $target->siteErrorMessages);
    }

    public function testCopyDuplicableSettingsLeavesIdentityUntouched(): void
    {
        $forms = new Forms();

        $source = new Form();
        $source->id = 1;
        $source->name = 'Source';
        $source->handle = 'source';

        $target = new Form();
        $target->id = 999;
        $target->name = 'Duplicate name';
        $target->handle = 'duplicateHandle';

        $forms->copyDuplicableSettings($source, $target);

        // Identity belongs to the caller, not the copy helper.
        $this->assertSame(999, $target->id);
        $this->assertSame('Duplicate name', $target->name);
        $this->assertSame('duplicateHandle', $target->handle);
    }
}
