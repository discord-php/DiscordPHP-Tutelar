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
 * Read-only bot configuration, loaded once from a JSON file (default
 * `config.json`). This is the static half — identity, links, the presence
 * rotation, and each guild's *default* channel/role wiring. Anything a command
 * changes at runtime lives in {@see Store} instead, so the config file can be
 * committed (minus the token, which only ever comes from the environment).
 *
 * Replaces the legacy `token.php` / `secret.php` / inline `$options` array.
 *
 * @since 2.0.0
 */
final class Config
{
    /**
     * @param string                                               $token            Discord bot token (from the environment, never the file).
     * @param string|null                                          $ownerId          Bot owner's Discord user id. Loaded for future owner-only commands; no module reads it yet.
     * @param string|null                                          $github           Repo URL shown in embed footers.
     * @param list<array{name: string, type: int, state?: string}> $presence         Rotating activity list; `type` is an Activity type (0-5), `state` a presence status.
     * @param int                                                  $presenceInterval Seconds between presence changes.
     * @param array<string, GuildConfig>                           $guilds           Per-guild defaults, keyed by guild id.
     */
    public function __construct(
        public readonly string $token,
        public readonly ?string $ownerId,
        public readonly ?string $github,
        public readonly array $presence,
        public readonly int $presenceInterval,
        public readonly array $guilds,
    ) {}

    /**
     * Builds the config from `$path` (JSON) with environment overrides.
     *
     * @throws \RuntimeException When the file exists but is not valid JSON, or
     *                           when the token is not set anywhere.
     */
    public static function load(string $path, array $env): self
    {
        $data = [];
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Config file {$path} is not valid JSON: " . json_last_error_msg());
            }
        }
        $data = is_array($data) ? $data : [];

        $token = (string) ($env['TOKEN'] ?? $data['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('No bot token — set TOKEN in the environment (or `token` in ' . $path . ').');
        }

        $guilds = [];
        foreach ((array) ($data['guilds'] ?? []) as $id => $g) {
            $guilds[(string) $id] = GuildConfig::fromArray(is_array($g) ? $g : []);
        }

        return new self(
            $token,
            ($env['OWNER_ID'] ?? $data['owner_id'] ?? null) ?: null,
            ($data['github'] ?? null) ?: null,
            self::normalisePresence($data['presence'] ?? []),
            (int) ($data['presence_interval'] ?? 120),
            $guilds,
        );
    }

    /** The configured defaults for one guild, or an empty {@see GuildConfig}. */
    public function guild(int|string $guildId): GuildConfig
    {
        return $this->guilds[(string) $guildId] ?? GuildConfig::fromArray([]);
    }

    /**
     * Accepts either bare strings (`"playing X"`) or objects
     * (`{name, type, state}`) and normalises them. `type` is clamped to a valid
     * Activity type (0-5) so a typo can't make {@see \Discord\Discord::updatePresence()}
     * throw from inside the rotation timer.
     *
     * @param mixed $raw
     *
     * @return list<array{name: string, type: int, state?: string}>
     */
    private static function normalisePresence(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $entry) {
            if (is_string($entry)) {
                $out[] = ['name' => $entry, 'type' => 0];
            } elseif (is_array($entry) && isset($entry['name'])) {
                $type = (int) ($entry['type'] ?? 0);
                $out[] = [
                    'name' => (string) $entry['name'],
                    'type' => ($type >= 0 && $type <= 5) ? $type : 0,
                    'state' => (string) ($entry['state'] ?? 'online'),
                ];
            }
        }

        return $out;
    }
}
