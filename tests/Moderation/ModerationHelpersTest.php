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
use Tutelar\Modules\Moderation;

/**
 * @covers \Tutelar\Modules\Moderation
 */
final class ModerationHelpersTest extends TestCase
{
    public function testRefusalBlocksSelfBotOwnerAndAbsentTargets(): void
    {
        // self
        $this->assertStringContainsString('yourself', (string) Moderation::refusal(true, false, false, true, 5, 1, false));
        // a bot
        $this->assertStringContainsString('bot', (string) Moderation::refusal(false, true, false, true, 5, 1, false));
        // the owner
        $this->assertStringContainsString('owner', (string) Moderation::refusal(false, false, true, true, 5, 1, false));
        // not in the guild, and not a raw-id action
        $this->assertStringContainsString('not in the server', (string) Moderation::refusal(false, false, false, false, 5, 1, false));
        // not in the guild, but a raw-id ban is allowed
        $this->assertNull(Moderation::refusal(false, false, false, false, 5, 1, false, allowAbsent: true));
    }

    public function testRefusalEnforcesRoleHierarchyUnlessTheModeratorOwnsTheServer(): void
    {
        $this->assertNull(Moderation::refusal(false, false, false, true, 9, 5, false), 'higher moderator may act');
        $this->assertStringContainsString('not below yours', (string) Moderation::refusal(false, false, false, true, 5, 5, false), 'equal top role is refused');
        $this->assertStringContainsString('not below yours', (string) Moderation::refusal(false, false, false, true, 3, 8, false), 'lower moderator is refused');
        $this->assertNull(Moderation::refusal(false, false, false, true, 0, 99, true), 'the owner outranks everyone');
    }

    public function testFilterMessagesAppliesEveryCriterionAndSkipsOldMessages(): void
    {
        $now = time();
        $msg = static fn(string $id, string $authorId, bool $bot, string $content, int $age): object => (object) [
            'id' => $id,
            'author' => (object) ['id' => $authorId, 'bot' => $bot],
            'content' => $content,
            'timestamp' => $now - $age,
            'webhook_id' => null,
        ];

        $messages = [
            $msg('1', 'u1', false, 'hello world', 10),
            $msg('2', 'u2', false, 'HELLO everyone', 10),
            $msg('3', 'u1', true, 'bot beep', 10),
            $msg('4', 'u1', false, 'hello again', 20 * 86400), // too old to bulk delete
        ];

        $byUser = Moderation::filterMessages($messages, 'u1', null, false);
        $this->assertSame(['1', '3'], array_column($byUser, 'id'), 'only u1, and message 4 is too old');

        $byText = Moderation::filterMessages($messages, null, 'hello', false);
        $this->assertSame(['1', '2'], array_column($byText, 'id'), 'case-insensitive contains, old one excluded');

        $bots = Moderation::filterMessages($messages, null, null, true);
        $this->assertSame(['3'], array_column($bots, 'id'));
    }
}
