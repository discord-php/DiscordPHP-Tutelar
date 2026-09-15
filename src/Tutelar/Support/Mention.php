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
 * Discord mention syntax, in one place.
 *
 * The rule these exist to enforce: **anything Tutelar logs about a user must be
 * clickable**. A name rendered as plain text (an embed author line, a display
 * name in a sentence) is a dead end for whoever reads the log later — they
 * can't open the profile, can't check the account, can't act. A `<@id>` mention
 * renders as the member's name *and* opens their profile, so it is always the
 * right way to name someone.
 *
 * A mention inside an embed never pings regardless of `allowed_mentions`, and
 * outside one the message's `allowed_mentions` decides — so using these is safe
 * in a log feed that must not notify anybody.
 *
 * @since 2.3.0
 */
final class Mention
{
    /**
     * `<@id>` — the member's name, linked to their profile. Falls back to a
     * visible placeholder rather than emitting `<@>`, which Discord renders as
     * broken literal text.
     */
    public static function user(int|string|null $id): string
    {
        return ($id = self::id($id)) === null ? '*(unknown user)*' : "<@{$id}>";
    }

    /** `<#id>` — the channel, linked. */
    public static function channel(int|string|null $id): string
    {
        return ($id = self::id($id)) === null ? '*(unknown channel)*' : "<#{$id}>";
    }

    /** `<@&id>` — the role, linked. */
    public static function role(int|string|null $id): string
    {
        return ($id = self::id($id)) === null ? '*(unknown role)*' : "<@&{$id}>";
    }

    /**
     * A profile link for an embed's `author.url`, so the name + avatar line at
     * the top of a log entry is clickable too. Null when there is no usable id
     * ({@see \Discord\Parts\Embed\Embed::setAuthor()} takes null happily).
     */
    public static function profileUrl(int|string|null $id): ?string
    {
        return ($id = self::id($id)) === null ? null : "https://discord.com/users/{$id}";
    }

    /**
     * `<@id> · ` + the raw id — the field value to use when a log entry is
     * *about* someone. The mention opens the profile; the id stays readable
     * when the mention can't resolve, which is exactly the case that matters
     * in a "member left" or "member banned" entry.
     */
    public static function userLine(int|string|null $id): string
    {
        return ($id = self::id($id)) === null ? '*(unknown user)*' : "<@{$id}> · `{$id}`";
    }

    /** A usable snowflake, or null for anything that isn't one. */
    private static function id(int|string|null $id): ?string
    {
        $id = (string) ($id ?? '');

        return $id !== '' && ctype_digit($id) ? $id : null;
    }
}
