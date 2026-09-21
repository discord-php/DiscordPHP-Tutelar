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
use Tutelar\Config;
use Tutelar\Moderation\CaseBook;
use Tutelar\Store;
use Tutelar\Support\Filesystem;
use Tutelar\Support\JsonFile;
use Tutelar\Tests\Doubles\DeferredAdapter;

/**
 * What a server set up has to be there when the bot comes back: `/config`
 * overrides, open tickets, and the moderation history.
 */
final class RestartTest extends TestCase
{
    private string $dir;

    private string $state;

    private string $cases;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tutelar-restart-' . bin2hex(random_bytes(6));
        $this->state = $this->dir . '/state.json';
        $this->cases = $this->dir . '/moderation.json';
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testGuildOverridesSurviveARestart(): void
    {
        $store = $this->store();
        $store->setGuildChannel('111', 'modlog', '222');
        $store->setGuildRole('111', 'staff', '333');
        $store->flush();

        $config = $this->store()->guildConfig($this->config(), '111');

        $this->assertSame('222', $config->channel('modlog'));
        $this->assertSame('333', $config->role('staff'));
    }

    public function testClearingAnOverrideSurvivesTooRatherThanComingBack(): void
    {
        $store = $this->store();
        $store->setGuildChannel('111', 'modlog', '222');
        $store->setGuildChannel('111', 'welcome', '444');
        $store->clearGuildChannel('111', 'modlog');
        $store->flush();

        $config = $this->store()->guildConfig($this->config(), '111');

        $this->assertNull($config->channel('modlog'));
        $this->assertSame('444', $config->channel('welcome'));
    }

    public function testAnOpenTicketAndItsCounterSurviveARestart(): void
    {
        // Tickets live in module scratch space, so a store that came back empty
        // would leave open ticket channels nobody can close.
        $store = $this->store();
        $store->moduleSet('tickets', 'seq:111', 7);
        $store->moduleSet('tickets', '999', [
            'guildId' => '111',
            'channelId' => '999',
            'seq' => 7,
            'log' => [['ts' => 123, 'actor' => '5', 'text' => 'opened']],
        ]);
        $store->flush();

        $restarted = $this->store();

        $this->assertSame(7, $restarted->moduleGet('tickets', 'seq:111'));
        $this->assertSame('opened', $restarted->moduleGet('tickets', '999')['log'][0]['text']);
    }

    public function testModerationHistorySurvivesARestart(): void
    {
        $book = $this->caseBook();
        $book->add('111', 'warn', '555', '5', 'spamming');
        $book->add('111', 'ban', '666', '5', 'raiding', 3600);
        $book->flush();

        $restarted = $this->caseBook();

        $this->assertSame(1, $restarted->warningCount('111', '555'));
        $this->assertSame('spamming', $restarted->get('111', 1)['reason']);
        $this->assertCount(1, $restarted->dueReversals(time() + 7200));
    }

    public function testTheNextCaseNumberDoesNotRestartWithTheProcess(): void
    {
        $book = $this->caseBook();
        $book->add('111', 'warn', '555', '5', 'one');
        $book->add('111', 'warn', '555', '5', 'two');
        $book->flush();

        $third = $this->caseBook()->add('111', 'warn', '555', '5', 'three');

        $this->assertSame(3, $third['id']);
    }

    public function testADamagedStateFileIsRecoveredRatherThanStartingEmpty(): void
    {
        $store = $this->store();
        $store->setGuildChannel('111', 'modlog', '222');
        $store->moduleSet('tickets', 'seq:111', 4);
        $store->flush();

        file_put_contents($this->state, '{"guilds": {"111": {"chan');

        $recovered = $this->store();

        $this->assertSame(4, $recovered->moduleGet('tickets', 'seq:111'));
        $this->assertStringContainsString('recovered', $recovered->warnings()[0] ?? '');
    }

    public function testADamagedCaseFileIsRecoveredRatherThanStartingEmpty(): void
    {
        // A silently empty case book looks exactly like a server where nobody
        // has ever been warned — the worst possible failure for this file.
        $book = $this->caseBook();
        $book->add('111', 'warn', '555', '5', 'spamming');
        $book->flush();

        file_put_contents($this->cases, 'garbage');

        $recovered = $this->caseBook();

        $this->assertSame(1, $recovered->warningCount('111', '555'));
        $this->assertStringContainsString('recovered', $recovered->warnings()[0] ?? '');
    }

    public function testNeitherStoreMakesTheCallerWaitOnTheDisk(): void
    {
        $adapter = new DeferredAdapter();
        $filesystem = Filesystem::with($adapter, 'deferred');

        $store = new Store($this->state, $filesystem);
        $store->setGuildChannel('111', 'modlog', '222');

        $book = new CaseBook($this->cases, $filesystem);
        $book->add('111', 'warn', '555', '5', 'spamming');

        // Both answered from memory; neither file exists yet.
        $this->assertFileDoesNotExist($this->state);
        $this->assertFileDoesNotExist($this->cases);
        $this->assertSame(2, $adapter->pending());

        $adapter->settle();

        $this->assertSame('222', $this->store()->guildConfig($this->config(), '111')->channel('modlog'));
        $this->assertSame(1, $this->caseBook()->warningCount('111', '555'));
    }

    public function testABackupIsKeptBesideEachFile(): void
    {
        $store = $this->store();
        $store->setGuildChannel('111', 'modlog', '222');
        $store->flush();

        $book = $this->caseBook();
        $book->add('111', 'warn', '555', '5', 'spamming');
        $book->flush();

        $this->assertFileExists($this->state . JsonFile::BACKUP_SUFFIX);
        $this->assertFileExists($this->cases . JsonFile::BACKUP_SUFFIX);
    }

    private function store(): Store
    {
        return new Store($this->state, Filesystem::blocking());
    }

    private function caseBook(): CaseBook
    {
        return new CaseBook($this->cases, Filesystem::blocking());
    }

    private function config(): Config
    {
        $path = $this->dir . '/config.json';
        file_put_contents($path, (string) json_encode(['token' => 't', 'guilds' => []]));

        return Config::load($path, ['DISCORD_TOKEN' => 't']);
    }
}
