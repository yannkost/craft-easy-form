<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yannkost\easyform\services\Webhooks;

/**
 * SSRF guard for outgoing webhooks. The e2e suite can't exercise the refusal
 * path (the test instance sets allowPrivateWebhookHosts=true so its loopback
 * sink works), so the host classification is unit-tested here with an injected
 * resolver — no real DNS.
 */
final class WebhookSsrfTest extends TestCase
{
    public function testLiteralPublicIpIsAllowed(): void
    {
        $this->assertTrue(Webhooks::isHostPublic('https://93.184.216.34/hook'));
    }

    #[DataProvider('privateOrReservedHosts')]
    public function testPrivateReservedAndLoopbackLiteralsAreBlocked(string $url): void
    {
        $this->assertFalse(Webhooks::isHostPublic($url));
    }

    public static function privateOrReservedHosts(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:8080/x'],
            'private 10/8' => ['http://10.1.2.3/'],
            'private 172.16/12' => ['http://172.16.5.5/'],
            'private 192.168/16' => ['https://192.168.1.1/hook'],
            'link-local' => ['http://169.254.169.254/latest/meta-data'],
        ];
    }

    public function testHostResolvingToPublicIpIsAllowed(): void
    {
        $this->assertTrue(Webhooks::isHostPublic('https://hook.example/', fn() => ['93.184.216.34']));
    }

    public function testHostResolvingToPrivateIpIsBlocked(): void
    {
        $this->assertFalse(Webhooks::isHostPublic('https://internal.example/', fn() => ['10.0.0.5']));
    }

    public function testHostResolvingToAnyPrivateIpIsBlocked(): void
    {
        // A single private answer poisons the set, even alongside a public one.
        $this->assertFalse(Webhooks::isHostPublic('https://x.example/', fn() => ['93.184.216.34', '127.0.0.1']));
    }

    public function testUnresolvableHostIsBlocked(): void
    {
        $this->assertFalse(Webhooks::isHostPublic('https://nope.example/', fn() => []));
    }

    public function testMalformedUrlsAreBlocked(): void
    {
        $this->assertFalse(Webhooks::isHostPublic('not a url'));
        $this->assertFalse(Webhooks::isHostPublic(''));
        $this->assertFalse(Webhooks::isHostPublic('/relative/only'));
    }
}
