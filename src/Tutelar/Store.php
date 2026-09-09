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
 * The `setGuild*` / `clearGuild*` / `forgetGuild` mutators are the write side of
 * the `/config` command ({@see \Tutelar\Modules\Configuration}); `config.json` still
 * supplies the per-guild *defaults* these overrides layer on top of.
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

    /**
     * Drops one named channel override for a guild (the guild falls back to its
     * `config.json` default for that name, if any). No-op when nothing is set.
     */
    public function clearGuildChannel(int|string $guildId, string $name): void
    {
        if (! isset($this->data['guilds'][(string) $guildId]['channels'][$name])) {
            return;
        }
        unset($this->data['guilds'][(string) $guildId]['channels'][$name]);
        $this->save();
    }

    /** Drops one named role override for a guild. No-op when nothing is set. */
    public function clearGuildRole(int|string $guildId, string $name): void
    {
        if (! isset($this->data['guilds'][(string) $guildId]['roles'][$name])) {
            return;
        }
        unset($this->data['guilds'][(string) $guildId]['roles'][$name]);
        $this->save();
    }

    /** Wipes a guild's runtime overrides, falling the guild back to `config.json` defaults. */
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

    /**
     * Atomically persists the store: encode, write a pid-suffixed sibling temp
     * file, then rename it over the target. Bails without touching the live file
     * if the data can't be encoded (e.g. a module stashed a non-UTF-8 string) or
     * the temp write fails, so a bad value never truncates the state.
     */
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
