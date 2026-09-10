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
 * *any* of the named Discord permissions?
 *
 * For a command / component interaction, prefer {@see forInteraction()} — it
 * reads the effective permission bitfield Discord puts **on the interaction
 * payload** (`member.permissions`, already resolved against the member's roles,
 * `@everyone` and the channel's overwrites, needing no cache) before it ever
 * touches a DiscordPHP `Member` part. That raw field is the authoritative
 * source; the part-based path ({@see memberHasAny()}) is the fallback for
 * non-interaction call sites and the belt-and-braces union.
 *
 * @since 2.0.0
 */
final class Permissions
{
    /** Permission sets the moderation commands accept. */
    public const MODERATOR = ['administrator', 'manage_guild', 'ban_members', 'kick_members', 'moderate_members'];

    public const MANAGER = ['administrator', 'manage_guild'];

    /**
     * Bit POSITION (not value) of each permission name this helper understands,
     * from Discord's permission flags (`1 << position`). `administrator` (3)
     * implies every permission.
     *
     * @var array<string, int>
     */
    public const POSITIONS = [
        'kick_members' => 1,
        'ban_members' => 2,
        'administrator' => 3,
        'manage_channels' => 4,
        'manage_guild' => 5,
        'manage_messages' => 13,
        'manage_roles' => 28,
        'moderate_members' => 40,
    ];

    /**
     * Gate for a slash-command or component {@see \Discord\Parts\Interactions\Interaction}:
     * does the invoking member hold any of `$any`?
     *
     *   1. the raw `member.permissions` bitfield off the interaction payload
     *      ({@see bitsFromInteraction()} → {@see bitsGrant()}) — no cache, and
     *      already channel-resolved by Discord;
     *   2. failing that, the part-based union ({@see memberHasAny()}).
     *
     * @param list<string> $any
     */
    public static function forInteraction(array $any, object $interaction): bool
    {
        if (self::bitsGrant($any, self::bitsFromInteraction($interaction))) {
            return true;
        }

        $member = $interaction->member ?? null;

        return $member instanceof Member && self::memberHasAny($any, $member);
    }

    /**
     * The `member.permissions` decimal string carried on an interaction
     * payload — the fully-resolved effective permissions Discord computed for
     * the invoking member in the interaction's channel — or `null` when it
     * isn't reachable (a DM interaction, or a shape we don't recognise).
     *
     * Tries the interaction's **raw** `member` attribute first (the pristine
     * `stdClass` straight off the gateway, which always carries `permissions`),
     * then the transformed `Member` part's raw attributes, then its getter.
     * `ArrayAccess` / `->member` both run DiscordPHP's `getMemberAttribute()`
     * transform, so they are NOT a source of the raw string on their own.
     */
    public static function bitsFromInteraction(object $interaction): ?string
    {
        $candidates = [];
        if (method_exists($interaction, 'getRawAttributes')) {
            $candidates[] = $interaction->getRawAttributes()['member'] ?? null;
        }
        $candidates[] = $interaction->member ?? null;

        foreach ($candidates as $member) {
            $perms = self::rawPermValue($member);
            if ($perms !== null) {
                return $perms;
            }
        }

        return null;
    }

    /**
     * Pull a `permissions` value out of whatever shape a `member` attribute
     * took — a gateway `stdClass`, an array, or a DiscordPHP `Part` (read its
     * raw attributes, not the mutating getter) — and normalise to a decimal
     * string. `null` when there is nothing usable.
     */
    private static function rawPermValue(mixed $member): ?string
    {
        if (is_array($member)) {
            $perms = $member['permissions'] ?? null;
        } elseif (is_object($member)) {
            $perms = null;
            if (method_exists($member, 'getRawAttributes')) {
                $perms = $member->getRawAttributes()['permissions'] ?? null;
            }
            $perms ??= ($member->permissions ?? null);
        } else {
            return null;
        }

        if (is_object($perms)) {          // a RolePermission part → its decimal bitwise
            $perms = (string) $perms;
        }
        if (is_int($perms)) {
            $perms = (string) $perms;
        }

        return (is_string($perms) && $perms !== '' && ctype_digit($perms)) ? $perms : null;
    }

    /**
     * Does a raw permission bitfield grant any of `$any`? `administrator`
     * (bit 3) short-circuits. Pure.
     *
     * `(int)` on a decimal permission string is exact on 64-bit PHP for the
     * whole current flag range (highest documented bit is 50); a 32-bit build
     * would truncate the high flags, which this helper does not gate on.
     *
     * @param list<string> $any
     */
    public static function bitsGrant(array $any, int|string|null $bits): bool
    {
        if ($bits === null || $bits === '') {
            return false;
        }
        $n = (int) $bits;

        if ((($n >> self::POSITIONS['administrator']) & 1) === 1) {
            return true;
        }
        foreach ($any as $perm) {
            $pos = self::POSITIONS[$perm] ?? null;
            if ($pos !== null && (($n >> $pos) & 1) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does `$member` hold any of `$any` — server-wide, or in `$channel` when
     * given? A null member counts as "no" (fail closed).
     *
     * ORs every source it can reach and grants on the first hit
     * ({@see resolve()}):
     *
     *   1. guild owner → yes;
     *   2. `$member->permissions` — the interaction-payload effective bitset;
     *   3. `Member::getPermissions()` — the computed role graph + overwrites;
     *   4. a raw walk of `@everyone` + the member's own roles against the guild
     *      role cache — the reliable fallback when 2 is absent and 3 bails.
     *
     * `administrator` in any source implies every permission.
     *
     * @param list<string> $any
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
