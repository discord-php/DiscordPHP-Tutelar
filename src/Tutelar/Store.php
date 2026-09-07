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
 * JSON-file backed store for everything Tutelar changes at runtime and must keep
 * across a restart: per-guild channel/role overrides plus small module scratch
 * space. Writes are atomic (temp file + rename).
 *
 * Replaces the legacy `VarSave()` / `VarLoad()` and the in-memory
 * `$tutelar->discord_config` array.
 *
 * @since 2.0.0
 */
final class Store
{
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];

        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /**
     * The effective config for a guild: the file defaults from {@see Config}
     * with this store's runtime overrides merged on top.
     */
    public function guildConfig(Config $config, int|string $guildId): GuildConfig
    {
        $overrides = $this->data['guilds'][(string) $guildId] ?? [];

        return $config->guild($guildId)->mergedWith(is_array($overrides) ? $overrides : []);
    }

    /** Sets one named channel for a guild and persists. */
    public function setGuildChannel(int|string $guildId, string $name, string $channelId): void
    {
        $this->data['guilds'][(string) $guildId]['channels'][$name] = $channelId;
        $this->save();
    }

    /** Sets one named role for a guild and persists. */
    public function setGuildRole(int|string $guildId, string $name, string $roleId): void
    {
        $this->data['guilds'][(string) $guildId]['roles'][$name] = $roleId;
        $this->save();
    }

    /** Wipes a guild's runtime overrides (`!s reset`). */
    public function forgetGuild(int|string $guildId): void
    {
        unset($this->data['guilds'][(string) $guildId]);
        $this->save();
    }

    /** Reads a module's scratch value. */
    public function moduleGet(string $module, string $key, mixed $default = null): mixed
    {
        return $this->data['modules'][$module][$key] ?? $default;
    }

    /** Writes a module's scratch value and persists. */
    public function moduleSet(string $module, string $key, mixed $value): void
    {
        $this->data['modules'][$module][$key] = $value;
        $this->save();
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private function save(): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }
        @rename($tmp, $this->path);
    }
}
