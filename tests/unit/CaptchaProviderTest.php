<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\captcha\RecaptchaV2Provider;
use yannkost\easyform\captcha\RecaptchaV3Provider;
use yannkost\easyform\captcha\TurnstileProvider;
use yannkost\easyform\models\Settings;

final class CaptchaProviderTest extends TestCase
{
    public function testHandlesAndTokenParams(): void
    {
        $s = new Settings();
        $this->assertSame('turnstile', TurnstileProvider::handle());
        $this->assertSame('recaptchaV3', RecaptchaV3Provider::handle());
        $this->assertSame('recaptchaV2', RecaptchaV2Provider::handle());

        $this->assertSame('cf-turnstile-response', (new TurnstileProvider($s))->getTokenParam());
        $this->assertSame('g-recaptcha-response', (new RecaptchaV3Provider($s))->getTokenParam());
        $this->assertSame('g-recaptcha-response', (new RecaptchaV2Provider($s))->getTokenParam());
    }

    public function testIsConfiguredRequiresBothKeys(): void
    {
        $s = new Settings();
        $turnstile = new TurnstileProvider($s);
        $this->assertFalse($turnstile->isConfigured());

        $s->turnstileSiteKey = 'site';
        $this->assertFalse($turnstile->isConfigured()); // secret still missing

        $s->turnstileSecret = 'secret';
        $this->assertTrue($turnstile->isConfigured());
        $this->assertSame('site', $turnstile->getSiteKey());
    }

    public function testV3ScoreGate(): void
    {
        $s = new Settings();
        $s->recaptchaV3ScoreThreshold = 0.5;
        $provider = new RecaptchaV3Provider($s);

        $this->assertTrue($provider->passesScore(['success' => true, 'score' => 0.9]));
        $this->assertTrue($provider->passesScore(['success' => true, 'score' => 0.5]));
        $this->assertFalse($provider->passesScore(['success' => true, 'score' => 0.3]));
        $this->assertFalse($provider->passesScore(['success' => false, 'score' => 0.9]));
        // Transport fail-open: allowed even without a score.
        $this->assertTrue($provider->passesScore(['success' => true, '_failOpen' => true]));
        // A success with no score at all (e.g. a non-v3 endpoint response) is
        // treated as score 0 and rejected — this is what the e2e v3 form relies on.
        $this->assertFalse($provider->passesScore(['success' => true]));
    }

    public function testV3RecordsLastScore(): void
    {
        $s = new Settings();
        $s->recaptchaV3ScoreThreshold = 0.5;
        $provider = new RecaptchaV3Provider($s);

        // Score is recorded whether the gate passes or blocks.
        $provider->passesScore(['success' => true, 'score' => 0.9]);
        $this->assertSame(0.9, $provider->getLastScore());

        $provider->passesScore(['success' => true, 'score' => 0.3]);
        $this->assertSame(0.3, $provider->getLastScore());

        // No score available (hard failure) or fail-open => null.
        $provider->passesScore(['success' => false]);
        $this->assertNull($provider->getLastScore());

        $provider->passesScore(['success' => true, '_failOpen' => true]);
        $this->assertNull($provider->getLastScore());
    }

    public function testV3PerFormThresholdOverride(): void
    {
        $s = new Settings();
        $s->recaptchaV3ScoreThreshold = 0.5; // global
        $provider = new RecaptchaV3Provider($s);

        // Explicit (per-form) threshold overrides the global one.
        $this->assertFalse($provider->passesScore(['success' => true, 'score' => 0.7], 0.9));
        $this->assertTrue($provider->passesScore(['success' => true, 'score' => 0.4], 0.3));

        // Null threshold falls back to the global setting.
        $this->assertTrue($provider->passesScore(['success' => true, 'score' => 0.7], null));
        $this->assertFalse($provider->passesScore(['success' => true, 'score' => 0.4], null));
    }
}
