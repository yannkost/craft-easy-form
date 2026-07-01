<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\services\Sanitization;

final class SanitizationTest extends TestCase
{
    public function testRemovesControlCharsButKeepsWhitespace(): void
    {
        // NULL byte + a C0 control + DEL are stripped.
        $this->assertSame('abc', Sanitization::removeControlChars("a\x00b\x07c\x7F"));
        // Tab, newline and carriage return survive (multi-line text).
        $this->assertSame("line1\nline2\tend\r", Sanitization::removeControlChars("line1\nline2\tend\r"));
        // Multibyte UTF-8 is untouched.
        $this->assertSame('Curaçao — café', Sanitization::removeControlChars('Curaçao — café'));
        $this->assertNull(Sanitization::removeControlChars(null));
    }

    public function testSanitizesCsvFormulaTriggers(): void
    {
        foreach (['=', '+', '-', '@', "\t", "\r", "\n"] as $trigger) {
            $this->assertSame("'" . $trigger . 'x', Sanitization::sanitizeForCsv($trigger . 'x'));
        }

        // Formula payload gets neutralized.
        $this->assertSame("'=cmd|'/c calc'!A1", Sanitization::sanitizeForCsv("=cmd|'/c calc'!A1"));

        // Safe values are returned unchanged.
        $this->assertSame('hello', Sanitization::sanitizeForCsv('hello'));
        $this->assertSame('a@b.co', Sanitization::sanitizeForCsv('a@b.co'));
        $this->assertSame('', Sanitization::sanitizeForCsv(''));
        $this->assertNull(Sanitization::sanitizeForCsv(null));
    }
}
