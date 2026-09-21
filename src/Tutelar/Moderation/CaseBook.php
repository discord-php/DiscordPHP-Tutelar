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

namespace Tutelar\Moderation;

use React\Promise\PromiseInterface;
use Tutelar\Support\Filesystem;
use Tutelar\Support\JsonFile;

/**
 * The moderation case book: a JSON-backed, per-guild, monotonically numbered log
 * of every moderator action (`warn`, `note`, `kick`, `ban`, `timeout`, `unban`,
 * `lock`, …). Discord's audit log is ephemeral, unnumbered and only weakly
 * queryable, so this is the durable record the `/case`, `/modlogs`, `/warnings`
 * and `/reason` commands read, and the source of truth for warning escalation
 * and scheduled tempban expiry.
 *
 * Writes are atomic (temp file + rename), mirroring {@see \Tutelar\Store}.
 *
 * @since 2.1.0
 */
final class CaseBook
{
    /** Actions that count toward warning escalation. */
    public const WARNING_TYPES = ['warn'];

    private array $data;

    private readonly JsonFile $file;

    public function __construct(string $path, ?Filesystem $filesystem = null)
    {
        $this->file = new JsonFile($path, $filesystem);
        $this->data = $this->file->load();
    }

    /**
     * Anything that went wrong reading the case file, for the startup
     * report. A silently empty case book would look exactly like a server
     * where nobody has ever been warned.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->file->warnings();
    }

    /** Where the cases live, for the log. */
    public function path(): string
    {
        return $this->file->path();
    }

    /** Resolves once everything changed so far has reached the disk. */
    public function saved(): PromiseInterface
    {
        return $this->file->saved();
    }

    /** Writes now, blocking. For shutdown. */
    public function flush(): bool
    {
        return $this->file->flush();
    }

    /**
     * Records a case and returns it (including its assigned `id`). A `ban` or
     * `timeout` with `$expiresIn` also registers a scheduled reversal that
     * {@see dueReversals()} will surface once the time is up.
     *
     * @param array<string, mixed> $extra Free-form per-type detail (e.g. the prior channel overwrite for a `lock`).
     *
     * @return array{id: int, type: string, user: string, mod: string, reason: string, at: int, expires: ?int, extra: array<string, mixed>, deleted: bool}
     */
    public function add(
        int|string $guildId,
        string $type,
        int|string $userId,
        int|string $modId,
        string $reason,
        ?int $expiresIn = null,
        array $extra = [],
    ): array {
        $guildId = (string) $guildId;
        $id = self::nextId($this->data, $guildId);
        $now = time();

        $case = [
            'id' => $id,
            'type' => $type,
            'user' => (string) $userId,
            'mod' => (string) $modId,
            'reason' => $reason === '' ? 'No reason given.' : $reason,
            'at' => $now,
            'expires' => $expiresIn === null ? null : $now + max(1, $expiresIn),
            'extra' => $extra,
            'deleted' => false,
        ];

        $this->data['next_id'][$guildId] = $id + 1;
        $this->data['cases'][$guildId][(string) $id] = $case;

        if ($case['expires'] !== null && in_array($type, ['ban', 'timeout'], true)) {
            $this->data['reversals'][$guildId . ':' . $userId] = [
                'guild' => $guildId,
                'user' => (string) $userId,
                'type' => $type,
                'case' => $id,
                'expires' => $case['expires'],
            ];
        }

        $this->save();

        return $case;
    }

    /** One case by id, or null (deleted cases are still returned — mark is on `deleted`). */
    public function get(int|string $guildId, int $id): ?array
    {
        $case = $this->data['cases'][(string) $guildId][(string) $id] ?? null;

        return is_array($case) ? $case : null;
    }

    /**
     * A user's cases, newest first, excluding soft-deleted ones.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int|string $guildId, int|string $userId, ?string $type = null): array
    {
        $out = [];
        foreach ($this->data['cases'][(string) $guildId] ?? [] as $case) {
            if (! is_array($case) || ($case['deleted'] ?? false)) {
                continue;
            }
            if ((string) ($case['user'] ?? '') !== (string) $userId) {
                continue;
            }
            if ($type !== null && ($case['type'] ?? '') !== $type) {
                continue;
            }
            $out[] = $case;
        }

        usort($out, static fn($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        return $out;
    }

    /** How many active warnings a user has (drives escalation). */
    public function warningCount(int|string $guildId, int|string $userId): int
    {
        $n = 0;
        foreach ($this->data['cases'][(string) $guildId] ?? [] as $case) {
            if (is_array($case)
                && ! ($case['deleted'] ?? false)
                && in_array($case['type'] ?? '', self::WARNING_TYPES, true)
                && (string) ($case['user'] ?? '') === (string) $userId
            ) {
                $n++;
            }
        }

        return $n;
    }

    /** Rewrites a case's reason. Returns false if the case does not exist. */
    public function setReason(int|string $guildId, int $id, string $reason): bool
    {
        if (! isset($this->data['cases'][(string) $guildId][(string) $id])) {
            return false;
        }
        $this->data['cases'][(string) $guildId][(string) $id]['reason'] = $reason === '' ? 'No reason given.' : $reason;
        $this->save();

        return true;
    }

    /** Soft-deletes a case (drops it from listings and warning counts). */
    public function remove(int|string $guildId, int $id): bool
    {
        if (! isset($this->data['cases'][(string) $guildId][(string) $id])) {
            return false;
        }
        $this->data['cases'][(string) $guildId][(string) $id]['deleted'] = true;
        $this->save();

        return true;
    }

    /**
     * Scheduled reversals (tempban / timeout) whose time is up.
     *
     * @return list<array{guild: string, user: string, type: string, case: int, expires: int}>
     */
    public function dueReversals(?int $now = null): array
    {
        $now ??= time();
        $due = [];
        foreach ($this->data['reversals'] ?? [] as $r) {
            if (is_array($r) && (int) ($r['expires'] ?? 0) <= $now) {
                $due[] = $r;
            }
        }

        return $due;
    }

    /** Drops a scheduled reversal once it has been carried out (or manually undone). */
    public function clearReversal(int|string $guildId, int|string $userId): void
    {
        unset($this->data['reversals'][$guildId . ':' . $userId]);
        $this->save();
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /** The next case number for a guild (1-based). Pure. */
    public static function nextId(array $data, string $guildId): int
    {
        $stored = (int) ($data['next_id'][$guildId] ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        $max = 0;
        foreach (array_keys($data['cases'][$guildId] ?? []) as $key) {
            $max = max($max, (int) $key);
        }

        return $max + 1;
    }

    /**
     * Hands the new state to {@see JsonFile}, which writes it atomically and
     * off the loop, keeps a recoverable backup, and folds a burst of case
     * changes into a single write.
     */
    private function save(): void
    {
        $this->file->save($this->data);
    }
}
