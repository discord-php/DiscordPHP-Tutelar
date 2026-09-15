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
use Tutelar\Modules\ModPanel;
use Tutelar\Modules\Moderation;

/**
 * @covers \Tutelar\Modules\ModPanel
 */
final class ModPanelTest extends TestCase
{
    public function testStatusLineNamesTheActionAndCase(): void
    {
        $this->assertSame('👢 Kicked · case #7', ModPanel::statusLine('kick', ['id' => 7], null));
        $this->assertSame('🔨 Banned · case #12', ModPanel::statusLine('ban', ['id' => 12], null));
        $this->assertSame('⏳ Timed out for 2h · case #3', ModPanel::statusLine('timeout', ['id' => 3], '2h'));
        $this->assertSame('🔈 Timeout cleared · case #9', ModPanel::statusLine('untimeout', ['id' => 9], null));
    }

    public function testStatusLineToleratesAMissingCaseId(): void
    {
        $this->assertSame('🕊️ Unbanned', ModPanel::statusLine('unban', [], null));
        $this->assertSame('✅ Done', ModPanel::statusLine('something-else', [], null));
    }

    public function testEscalationHintFiresOnlyOneBelowARung(): void
    {
        // ESCALATION rungs: 3 → 1h timeout, 5 → 1d timeout, 7 → kick, 10 → ban.
        $this->assertSame('Next warning auto-timeout (1h).', ModPanel::escalationHint(2));
        $this->assertSame('Next warning auto-timeout (1d).', ModPanel::escalationHint(4));
        $this->assertSame('Next warning auto-kick.', ModPanel::escalationHint(6));
        $this->assertSame('Next warning auto-ban.', ModPanel::escalationHint(9));

        $this->assertNull(ModPanel::escalationHint(0));
        $this->assertNull(ModPanel::escalationHint(3), 'sitting on a rung is not one below the next');
        $this->assertNull(ModPanel::escalationHint(10));
    }

    public function testEscalationHintTracksTheSourceOfTruth(): void
    {
        foreach (Moderation::ESCALATION as $count => $step) {
            $hint = ModPanel::escalationHint($count - 1);
            $this->assertNotNull($hint);
            $this->assertStringContainsString($step['action'], $hint);
        }
    }

    public function testDeleteLabelNamesTheCaseAndItsKind(): void
    {
        $this->assertSame('🗑️ #42 warn', ModPanel::deleteLabel(['id' => 42, 'type' => 'warn']));
        $this->assertSame('🗑️ #7', ModPanel::deleteLabel(['id' => '7']));
    }

    public function testDeleteLabelFitsDiscordsButtonLimit(): void
    {
        $label = ModPanel::deleteLabel(['id' => 42, 'type' => str_repeat('x', 200)]);

        $this->assertLessThanOrEqual(80, mb_strlen($label));
        $this->assertStringStartsWith('🗑️ #42', $label);
    }

    public function testAHistoryPageOfDeleteButtonsFitsDiscordsComponentLimits(): void
    {
        // The list is capped at CASE_LIST_LIMIT and rendered five buttons to a
        // row; Discord allows five rows of five on a message.
        $this->assertLessThanOrEqual(25, Moderation::CASE_LIST_LIMIT);
        $this->assertLessThanOrEqual(5, (int) ceil(Moderation::CASE_LIST_LIMIT / 5));
    }
}
