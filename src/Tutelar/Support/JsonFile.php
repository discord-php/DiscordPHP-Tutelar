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

namespace Tutelar\Support;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * A JSON document on disk that survives restarts, crashes and a careless text
 * editor — and that is written without stopping the event loop.
 *
 * Both of Tutelar's persistent stores are this file with different contents:
 * {@see \Tutelar\Store} keeps every guild's `/config` overrides and each
 * module's scratch space (including open tickets and their progress logs), and
 * {@see \Tutelar\Moderation\CaseBook} keeps the moderation history. Losing
 * either silently is the worst thing the bot can do to a server, so the same
 * guarantees belong to both rather than being written twice.
 *
 * ## Surviving
 *
 *  - **Writes are atomic.** Content goes to a temp file and is renamed over the
 *    target, so a crash mid-write cannot leave a half-written document where
 *    the state used to be.
 *  - **The last good copy is kept** beside it as `.bak`, written after each
 *    successful save — not by copying the file about to be replaced, which
 *    would leave the backup one write behind.
 *  - **A damaged file is never silently replaced.** If the JSON does not parse,
 *    the backup is tried; if that fails too, the file is preserved under a
 *    `.corrupt-<timestamp>` name and the caller starts empty. Without that, one
 *    truncated file plus one `/config` command is every guild's settings gone
 *    with nothing left to recover from.
 *  - Anything noticed on the way in is recorded in {@see warnings()}, which the
 *    bot logs at startup and reports to its owner.
 *
 * ## Not blocking the loop
 *
 * Loading is synchronous, once, during construction: it happens before `run()`,
 * when there is no loop to block. Saving is not — a ticket's progress log is
 * written while the bot is serving everything else — so it goes through
 * {@see Filesystem}, and the caller never waits: `save()` records the new state
 * and returns, writes that arrive during one already in flight collapse into a
 * single follow-up, and only the newest state is written.
 *
 * @since 2.4.0
 */
final class JsonFile
{
    /** Where the last known-good copy is kept. */
    public const BACKUP_SUFFIX = '.bak';

    private readonly Filesystem $filesystem;

    /** @var list<string> */
    private array $warnings = [];

    /** The newest state handed to {@see save()}. */
    private array $pending = [];

    /** The write in flight, if any. */
    private ?PromiseInterface $writing = null;

    /** Whether {@see save()} was called while that write was in flight. */
    private bool $dirty = false;

    public function __construct(private readonly string $path, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? Filesystem::create();
    }

    /**
     * Reads the document, falling back to the backup and refusing to discard
     * one it cannot understand.
     */
    public function load(): array
    {
        foreach (glob($this->path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }

        if (! is_file($this->path)) {
            return [];
        }

        $data = self::decode($this->path);
        if ($data !== null) {
            return $data;
        }

        $backup = self::decode($this->path . self::BACKUP_SUFFIX);
        if ($backup !== null) {
            $this->warnings[] = sprintf(
                '%s could not be read; recovered the previous copy from %s.',
                basename($this->path),
                basename($this->path) . self::BACKUP_SUFFIX,
            );

            return $backup;
        }

        // Nothing usable. Move the unreadable file out of the way rather than
        // starting empty and letting the next save overwrite it for good.
        $kept = $this->path . '.corrupt-' . date('Ymd-His');

        $this->warnings[] = @rename($this->path, $kept)
            ? sprintf(
                '%s could not be read and no backup was usable; it has been kept as %s and the bot started with none of it.',
                basename($this->path),
                basename($kept),
            )
            : sprintf(
                '%s could not be read and could not be moved aside; refusing to overwrite it, so nothing will be saved.',
                basename($this->path),
            );

        return [];
    }

    /**
     * Anything that went wrong while reading, in the order it was found. Empty
     * on a normal start.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The backend in use, for the startup line. */
    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /** Records new state and schedules a write. Returns immediately. */
    public function save(array $data): void
    {
        $this->pending = $data;

        if ($this->writing !== null) {
            $this->dirty = true;

            return;
        }

        // The pending promise has to exist *before* the write starts. With a
        // synchronous backend the completion callback runs inside then(), so
        // assigning its return value afterwards would put a finished promise
        // back over the null the callback had just written — and every later
        // save would think one was still in flight and never run.
        $deferred = new Deferred();
        $this->writing = $deferred->promise();

        $this->write()->then(function (bool $ok) use ($deferred): void {
            $this->writing = null;

            if ($this->dirty) {
                $this->dirty = false;
                $this->save($this->pending);
            }

            $deferred->resolve($ok);
        });
    }

    /**
     * Resolves when everything saved so far has reached the disk, following
     * the queue to the end.
     */
    public function saved(): PromiseInterface
    {
        if ($this->writing === null) {
            return resolve(true);
        }

        return $this->writing->then(fn (): PromiseInterface => $this->saved());
    }

    /**
     * Writes the newest state now, blocking.
     *
     * For shutdown: the loop is about to stop, so a queued write would never
     * run. Everything else should use {@see save()}.
     */
    public function flush(): bool
    {
        if ($this->writing === null && ! $this->dirty && $this->pending === []) {
            return true;
        }

        $this->writing = null;
        $this->dirty = false;

        $json = self::encode($this->pending);
        if ($json === null) {
            return false;
        }

        Filesystem::ensureDirectory(\dirname($this->path));

        $tmp = $this->path . '.' . getmypid() . '.tmp';

        if (! Filesystem::writeDurably($tmp, $json) || ! Filesystem::move($tmp, $this->path)) {
            @unlink($tmp);

            return false;
        }

        $backupTmp = $this->path . '.' . getmypid() . '.bak.tmp';

        if (Filesystem::writeDurably($backupTmp, $json)) {
            Filesystem::move($backupTmp, $this->path . self::BACKUP_SUFFIX);
        } else {
            @unlink($backupTmp);
        }

        return true;
    }

    /**
     * Encode, write a temp file, rename it over the target, then write the
     * backup.
     *
     * Bails without touching the live file when the data cannot be encoded — a
     * module stashing a non-UTF-8 string, say — so a bad value never truncates
     * the state.
     */
    private function write(): PromiseInterface
    {
        $json = self::encode($this->pending);
        if ($json === null) {
            return resolve(false);
        }

        Filesystem::ensureDirectory(\dirname($this->path));

        $tmp = $this->path . '.' . getmypid() . '.tmp';

        return $this->filesystem->write($tmp, $json)->then(function (bool $written) use ($tmp, $json): PromiseInterface {
            if (! $written || ! Filesystem::move($tmp, $this->path)) {
                return $this->filesystem->delete($tmp)->then(static fn (): bool => false);
            }

            $backupTmp = $this->path . '.' . getmypid() . '.bak.tmp';

            return $this->filesystem->write($backupTmp, $json)->then(function (bool $ok) use ($backupTmp): bool {
                if ($ok) {
                    Filesystem::move($backupTmp, $this->path . self::BACKUP_SUFFIX);
                }

                return true;
            });
        });
    }

    private static function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * Reads and decodes one file, or `null` when it is missing, unreadable, or
     * not a JSON object.
     */
    private static function decode(string $path): ?array
    {
        $contents = Filesystem::readBlocking($path);
        if ($contents === null) {
            return null;
        }

        // An empty file is a legitimate "nothing saved yet" — created but never
        // written to.
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
