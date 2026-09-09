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

namespace Tutelar\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tutelar\Support\Text;

/**
 * @covers \Tutelar\Support\Text
 */
final class TextTest extends TestCase
{
    public function testShortTextIsReturnedUnchanged(): void
    {
        $this->assertSame('hello', Text::clip('hello', 100));
    }

    public function testOverLongTextIsCutAndEllipsised(): void
    {
        $clipped = Text::clip(str_repeat('x', 50), 10);

        $this->assertSame(str_repeat('x', 9) . '…', $clipped);
        $this->assertSame(10, mb_strlen($clipped));
    }

    public function testTextExactlyAtTheLimitIsNotTouched(): void
    {
        $this->assertSame('abcde', Text::clip('abcde', 5));
    }

    public function testEmptyStringBecomesAVisiblePlaceholder(): void
    {
        $this->assertSame('*(empty)*', Text::clip(''));
        $this->assertSame('*(empty)*', Text::clip('', 3));
    }

    public function testMultibyteTextIsCountedInCharactersNotBytes(): void
    {
        // 5 codepoints, well under the limit, so it must survive intact.
        $this->assertSame('café☕', Text::clip('café☕', 10));
    }

    public function testFieldReturnsAnInlineTripleClippedToDiscordsEmbedLimits(): void
    {
        $this->assertSame(['Name', 'value', false], Text::field('Name', 'value'));
        $this->assertSame(['Name', 'value', true], Text::field('Name', 'value', true));

        [$name, $value] = Text::field(str_repeat('n', 400), str_repeat('v', 4000));
        $this->assertSame(256, mb_strlen($name));
        $this->assertSame(1024, mb_strlen($value));
        $this->assertStringEndsWith('…', $name);
        $this->assertStringEndsWith('…', $value);
    }

    public function testFieldPlaceholdersAnEmptyValueSoDiscordAcceptsIt(): void
    {
        $this->assertSame(['Name', '*(empty)*', false], Text::field('Name', ''));
    }
}
