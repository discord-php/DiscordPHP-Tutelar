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
use Tutelar\Modules\Onboarding;

/**
 * @covers \Tutelar\Modules\Onboarding
 */
final class OnboardingTest extends TestCase
{
    public function testMentionListWrapsEachIdInTheGivenSigil(): void
    {
        $this->assertSame('<#1> <#2>', Onboarding::mentionList(['1', '2'], '#'));
        $this->assertSame('<@&7>', Onboarding::mentionList([7], '@&'));
        $this->assertSame('', Onboarding::mentionList([], '#'));
    }

    public function testMentionListIsCappedSoAFieldCannotOverflow(): void
    {
        $rendered = Onboarding::mentionList(range(1, 40), '#');

        $this->assertSame(15, substr_count($rendered, '<#'));
        $this->assertStringEndsWith(' +25', $rendered, 'the remainder is summarised, not dropped silently');
    }

    public function testPromptHeadingSummarisesThePromptFlags(): void
    {
        $heading = Onboarding::promptHeading('Pick your pronouns', required: true, singleSelect: false, inOnboarding: true);

        $this->assertStringContainsString('Pick your pronouns', $heading);
        $this->assertStringContainsString('required', $heading);
        $this->assertStringContainsString('pick any', $heading);
        $this->assertStringContainsString('in onboarding', $heading);
    }

    public function testPromptHeadingFallsBackForAnUntitledChannelsAndRolesOnlyPrompt(): void
    {
        $heading = Onboarding::promptHeading('', required: false, singleSelect: true, inOnboarding: false);

        $this->assertStringContainsString('Untitled prompt', $heading);
        $this->assertStringContainsString('pick one', $heading);
        $this->assertStringContainsString('Channels & Roles only', $heading);
        $this->assertStringNotContainsString('required', $heading);
    }

    public function testPromptBodyListsEachOptionAndWhatItGrants(): void
    {
        $body = Onboarding::promptBody([
            ['title' => 'Art', 'role_ids' => ['100'], 'channel_ids' => ['200']],
            ['title' => 'Music', 'role_ids' => [], 'channel_ids' => []],
        ]);

        $this->assertStringContainsString('• Art → <@&100>  <#200>', $body);
        $this->assertStringContainsString('• Music', $body);
        $this->assertStringNotContainsString('Music →', $body, 'an option that grants nothing has no arrow');
    }

    public function testPromptBodyNamesAnUntitledOption(): void
    {
        $body = Onboarding::promptBody([['title' => '', 'role_ids' => ['9'], 'channel_ids' => []]]);

        $this->assertStringContainsString('• Untitled → <@&9>', $body);
    }

    public function testPromptBodyHandlesAPromptWithNoOptions(): void
    {
        $this->assertSame('*(no options)*', Onboarding::promptBody([]));
    }
}
