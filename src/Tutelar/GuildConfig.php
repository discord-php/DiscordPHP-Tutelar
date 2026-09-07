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

namespace Tutelar;

/**
 * One guild's wiring: named channels (`log`, `suggestion_pending`, …) and named
 * roles. These are *defaults* from {@see Config}; runtime edits land in
 * {@see Store} and are merged on top by {@see Store::guildConfig()}.
 *
 * Self-assignable roles are deliberately not modelled here — that is Discord's
 * native **Onboarding** / **Channels &amp; Roles**, surfaced by the
 * {@see Modules\Onboarding} module rather than reimplemented as reaction roles.
 *
 * @since 2.0.0
 */
final class GuildConfig
{
    /**
     * @param array<string, string> $channels name => channel id
     * @param array<string, string> $roles    name => role id
     */
    public function __construct(
        public readonly array $channels = [],
        public readonly array $roles = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            self::strMap($data['channels'] ?? []),
            self::strMap($data['roles'] ?? []),
        );
    }

    public function channel(string $name): ?string
    {
        return $this->channels[$name] ?? null;
    }

    public function role(string $name): ?string
    {
        return $this->roles[$name] ?? null;
    }

    /**
     * Returns a copy with `$overrides` merged over this config (used to layer
     * {@see Store} runtime state onto the file defaults).
     */
    public function mergedWith(array $overrides): self
    {
        return new self(
            array_merge($this->channels, self::strMap($overrides['channels'] ?? [])),
            array_merge($this->roles, self::strMap($overrides['roles'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'channels' => $this->channels,
            'roles' => $this->roles,
        ];
    }

    /** @return array<string, string> */
    private static function strMap(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v))) {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }
}
