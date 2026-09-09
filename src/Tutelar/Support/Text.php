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

/**
 * Small string helpers shared by the modules that build embeds. Kept here rather
 * than on any one module so `EventLogger`, `Onboarding`, … don't import each
 * other just to truncate a field.
 *
 * @since 2.0.0
 */
final class Text
{
    /**
     * Clips `$text` so it fits in a Discord embed field (value limit 1024,
     * name limit 256), appending `…` when it had to cut. An empty string
     * becomes a visible placeholder so it can still be used as a field value —
     * Discord rejects genuinely empty ones.
     *
     * @param int $limit Max length of the returned string, ellipsis included.
     */
    public static function clip(string $text, int $limit = 1024): string
    {
        if ($text === '') {
            return '*(empty)*';
        }

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, max(0, $limit - 1)) . '…'
            : $text;
    }

    /**
     * A `[name, value, inline]` triple for {@see \Discord\Parts\Embed\Embed::addFieldValues()},
     * pre-clipped to Discord's field limits (name 256, value 1024). DiscordPHP's
     * Embed builder documents those limits but does NOT enforce them — it only
     * checks the 25-field count — so a long dynamic value otherwise reaches
     * Discord and comes back `50035`. Spread it: `$e->addFieldValues(...Text::field($n, $v))`.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    public static function field(string $name, string $value, bool $inline = false): array
    {
        return [self::clip($name, 256), self::clip($value, 1024), $inline];
    }
}
