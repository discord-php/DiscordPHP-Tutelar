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
use Discord\Parts\Guild\Role;
use Discord\Parts\Permissions\RolePermission;
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
     * `$channel` when given? A null member counts as "no" (fail closed).
     *
     * Discord hands us the same fact through several channels, each of which can
     * be unavailable in a given moment, so this ORs **every** source it can
     * reach and grants on the first hit ({@see resolve()}):
     *
     *   1. guild owner → yes (owners hold every permission implicitly);
     *   2. `$member->permissions` — the effective bitset Discord ships **on the
     *      interaction payload**, needing no cache;
     *   3. `Member::getPermissions()` — the role graph + channel overwrites,
     *      computed from cache;
     *   4. a raw walk of `@everyone` + the member's own roles against the guild
     *      role cache — the reliable fallback when (2) is absent and (3) bails
     *      (a cold `@everyone` role, or a member part hydrated without its role
     *      graph, which is what made an admin get "you need Manage Server").
     *
     * `administrator` in any source implies every permission.
     *
     * @param list<string> $any    Permission names — see DiscordPHP's RolePermission.
     * @param Member|null  $member The member to check.
     */
    public static function memberHasAny(array $any, ?Member $member, ?Channel $channel = null): bool
    {
        if ($member === null) {
            return false;
        }

        $guild = $member->guild;
        $isOwner = $guild !== null && (string) $guild->owner_id === (string) $member->id;

        // Source 2: the interaction-payload effective bitset.
        $bitset = null;
        try {
            $perms = $member->permissions;
            if ($perms instanceof RolePermission) {
                $bitset = self::flagMap($any, $perms);
            }
        } catch (\Throwable) {
            // getPermissionsAttribute can throw on a half-built member — ignore.
        }

        $roleMaps = [];

        // Source 3: the computed role graph (+ channel overwrites).
        try {
            $computed = $member->getPermissions($channel);
            if ($computed instanceof RolePermission) {
                $roleMaps[] = self::flagMap($any, $computed);
            }
        } catch (\Throwable) {
        }

        // Source 4: walk the roles directly against the guild cache.
        if ($guild !== null) {
            try {
                $everyone = $guild->roles->get('id', (string) $guild->id);
                if ($everyone instanceof Role && $everyone->permissions instanceof RolePermission) {
                    $roleMaps[] = self::flagMap($any, $everyone->permissions);
                }
                foreach ($member->roles as $role) {
                    if ($role instanceof Role && $role->permissions instanceof RolePermission) {
                        $roleMaps[] = self::flagMap($any, $role->permissions);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return self::resolve($any, $isOwner, $bitset, $roleMaps);
    }

    /**
     * Pure decision over the gathered signals: owner wins outright, then any
     * bitset or per-role map that {@see grantsAny()} accepts.
     *
     * @param list<string>             $any
     * @param array<string, bool>|null $bitset   interaction effective perms, `name => held`
     * @param list<array<string,bool>> $roleMaps per-source perm maps (computed graph, `@everyone`, each role)
     */
    public static function resolve(array $any, bool $isOwner, ?array $bitset, array $roleMaps): bool
    {
        if ($isOwner) {
            return true;
        }
        if ($bitset !== null && self::grantsAny($any, $bitset)) {
            return true;
        }
        foreach ($roleMaps as $held) {
            if (self::grantsAny($any, $held)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a {@see RolePermission} to a `name => held?` map covering
     * `administrator` plus every name in `$any`. Pure given the part.
     *
     * @param list<string> $any
     *
     * @return array<string, bool>
     */
    private static function flagMap(array $any, RolePermission $perms): array
    {
        $flags = ['administrator' => ! empty($perms->administrator)];
        foreach ($any as $perm) {
            $flags[$perm] = ! empty($perms->{$perm});
        }

        return $flags;
    }

    /**
     * Pure form of the check with the `administrator`-implies-everything rule
     * folded in: `administrator` in `$granted` wins regardless of `$any`.
     *
     * @param list<string>        $any
     * @param array<string, bool> $granted permission name => held? (may include `administrator`)
     */
    public static function grantsAny(array $any, array $granted): bool
    {
        return ! empty($granted['administrator']) || self::anyGranted($any, $granted);
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
