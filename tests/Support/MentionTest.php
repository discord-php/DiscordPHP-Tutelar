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
use Tutelar\Support\Mention;

/**
 * @covers \Tutelar\Support\Mention
 */
final class MentionTest extends TestCase
{
    public function testEachKindGetsItsOwnSyntax(): void
    {
        $this->assertSame('<@123>', Mention::user('123'));
        $this->assertSame('<#123>', Mention::channel('123'));
        $this->assertSame('<@&123>', Mention::role('123'));
    }

    public function testIntegerIdsWorkToo(): void
    {
        // Case-book entries and event payloads hand back both shapes.
        $this->assertSame('<@123>', Mention::user(123));
        $this->assertSame('<#123>', Mention::channel(123));
    }

    public function testAUserLineCarriesTheMentionAndTheRawId(): void
    {
        // The id is what survives when the mention can't resolve — a member who
        // left, or a banned account nobody in the guild shares a server with.
        $this->assertSame('<@123> · `123`', Mention::userLine('123'));
    }

    public function testAProfileUrlPointsAtTheAccount(): void
    {
        $this->assertSame('https://discord.com/users/123', Mention::profileUrl('123'));
    }

    public function testAMissingIdNeverProducesABrokenMention(): void
    {
        // `<@>` renders as literal text in Discord, which is worse than saying
        // nothing — every helper degrades to a readable placeholder instead.
        foreach ([null, '', 'not-an-id', '12a3', ' 123'] as $bad) {
            $this->assertStringNotContainsString('<@>', Mention::user($bad));
            $this->assertStringNotContainsString('<#>', Mention::channel($bad));
            $this->assertSame('*(unknown user)*', Mention::user($bad));
            $this->assertSame('*(unknown channel)*', Mention::channel($bad));
            $this->assertSame('*(unknown role)*', Mention::role($bad));
            $this->assertSame('*(unknown user)*', Mention::userLine($bad));
            $this->assertNull(Mention::profileUrl($bad), 'setAuthor() takes null, not a broken URL');
        }
    }
}
