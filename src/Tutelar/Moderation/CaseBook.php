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

    public function __construct(private readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];

        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
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

    private function save(): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $this->path);
    }
}
