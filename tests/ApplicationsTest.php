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
use Tutelar\Modules\Applications;

/**
 * @covers \Tutelar\Modules\Applications
 */
final class ApplicationsTest extends TestCase
{
    /** @return array{accountAgeDays: ?float, unanswered: int, priorCases: int} */
    private function facts(?float $ageDays = 365.0, int $unanswered = 0, int $priorCases = 0): array
    {
        return ['accountAgeDays' => $ageDays, 'unanswered' => $unanswered, 'priorCases' => $priorCases];
    }

    /** @return array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool} */
    private function rules(bool $auto = true, int $minAge = 30, bool $answers = true, bool $clean = true): array
    {
        return Applications::normalise([
            'auto' => $auto,
            'min_account_age_days' => $minAge,
            'require_answers' => $answers,
            'require_clean_record' => $clean,
        ]);
    }

    // --- evaluate() ------------------------------------------------------

    public function testNothingIsAutoApprovedWhileAutoApprovalIsOff(): void
    {
        $verdict = Applications::evaluate($this->facts(), $this->rules(auto: false));

        $this->assertFalse($verdict['approve']);
        $this->assertSame(['auto-approval is off for this server'], $verdict['reasons']);
    }

    public function testAnApplicationThatPassesEveryRuleIsApproved(): void
    {
        $verdict = Applications::evaluate($this->facts(), $this->rules());

        $this->assertTrue($verdict['approve']);
        $this->assertSame([], $verdict['reasons'], 'an approval records no reason to hold it');
    }

    public function testATooYoungAccountIsHeldWithTheAgeSpelledOut(): void
    {
        $verdict = Applications::evaluate($this->facts(ageDays: 3.0), $this->rules(minAge: 30));

        $this->assertFalse($verdict['approve']);
        $this->assertStringContainsString('3 day(s) old', $verdict['reasons'][0]);
        $this->assertStringContainsString('minimum 30 days', $verdict['reasons'][0]);
    }

    public function testAnUnreadableAccountAgeFailsClosedRatherThanApproving(): void
    {
        $verdict = Applications::evaluate($this->facts(ageDays: null), $this->rules(minAge: 30));

        $this->assertFalse($verdict['approve']);
        $this->assertSame(['the account age could not be read'], $verdict['reasons']);
    }

    public function testAnUnreadableAccountAgeIsFineWhenThereIsNoAgeRule(): void
    {
        $this->assertTrue(Applications::evaluate($this->facts(ageDays: null), $this->rules(minAge: 0))['approve']);
    }

    public function testUnansweredRequiredQuestionsAndPriorCasesBothHoldTheApplication(): void
    {
        $verdict = Applications::evaluate($this->facts(unanswered: 2, priorCases: 1), $this->rules());

        $this->assertFalse($verdict['approve']);
        $this->assertCount(2, $verdict['reasons'], 'every failing rule is reported, not just the first');
        $this->assertStringContainsString('2 required question(s)', $verdict['reasons'][0]);
        $this->assertStringContainsString('1 prior moderation case(s)', $verdict['reasons'][1]);
    }

    public function testTurningTheAnswerAndRecordRulesOffIgnoresThoseFacts(): void
    {
        $verdict = Applications::evaluate(
            $this->facts(unanswered: 5, priorCases: 9),
            $this->rules(answers: false, clean: false),
        );

        $this->assertTrue($verdict['approve']);
    }

    // --- normalise() -----------------------------------------------------

    public function testNormaliseFillsEveryRuleFromTheDefaults(): void
    {
        $this->assertSame(Applications::DEFAULTS, Applications::normalise([]));
        $this->assertSame(Applications::DEFAULTS, Applications::normalise('not an array'));
    }

    public function testNormaliseClampsTheAccountAgeAndCoercesTheFlags(): void
    {
        $rules = Applications::normalise(['auto' => 1, 'min_account_age_days' => -5, 'require_answers' => 0]);

        $this->assertTrue($rules['auto']);
        $this->assertSame(0, $rules['min_account_age_days']);
        $this->assertFalse($rules['require_answers']);
        $this->assertSame(3650, Applications::normalise(['min_account_age_days' => 99999])['min_account_age_days']);
    }

    public function testAutoApprovalIsOffOutOfTheBox(): void
    {
        $this->assertFalse(Applications::DEFAULTS['auto'], 'a server must opt in before the bot approves anyone');
    }

    // --- snowflakes / ages ----------------------------------------------

    public function testSnowflakeTimestampReadsDiscordsEpoch(): void
    {
        // The first possible snowflake is Discord's epoch, 2015-01-01T00:00:00Z.
        $this->assertSame(1420070400, Applications::snowflakeTimestamp('0'));
        $this->assertNull(Applications::snowflakeTimestamp(''));
        $this->assertNull(Applications::snowflakeTimestamp('not-a-snowflake'));
    }

    public function testSnowflakeTimestampMatchesAKnownId(): void
    {
        // 175928847299117063 is the id from Discord's own snowflake docs.
        $this->assertSame(1462015105, Applications::snowflakeTimestamp('175928847299117063'));
    }

    public function testDescribeAgeScalesFromMinutesToDays(): void
    {
        $this->assertSame('2 day(s)', Applications::describeAge(2.5));
        $this->assertSame('6 hour(s)', Applications::describeAge(0.25));
        $this->assertSame('30 minute(s)', Applications::describeAge(1 / 48));
    }

    // --- form responses --------------------------------------------------

    public function testUnansweredRequiredCountsOnlyBlankRequiredFields(): void
    {
        $rows = [
            ['label' => 'Why here?', 'required' => true, 'response' => 'For the memes'],
            ['label' => 'Blank but required', 'required' => true, 'response' => '   '],
            ['label' => 'Terms', 'required' => true, 'response' => false],
            ['label' => 'Optional', 'required' => false, 'response' => null],
        ];

        $this->assertSame(2, Applications::unansweredRequired($rows));
        $this->assertSame(0, Applications::unansweredRequired([]));
    }

    public function testRenderResponsesShowsEveryQuestionAndItsAnswer(): void
    {
        $rendered = Applications::renderResponses([
            ['label' => 'Why here?', 'required' => true, 'response' => 'A friend invited me'],
            ['label' => 'Rules', 'required' => true, 'response' => true],
            ['label' => 'Skipped', 'required' => false, 'response' => null],
            ['label' => '', 'required' => false, 'response' => ['red', 'blue']],
        ]);

        $this->assertStringContainsString('**Why here?** — A friend invited me', $rendered);
        $this->assertStringContainsString('✅ acknowledged', $rendered);
        $this->assertStringContainsString('*(blank)*', $rendered);
        $this->assertStringContainsString('**Question** — red, blue', $rendered, 'an unlabelled field still renders');
    }

    public function testRenderResponsesFitsAnEmbedField(): void
    {
        $rows = array_fill(0, 40, ['label' => str_repeat('q', 200), 'required' => true, 'response' => str_repeat('a', 500)]);

        $this->assertLessThanOrEqual(1024, mb_strlen(Applications::renderResponses($rows)));
    }

    public function testRenderResponsesSaysSoWhenThereIsNoForm(): void
    {
        $this->assertStringContainsString('no questions', Applications::renderResponses([]));
    }

    // --- card copy -------------------------------------------------------

    public function testVerdictLineReportsAnAutoApproval(): void
    {
        $this->assertStringContainsString('approved automatically', Applications::verdictLine(true, []));
    }

    public function testVerdictLineListsEveryReasonTheApplicationIsWaiting(): void
    {
        $line = Applications::verdictLine(null, ['too new', 'one unanswered question']);

        $this->assertStringContainsString('Waiting for a decision', $line);
        $this->assertStringContainsString('• too new', $line);
        $this->assertStringContainsString('• one unanswered question', $line);
    }

    public function testVerdictLineDistinguishesAFailedApprovalCallFromAHeldApplication(): void
    {
        $line = Applications::verdictLine(false, ['auto-approve failed — Missing Permissions']);

        $this->assertStringContainsString('the approval call failed', $line);
        $this->assertStringContainsString('Missing Permissions', $line);
    }

    public function testThePingMentionsTheOwnerAndAsksForALook(): void
    {
        $this->assertSame('<@42> a new server application needs a look.', Applications::ping('42', false));
        $this->assertStringContainsString('auto-approved', Applications::ping('42', true));
    }

    public function testThePingDegradesGracefullyWithNoKnownOwner(): void
    {
        $content = Applications::ping('', false);

        $this->assertStringNotContainsString('<@', $content);
        $this->assertStringStartsWith('a new server application', $content);
    }

    public function testSummaryShowsTheRulesAndWhereApplicationsLand(): void
    {
        $summary = Applications::summary($this->rules(minAge: 14), '999');

        $this->assertStringContainsString('Auto-approval — **on**', $summary);
        $this->assertStringContainsString('at least **14 day(s)** old', $summary);
        $this->assertStringContainsString('<#999>', $summary);
    }

    public function testSummaryWarnsWhenThereIsNoLogChannelToAnnounceIn(): void
    {
        $summary = Applications::summary($this->rules(auto: false, minAge: 0), null);

        $this->assertStringContainsString('Auto-approval — **off**', $summary);
        $this->assertStringContainsString('No account-age minimum', $summary);
        $this->assertStringContainsString('No log channel set', $summary);
    }
}
