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
 * Parses and renders the short human durations moderators type into command
 * options (`10m`, `2h`, `7d`, `1w`, `perm`). Discord has no duration input type,
 * so every "how long" option is a free-text string this normalises.
 *
 * @since 2.1.0
 */
final class Duration
{
    private const UNITS = [
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    /**
     * Seconds for a duration string, or null for "permanent / no expiry"
     * (`perm`, `permanent`, `forever`, `0`, empty). Throws on anything that
     * isn't a recognised duration so a mistyped option fails loudly rather
     * than silently becoming a permaban.
     *
     * Accepts a bare integer (seconds), `90s`, `10m`, `2h`, `7d`, `1w`, and
     * compound forms like `1d12h` or `1w 3d`.
     *
     * @throws \InvalidArgumentException
     */
    public static function toSeconds(?string $input): ?int
    {
        $s = strtolower(trim((string) $input));

        if ($s === '' || $s === '0' || in_array($s, ['perm', 'permanent', 'forever', 'none', 'never'], true)) {
            return null;
        }

        if (ctype_digit($s)) {
            return (int) $s;
        }

        if (! preg_match_all('/(\d+)\s*([smhdw])/', $s, $matches, PREG_SET_ORDER)) {
            throw new \InvalidArgumentException("Not a duration: \"{$input}\". Try 10m, 2h, 7d, 1w, or perm.");
        }

        // Reject stray characters between the tokens we matched.
        if (preg_replace('/\d+\s*[smhdw]\s*/', '', $s) !== '') {
            throw new \InvalidArgumentException("Not a duration: \"{$input}\". Try 10m, 2h, 7d, 1w, or perm.");
        }

        $total = 0;
        foreach ($matches as [, $n, $unit]) {
            $total += (int) $n * self::UNITS[$unit];
        }

        return $total;
    }

    /**
     * Clamp `$seconds` to Discord's 28-day member-timeout ceiling, keeping
     * null (permanent) as-is — callers use this only for timeouts.
     */
    public static function clampToTimeout(?int $seconds): int
    {
        $max = 28 * 86400;

        return $seconds === null ? $max : max(1, min($seconds, $max));
    }

    /** `"7 days"`, `"1 hour 30 minutes"`, `"permanent"` — for confirmations and the mod log. */
    public static function humanize(?int $seconds): string
    {
        if ($seconds === null) {
            return 'permanent';
        }
        if ($seconds <= 0) {
            return '0 seconds';
        }

        $parts = [];
        foreach (['w' => 'week', 'd' => 'day', 'h' => 'hour', 'm' => 'minute', 's' => 'second'] as $unit => $label) {
            $size = self::UNITS[$unit];
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);
                $seconds -= $count * $size;
                $parts[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
            }
        }

        return implode(' ', array_slice($parts, 0, 2));
    }
}
