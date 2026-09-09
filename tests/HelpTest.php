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

    public function testEveryRenderedFieldFitsTheDiscordEmbedLimit(): void
    {
        foreach (Help::fields(Help::sectionsFor(true, true)) as [$name, $value]) {
            $this->assertLessThanOrEqual(256, mb_strlen($name), "field name too long: {$name}");
            $this->assertLessThanOrEqual(1024, mb_strlen($value), "field value over 1024: {$name}");
            $this->assertNotSame('', $value);
        }
    }

    public function testALongSectionIsSplitAcrossContinuationFields(): void
    {
        $long = ['tier' => 'everyone', 'title' => 'Big', 'lines' => array_fill(0, 40, str_repeat('x', 80))];

        $fields = Help::fields([$long]);

        $this->assertGreaterThan(1, count($fields), 'a 40-line section must span more than one field');
        $this->assertSame('Big', $fields[0][0]);
        $this->assertSame('Big (cont.)', $fields[1][0]);
        // Every line survives the packing (just redistributed across fields).
        $lines = explode("\n", implode("\n", array_column($fields, 1)));
        $this->assertCount(40, $lines, 'no line dropped or added');
    }

    public function testShortSectionsStayOneFieldEach(): void
    {
        $fields = Help::fields(Help::sectionsFor(false, false));
        $this->assertSame([['Everyone', implode("\n", Help::SECTIONS[0]['lines'])]], $fields);
    }
}
