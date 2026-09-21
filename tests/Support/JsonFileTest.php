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
use Tutelar\Support\Filesystem;
use Tutelar\Support\JsonFile;
use Tutelar\Tests\Doubles\DeferredAdapter;

/**
 * The two things every persistent store depends on: that what was saved comes
 * back, and that a damaged file is never quietly replaced by an empty one.
 */
final class JsonFileTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tutelar-jsonfile-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/state.json';
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testWhatWasSavedComesBack(): void
    {
        $file = $this->file();
        $file->save(['guilds' => ['1' => ['channels' => ['modlog' => '99']]]]);
        $file->flush();

        $this->assertSame(
            ['guilds' => ['1' => ['channels' => ['modlog' => '99']]]],
            $this->file()->load()
        );
    }

    public function testAMissingFileIsAnEmptyDocumentAndNotAProblem(): void
    {
        $file = $this->file();

        $this->assertSame([], $file->load());
        $this->assertSame([], $file->warnings());
    }

    public function testAnEmptyFileIsNotTreatedAsDamage(): void
    {
        file_put_contents($this->path, '');

        $file = $this->file();

        $this->assertSame([], $file->load());
        $this->assertSame([], $file->warnings());
    }

    public function testABackupIsWrittenAfterEachSave(): void
    {
        $file = $this->file();
        $file->save(['modules' => ['tickets' => ['seq:1' => 4]]]);
        $file->flush();

        $this->assertFileExists($this->path . JsonFile::BACKUP_SUFFIX);
        $this->assertSame(
            file_get_contents($this->path),
            file_get_contents($this->path . JsonFile::BACKUP_SUFFIX),
            'the backup should hold what was just saved, not the state before it',
        );
    }

    public function testADamagedFileIsRecoveredFromTheBackup(): void
    {
        $file = $this->file();
        $file->save(['guilds' => ['1' => ['roles' => ['staff' => '7']]]]);
        $file->flush();

        // Truncated by something outside this process.
        file_put_contents($this->path, '{"guilds": {"1": {"roles": {"sta');

        $recovered = $this->file();

        $this->assertSame(['guilds' => ['1' => ['roles' => ['staff' => '7']]]], $recovered->load());
        $this->assertStringContainsString('recovered', $recovered->warnings()[0] ?? '');
    }

    public function testAnUnreadableFileIsKeptRatherThanOverwritten(): void
    {
        // Without this, one truncated file plus one `/config` command is every
        // guild's settings gone, with nothing left to recover from.
        file_put_contents($this->path, 'not json');
        file_put_contents($this->path . JsonFile::BACKUP_SUFFIX, 'not json either');

        $file = $this->file();

        $this->assertSame([], $file->load());
        $this->assertStringContainsString('kept as', $file->warnings()[0] ?? '');

        $file->save(['guilds' => []]);
        $file->flush();

        $kept = glob($this->dir . '/state.json.corrupt-*') ?: [];

        $this->assertCount(1, $kept);
        $this->assertSame('not json', file_get_contents($kept[0]));
    }

    public function testStaleTempFilesFromAKilledProcessAreCleanedUp(): void
    {
        file_put_contents($this->path . '.999999.tmp', '{}');
        file_put_contents($this->path . '.999999.bak.tmp', '{}');

        $this->file()->load();

        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testAValueThatCannotBeEncodedLeavesTheFileAlone(): void
    {
        $file = $this->file();
        $file->save(['ok' => true]);
        $file->flush();

        $good = (string) file_get_contents($this->path);

        // A module stashing a non-UTF-8 string, which json_encode refuses.
        $file->save(['broken' => "\xB1\x31"]);

        $this->assertFalse($file->flush());
        $this->assertSame($good, file_get_contents($this->path));
    }

    public function testSavingDoesNotWaitForTheDisk(): void
    {
        $adapter = new DeferredAdapter();
        $file = new JsonFile($this->path, Filesystem::with($adapter, 'deferred'));

        $file->save(['guilds' => ['1' => []]]);

        $this->assertFileDoesNotExist($this->path);
        $this->assertSame(1, $adapter->pending());

        $adapter->settle();

        $this->assertFileExists($this->path);
    }

    public function testSavesDuringAWriteCoalesceAndTheNewestStateWins(): void
    {
        $adapter = new DeferredAdapter();
        $file = new JsonFile($this->path, Filesystem::with($adapter, 'deferred'));

        $file->save(['n' => 1]);   // starts a write
        $file->save(['n' => 2]);   // queued behind it
        $file->save(['n' => 3]);   // folded into the same follow-up

        $adapter->settle();

        // Two rounds of (file + backup), not three, and the last state stands.
        $this->assertSame(4, $adapter->countOf('write'));
        $this->assertSame(['n' => 3], $this->file()->load());
    }

    public function testTheQueueKeepsWorkingAfterAWriteCompletes(): void
    {
        $adapter = new DeferredAdapter();
        $file = new JsonFile($this->path, Filesystem::with($adapter, 'deferred'));

        $file->save(['n' => 1]);
        $adapter->settle();

        $file->save(['n' => 2]);
        $adapter->settle();

        $this->assertSame(['n' => 2], $this->file()->load());
    }

    public function testSavedResolvesWhenTheQueueIsEmpty(): void
    {
        $adapter = new DeferredAdapter();
        $file = new JsonFile($this->path, Filesystem::with($adapter, 'deferred'));

        $file->save(['n' => 1]);
        $file->save(['n' => 2]);

        $done = false;
        $file->saved()->then(function () use (&$done): void {
            $done = true;
        });

        $this->assertFalse($done);

        $adapter->settle();

        $this->assertTrue($done);
    }

    public function testFlushWritesEvenWithAnOutstandingAsyncWrite(): void
    {
        // Shutdown: the loop is about to stop, so a queued write would never
        // run.
        $adapter = new DeferredAdapter();
        $file = new JsonFile($this->path, Filesystem::with($adapter, 'deferred'));

        $file->save(['n' => 1]);

        $this->assertFileDoesNotExist($this->path);
        $this->assertTrue($file->flush());
        $this->assertSame(['n' => 1], $this->file()->load());
    }

    public function testFlushIsANoOpWhenNothingHasBeenSaved(): void
    {
        $this->assertTrue($this->file()->flush());
        $this->assertFileDoesNotExist($this->path);
    }

    private function file(): JsonFile
    {
        return new JsonFile($this->path, Filesystem::blocking());
    }
}
