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

use Discord\Parts\Channel\Channel;
use Discord\Parts\User\Member;

/**
 * The legacy `perm_check()` closure, as a small typed helper: does a member hold
 * *any* of the named Discord permissions (optionally within a channel)?
 *
 * @since 2.0.0
 */
final class Permissions
{
    /** Permission sets the moderation commands accept. */
    public const MODERATOR = ['administrator', 'manage_guild', 'ban_members', 'kick_members', 'moderate_members'];

    public const MANAGER = ['administrator', 'manage_guild'];

    /**
     * Does `$member` hold any of the `$any` permissions — server-wide, or in
     * `$channel` when given? A null member, or a member whose permissions can't
     * be resolved, counts as "no" (fail closed).
     *
     * @param list<string> $any    Permission names — see DiscordPHP's RolePermission.
     * @param Member|null  $member The member to check.
     */
    public static function memberHasAny(array $any, ?Member $member, ?Channel $channel = null): bool
    {
        $held = $member?->getPermissions($channel);
        if ($held === null) {
            return false;
        }

        $flags = [];
        foreach ($any as $perm) {
            $flags[$perm] = ! empty($held->{$perm});
        }

        return self::anyGranted($any, $flags);
    }

    /**
     * Pure form: given the permission names to accept and a map of which are
     * granted, is any accepted one granted?
     *
     * @param list<string>        $any
     * @param array<string, bool> $granted permission name => held?
     */
    public static function anyGranted(array $any, array $granted): bool
    {
        foreach ($any as $perm) {
            if (! empty($granted[$perm])) {
                return true;
            }
        }

        return false;
    }
}
