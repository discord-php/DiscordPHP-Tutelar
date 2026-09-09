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
use Tutelar\Modules\Help;

/**
 * @covers \Tutelar\Modules\Help
 */
final class HelpTest extends TestCase
{
    private function titles(bool $isModerator, bool $isManager): array
    {
        return array_map(static fn (array $s): string => $s['tier'], Help::sectionsFor($isModerator, $isManager));
    }

    public function testAPlainMemberOnlySeesThePublicSection(): void
    {
        $this->assertSame(['everyone'], $this->titles(isModerator: false, isManager: false));
    }

    public function testAModeratorSeesThePublicAndModerationSections(): void
    {
        $this->assertSame(['everyone', 'moderator'], $this->titles(isModerator: true, isManager: false));
    }

    public function testAManagerSeesOnboardingAndConfigButNotModeration(): void
    {
        // Manage Server is not one of the moderation permissions on its own.
        $this->assertSame(['everyone', 'manager', 'manager'], $this->titles(isModerator: false, isManager: true));
    }

    public function testAnAdministratorSeesEverySection(): void
    {
        $tiers = $this->titles(isModerator: true, isManager: true);

        $this->assertContains('everyone', $tiers);
        $this->assertContains('moderator', $tiers);
        $this->assertContains('manager', $tiers);
        $this->assertCount(count(Help::SECTIONS), $tiers, 'nothing is hidden from an admin');
    }

    public function testEverySectionListsAtLeastOneCommandAndPreservesOrder(): void
    {
        $all = Help::sectionsFor(true, true);
        $this->assertSame(Help::SECTIONS, $all, 'order matches the source of truth');

        foreach ($all as $section) {
            $this->assertNotSame([], $section['lines'], $section['title'] . ' has no lines');
            $this->assertStringContainsString('`/', implode("\n", $section['lines']), $section['title'] . ' names no command');
        }
    }
}
