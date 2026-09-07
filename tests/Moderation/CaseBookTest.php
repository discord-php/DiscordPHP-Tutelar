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
use Tutelar\Moderation\CaseBook;

/**
 * @covers \Tutelar\Moderation\CaseBook
 */
final class CaseBookTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tutelar-casebook-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/moderation.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function testCasesAreNumberedPerGuildAndPersist(): void
    {
        $book = new CaseBook($this->path);
        $a = $book->add('g1', 'warn', 'u1', 'm1', 'spam');
        $b = $book->add('g1', 'kick', 'u2', 'm1', 'rude');
        $c = $book->add('g2', 'warn', 'u1', 'm1', 'other guild');

        $this->assertSame(1, $a['id']);
        $this->assertSame(2, $b['id']);
        $this->assertSame(1, $c['id'], 'a second guild starts its own numbering');

        $reloaded = new CaseBook($this->path);
        $this->assertSame(3, $reloaded->add('g1', 'note', 'u1', 'm1', 'next')['id'], 'numbering survives a reload');
        $this->assertSame('rude', $reloaded->get('g1', 2)['reason']);
    }

    public function testEmptyReasonBecomesAPlaceholder(): void
    {
        $book = new CaseBook($this->path);
        $this->assertSame('No reason given.', $book->add('g1', 'warn', 'u1', 'm1', '')['reason']);
    }

    public function testForUserFiltersByTypeSkipsDeletedAndIsNewestFirst(): void
    {
        $book = new CaseBook($this->path);
        $book->add('g1', 'warn', 'u1', 'm1', 'one');
        $book->add('g1', 'note', 'u1', 'm1', 'a note');
        $third = $book->add('g1', 'warn', 'u1', 'm1', 'two');
        $book->add('g1', 'warn', 'u2', 'm1', 'someone else');

        $warnings = $book->forUser('g1', 'u1', 'warn');
        $this->assertSame([3, 1], array_column($warnings, 'id'), 'only u1 warnings, newest first');

        $book->remove('g1', $third['id']);
        $this->assertSame([1], array_column($book->forUser('g1', 'u1', 'warn'), 'id'));
        $this->assertSame(1, $book->warningCount('g1', 'u1'), 'a voided warning stops counting');
    }

    public function testWarningCountDrivesEscalationBoundaries(): void
    {
        $book = new CaseBook($this->path);
        $this->assertSame(0, $book->warningCount('g1', 'u1'));
        $book->add('g1', 'warn', 'u1', 'm1', 'x');
        $book->add('g1', 'warn', 'u1', 'm1', 'x');
        $book->add('g1', 'note', 'u1', 'm1', 'notes do not count');
        $this->assertSame(2, $book->warningCount('g1', 'u1'));
    }

    public function testSetReasonAndRemoveReportMissingCases(): void
    {
        $book = new CaseBook($this->path);
        $book->add('g1', 'warn', 'u1', 'm1', 'orig');

        $this->assertTrue($book->setReason('g1', 1, 'edited'));
        $this->assertSame('edited', $book->get('g1', 1)['reason']);
        $this->assertFalse($book->setReason('g1', 99, 'nope'));
        $this->assertFalse($book->remove('g1', 99));
    }

    public function testTimedBansAndTimeoutsRegisterAReversalThatComesDue(): void
    {
        $book = new CaseBook($this->path);
        $book->add('g1', 'ban', 'u1', 'm1', 'temp', 3600);   // expires in 1h
        $book->add('g1', 'timeout', 'u2', 'm1', 'quiet', 60); // expires in 1m
        $book->add('g1', 'ban', 'u3', 'm1', 'permanent', null); // no reversal
        $book->add('g1', 'warn', 'u4', 'm1', 'not reversible', 10); // wrong type, no reversal

        $due = $book->dueReversals(time() + 120);
        $this->assertCount(1, $due, 'only the 1-minute timeout is due after 2 minutes');
        $this->assertSame('u2', $due[0]['user']);
        $this->assertSame('timeout', $due[0]['type']);

        $this->assertCount(2, $book->dueReversals(time() + 7200), 'both time-boxed actions are due after 2 hours');

        $book->clearReversal('g1', 'u2');
        $this->assertCount(1, $book->dueReversals(time() + 7200));
    }

    public function testNextIdIsPureAndRecoversFromRawCaseKeys(): void
    {
        $data = ['cases' => ['g1' => ['1' => [], '2' => [], '7' => []]]];
        $this->assertSame(8, CaseBook::nextId($data, 'g1'), 'falls back to max key + 1 when next_id is absent');
        $this->assertSame(1, CaseBook::nextId([], 'fresh'));
    }
}
