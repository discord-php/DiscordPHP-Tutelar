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

use React\Promise\PromiseInterface;
use Tutelar\Support\Filesystem;
use Tutelar\Support\JsonFile;

/**
 * JSON-file backed store for everything Tutelar changes at runtime and must keep
 * across a restart: per-guild channel/role overrides plus small module scratch
 * space — which includes every open ticket and its progress log.
 *
 * Durability and non-blocking writes both belong to {@see JsonFile}: the file is
 * written atomically, a `.bak` is kept, a damaged file is recovered from it or
 * preserved rather than overwritten, and the write itself happens off the event
 * loop where the platform can. A mutator here updates memory and returns; the
 * disk catches up.
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

    private readonly JsonFile $file;

    public function __construct(string $path, ?Filesystem $filesystem = null)
    {
        $this->file = new JsonFile($path, $filesystem);
        $this->data = $this->file->load();
    }

    /**
     * Anything that went wrong reading the state file, for the startup
     * report. Empty on a normal start.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->file->warnings();
    }

    /** Where the state lives, and what it is written with — for the log. */
    public function path(): string
    {
        return $this->file->path();
    }

    public function filesystem(): Filesystem
    {
        return $this->file->filesystem();
    }

    /** Resolves once everything changed so far has reached the disk. */
    public function saved(): PromiseInterface
    {
        return $this->file->saved();
    }

    /**
     * Writes now, blocking. For shutdown: a queued write would never run
     * once the loop has stopped.
     */
    public function flush(): bool
    {
        return $this->file->flush();
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
     * Hands the new state to {@see JsonFile}, which writes it off the loop
     * and folds rapid changes into a single write.
     */
    private function save(): void
    {
        $this->file->save($this->data);
    }
}
