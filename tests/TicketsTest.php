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
use Tutelar\Modules\Tickets;

/**
 * @covers \Tutelar\Modules\Tickets
 */
final class TicketsTest extends TestCase
{
    private const EVERYONE = '111';

    private const BOT = '999';

    public function testStaffRoleIdsPicksRolesWithAModeratorPermAndSkipsEveryone(): void
    {
        $map = [
            self::EVERYONE => ['administrator' => false, 'kick_members' => false, 'ban_members' => false, 'manage_guild' => false, 'moderate_members' => false],
            '200' => ['administrator' => false, 'kick_members' => true, 'ban_members' => false, 'manage_guild' => false, 'moderate_members' => false],
            '201' => ['administrator' => true, 'kick_members' => false, 'ban_members' => false, 'manage_guild' => false, 'moderate_members' => false],
            '202' => ['administrator' => false, 'kick_members' => false, 'ban_members' => false, 'manage_guild' => false, 'moderate_members' => false],
        ];

        $this->assertSame(['200', '201'], Tickets::staffRoleIds($map, self::EVERYONE));
    }

    public function testStaffRoleIdsIsEmptyWhenNoRoleQualifies(): void
    {
        $this->assertSame([], Tickets::staffRoleIds([
            self::EVERYONE => ['administrator' => true, 'kick_members' => true],
            '200' => ['administrator' => false, 'kick_members' => false, 'ban_members' => false, 'manage_guild' => false, 'moderate_members' => false],
        ], self::EVERYONE));
    }

    public function testOverwritesDenyEveryoneViewAndAllowBotPlusStaffRoles(): void
    {
        $ov = Tickets::overwrites(self::EVERYONE, self::BOT, ['200', '201'], null);

        // @everyone: deny view (1<<10 = 1024), allow nothing.
        $this->assertSame(['id' => self::EVERYONE, 'type' => 0, 'allow' => '0', 'deny' => '1024'], $ov[0]);

        // bot: member overwrite, allow includes manage_channels (1<<4) so it can delete on close.
        $this->assertSame(self::BOT, $ov[1]['id']);
        $this->assertSame(1, $ov[1]['type']);
        $this->assertSame('0', $ov[1]['deny']);
        $this->assertSame(16, (int) $ov[1]['allow'] & 16, 'bot keeps manage_channels');
        $this->assertSame(1024, (int) $ov[1]['allow'] & 1024, 'bot can view');

        // the two staff roles, as role overwrites, allowing view+send+history.
        $roleRows = array_values(array_filter($ov, static fn (array $r): bool => $r['type'] === 0 && $r['id'] !== self::EVERYONE));
        $this->assertSame(['200', '201'], array_map(static fn (array $r): string => $r['id'], $roleRows));
        foreach ($roleRows as $r) {
            $this->assertSame(1024, (int) $r['allow'] & 1024);
            $this->assertSame(2048, (int) $r['allow'] & 2048);
            $this->assertSame(65536, (int) $r['allow'] & 65536);
            $this->assertSame('0', $r['deny']);
        }
    }

    public function testOverwritesAddAnInvitedGuestButNeverDuplicateTheBot(): void
    {
        $withGuest = Tickets::overwrites(self::EVERYONE, self::BOT, ['200'], '424242');
        $guest = end($withGuest);
        $this->assertSame(['id' => '424242', 'type' => 1], ['id' => $guest['id'], 'type' => $guest['type']]);
        $this->assertSame(0, (int) $guest['allow'] & 16, 'a guest never gets manage_channels');

        $botAsGuest = Tickets::overwrites(self::EVERYONE, self::BOT, [], self::BOT);
        $memberRows = array_filter($botAsGuest, static fn (array $r): bool => $r['type'] === 1);
        $this->assertCount(1, $memberRows, 'the bot is not added twice when it is also passed as the guest');
    }

    public function testOverwritesDropAStaffRoleThatEqualsEveryone(): void
    {
        $ov = Tickets::overwrites(self::EVERYONE, self::BOT, [self::EVERYONE, '200'], null);
        $roleIds = array_map(static fn (array $r): string => $r['id'], array_filter($ov, static fn (array $r): bool => $r['type'] === 0));
        $this->assertSame([self::EVERYONE, '200'], array_values($roleIds));
        // …and the @everyone row is still the deny-view one, not an allow.
        $this->assertSame('1024', $ov[0]['deny']);
        $this->assertSame('0', $ov[0]['allow']);
    }

    public function testRenderTranscriptIsPlainTextWithHeaderAndLog(): void
    {
        $ticket = [
            'seq' => 7,
            'guildId' => '12',
            'channelId' => '34',
            'kind' => 'report',
            'subjectId' => '56',
            'openerId' => '78',
            'reason' => 'Message reported to the mods.',
            'source' => ['channelId' => '90', 'messageId' => '1234'],
            'opened' => 1_700_000_000,
            'closed' => 1_700_000_600,
            'log' => [
                Tickets::entry(1_700_000_000, '<@78>', 'opened the ticket — Message reported to the mods.'),
                Tickets::entry(1_700_000_300, '<@78>', 'warned <@56> · case #4 — spam'),
                Tickets::entry(1_700_000_600, '<@78>', 'closed the ticket — handled'),
            ],
        ];

        $t = Tickets::renderTranscript($ticket);

        $this->assertStringContainsString('Tutelar ticket #7', $t);
        $this->assertStringContainsString('Channel:  34 (deleted on close)', $t);
        $this->assertStringContainsString('Subject:  @56', $t);
        $this->assertStringContainsString('https://discord.com/channels/12/90/1234', $t);
        $this->assertStringContainsString('2023-11-14 22:13:20 UTC  @78 opened the ticket', $t);
        $this->assertStringContainsString('warned @56 · case #4 — spam', $t);
        $this->assertStringNotContainsString('<@', $t, 'mentions are flattened so the .txt has no live pings');
    }

    public function testSummaryLineDescribesTheTicket(): void
    {
        $line = Tickets::summaryLine([
            'kind' => 'report', 'subjectId' => '56', 'openerId' => '78', 'opened' => 1_700_000_000,
        ]);
        $this->assertStringContainsString('Reported message', $line);
        $this->assertStringContainsString('subject <@56>', $line);
        $this->assertStringContainsString('opened by <@78>', $line);
        $this->assertStringContainsString('<t:1700000000:R>', $line);

        $manual = Tickets::summaryLine(['kind' => 'manual', 'subjectId' => '', 'openerId' => '78', 'opened' => 1]);
        $this->assertStringContainsString('Manual ticket', $manual);
        $this->assertStringNotContainsString('subject', $manual);
    }
}
