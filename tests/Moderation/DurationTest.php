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

namespace Tutelar\Tests\Moderation;

use PHPUnit\Framework\TestCase;
use Tutelar\Moderation\Duration;

/**
 * @covers \Tutelar\Moderation\Duration
 */
final class DurationTest extends TestCase
{
    public function testUnitsAndCompoundForms(): void
    {
        $this->assertSame(90, Duration::toSeconds('90'));
        $this->assertSame(90, Duration::toSeconds('90s'));
        $this->assertSame(600, Duration::toSeconds('10m'));
        $this->assertSame(7200, Duration::toSeconds('2h'));
        $this->assertSame(604800, Duration::toSeconds('1w'));
        $this->assertSame(86400 + 43200, Duration::toSeconds('1d12h'));
        $this->assertSame(604800 + 3 * 86400, Duration::toSeconds('1w 3d'));
    }

    public function testPermanentForms(): void
    {
        foreach (['', '0', 'perm', 'permanent', 'FOREVER', 'never', null] as $input) {
            $this->assertNull(Duration::toSeconds($input), var_export($input, true) . ' is permanent');
        }
    }

    public function testGarbageThrowsRatherThanBecomingAPermaban(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::toSeconds('banana');
    }

    public function testStrayCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::toSeconds('10m please');
    }

    public function testClampToTimeoutHonoursDiscords28DayCeiling(): void
    {
        $max = 28 * 86400;
        $this->assertSame($max, Duration::clampToTimeout(null), 'permanent → the ceiling');
        $this->assertSame($max, Duration::clampToTimeout(999 * 86400));
        $this->assertSame(600, Duration::clampToTimeout(600));
        $this->assertSame(1, Duration::clampToTimeout(0));
    }

    public function testHumanize(): void
    {
        $this->assertSame('permanent', Duration::humanize(null));
        $this->assertSame('10 minutes', Duration::humanize(600));
        $this->assertSame('1 hour', Duration::humanize(3600));
        $this->assertSame('1 day 6 hours', Duration::humanize(86400 + 6 * 3600));
        $this->assertSame('1 week 1 day', Duration::humanize(694861));
    }
}
