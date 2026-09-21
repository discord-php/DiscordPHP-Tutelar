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

use Discord\Builders\MessageBuilder;
use Discord\MessageCommandClient;
use Discord\Parts\Channel\Message\AllowedMentions;
use Tutelar\Modules\Module;

/**
 * The Tutelar client — a DiscordPHP {@see MessageCommandClient} that additionally
 * carries the loaded {@see Config}, the runtime {@see Store}, and an ordered list
 * of feature {@see Module}s that are booted once the gateway is ready.
 *
 * Rebuild of the legacy procedural `Tutelar\Tutelar` (one class, a 600-line
 * `$options` closure bag): configuration is data, features are modules, secrets
 * only come from the environment.
 *
 * @since 2.0.0
 */
class Tutelar extends MessageCommandClient
{
    public const GITHUB = 'https://github.com/discord-php/DiscordPHP-Tutelar';

    protected Config $config;

    protected Store $store;

    /** @var list<Module> */
    protected array $modules = [];

    protected bool $modulesBooted = false;

    /**
     * Trouble reported by a store that was read before the bot existed.
     *
     * @var list<array{label: string, warnings: list<string>}>
     *
     * @since 2.4.0
     */
    protected array $startupWarnings = [];

    public function __construct(Config $config, Store $store, array $options = [])
    {
        $this->config = $config;
        $this->store = $store;

        parent::__construct($options + ['token' => $config->token]);

        $ready = false;
        $appReady = false;
        $boot = function () use (&$ready, &$appReady): void {
            if ($ready && $appReady && ! $this->modulesBooted) {
                $this->bootModules();
            }
        };
        $this->once('init', static function () use (&$ready, $boot): void {
            $ready = true;
            $boot();
        });
        $this->once('application-init', static function () use (&$appReady, $boot): void {
            $appReady = true;
            $boot();
        });
    }

    /**
     * Records something that went wrong while a store was being read, to be
     * reported once the gateway is up.
     *
     * The stores are constructed before there is a bot to log through, and a
     * file that could not be read is exactly the thing an operator has to
     * hear about — so it is held until there is somewhere to say it.
     *
     * @param list<string> $warnings
     *
     * @since 2.4.0
     */
    public function addStartupWarnings(string $label, array $warnings): self
    {
        if ($warnings !== []) {
            $this->startupWarnings[] = ['label' => $label, 'warnings' => $warnings];
        }

        return $this;
    }

    /** Adds a module. Call before {@see run()}; modules boot in registration order. */
    public function addModule(Module $module): self
    {
        $this->modules[] = $module;

        return $this;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    /** The effective config for a guild (file defaults + runtime overrides). */
    public function guild(int|string $guildId): GuildConfig
    {
        return $this->store->guildConfig($this->config, $guildId);
    }

    /**
     * A {@see MessageBuilder} that can ping users (so "welcome, @member" works)
     * but never roles or `@everyone`.
     */
    public static function reply(bool $allowUserMention = true): MessageBuilder
    {
        return MessageBuilder::new()->setAllowedMentions(
            $allowUserMention
                ? AllowedMentions::new()->setParse([AllowedMentions::TYPE_USER])
                : AllowedMentions::none(),
        );
    }

    /**
     * Says what was restored from disk, and what could not be.
     *
     * A store that failed to load leaves the bot running with less than it
     * was given — no guild overrides, no open tickets, or no moderation
     * history — and nothing else would ever mention it.
     *
     * @since 2.4.0
     */
    private function reportRestoredState(): void
    {
        $this->logger->info('[tutelar] state restored from ' . $this->store->path());
        $this->logger->info('[tutelar] ' . $this->store->filesystem()->describe());

        $reports = $this->startupWarnings;

        if ($this->store->warnings() !== []) {
            array_unshift($reports, ['label' => 'runtime state', 'warnings' => $this->store->warnings()]);
        }

        if ($reports === []) {
            return;
        }

        $lines = [];

        foreach ($reports as $report) {
            foreach ($report['warnings'] as $warning) {
                $this->logger->warning(sprintf('[tutelar] %s: %s', $report['label'], $warning));
                $lines[] = sprintf('**%s** — %s', $report['label'], $warning);
            }
        }

        $this->notifyOwner("⚠️ **I had trouble reading what I had saved.**\n- " . implode("\n- ", $lines));
    }

    /**
     * DMs the configured owner, when there is one.
     *
     * @since 2.4.0
     */
    public function notifyOwner(string $content): void
    {
        $ownerId = $this->config->ownerId;

        if ($ownerId === null || $ownerId === '') {
            return;
        }

        $this->users->fetch($ownerId)->then(
            fn ($user) => $user->sendMessage(self::reply(false)->setContent($content)),
        )->then(null, fn (\Throwable $e) => $this->logger->error(
            '[tutelar] could not DM the owner: ' . $e->getMessage(),
        ));
    }

    private function bootModules(): void
    {
        $this->modulesBooted = true;
        $this->reportRestoredState();

        foreach ($this->modules as $module) {
            try {
                $module->boot($this);
                $this->logger->info('[tutelar] module booted: ' . $module->name());
            } catch (\Throwable $e) {
                $this->logger->error('[tutelar] module ' . $module->name() . ' failed to boot: ' . $e->getMessage());
            }
        }
    }
}
