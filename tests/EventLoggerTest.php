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
use Tutelar\Modules\EventLogger;

/**
 * @covers \Tutelar\Modules\EventLogger
 */
final class EventLoggerTest extends TestCase
{
    public function testDescribeChangeShowsBothSidesWhenTheyDiffer(): void
    {
        $this->assertSame('`old` → `new`', EventLogger::describeChange('old', 'new'));
    }

    public function testDescribeChangeRendersAnEmptySideAsADash(): void
    {
        $this->assertSame('`—` → `new`', EventLogger::describeChange(null, 'new'));
        $this->assertSame('`old` → `—`', EventLogger::describeChange('old', ''));
    }

    public function testDescribeChangeIsNullWhenNothingChanged(): void
    {
        $this->assertNull(EventLogger::describeChange('same', 'same'));
        $this->assertNull(EventLogger::describeChange(null, ''), 'null and "" are the same absence');
    }

    public function testDescribeRoleChangeReportsTheSymmetricDifferenceAsMentions(): void
    {
        $this->assertSame(
            '+<@&30> -<@&10>',
            EventLogger::describeRoleChange(['10', '20'], ['20', '30']),
        );
    }

    public function testDescribeRoleChangeIsNullWhenTheRoleSetsMatch(): void
    {
        $this->assertNull(EventLogger::describeRoleChange(['1', '2'], ['2', '1']));
    }

    public function testDescribeRoleChangeReportsAPureAddition(): void
    {
        $this->assertSame('+<@&9>', EventLogger::describeRoleChange([], ['9']));
    }
}
