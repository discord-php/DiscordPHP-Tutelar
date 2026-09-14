<?php

declare(strict_types=1);

/*
 * This file is a part of the Tutelar project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Tutelar\Tests;

use PHPUnit\Framework\TestCase;
use Tutelar\Modules\MemberJson;

/**
 * @covers \Tutelar\Modules\MemberJson
 */
final class MemberJsonTest extends TestCase
{
    public function testASmallDumpIsFencedInline(): void
    {
        $json = "{\n    \"nick\": \"Val\"\n}";
        $content = MemberJson::content($json, '42');

        $this->assertTrue(MemberJson::fitsInline($json));
        $this->assertStringStartsWith("```json\n", $content);
        $this->assertStringEndsWith("\n```", $content);
        $this->assertStringContainsString('"nick": "Val"', $content);
    }

    public function testADumpTooBigToInlineBecomesANoteAboutTheAttachment(): void
    {
        $json = str_repeat('x', 5000);
        $content = MemberJson::content($json, '42');

        $this->assertFalse(MemberJson::fitsInline($json));
        $this->assertStringNotContainsString('```', $content, 'the payload is attached, not pasted');
        $this->assertStringContainsString('<@42>', $content);
        $this->assertStringContainsString('4.9 KB', $content);
    }

    public function testInlineContentStaysUnderDiscordsMessageLimit(): void
    {
        $json = str_repeat('y', 1900);

        $this->assertTrue(MemberJson::fitsInline($json));
        $this->assertLessThanOrEqual(2000, mb_strlen(MemberJson::content($json, '42')));
    }

    public function testTheInlineCutoffIsExact(): void
    {
        $this->assertTrue(MemberJson::fitsInline(str_repeat('z', 1900)));
        $this->assertFalse(MemberJson::fitsInline(str_repeat('z', 1901)));
    }

    public function testDescribeSizeSwitchesToKilobytes(): void
    {
        $this->assertSame('512 bytes', MemberJson::describeSize(512));
        $this->assertSame('1.0 KB', MemberJson::describeSize(1024));
        $this->assertSame('2.5 KB', MemberJson::describeSize(2560));
    }
}
