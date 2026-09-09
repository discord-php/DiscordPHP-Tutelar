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

namespace Tutelar\Modules;

use Discord\Builders\CommandBuilder;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;
use Tutelar\GuildConfig;
use Tutelar\Support\Permissions;
use Tutelar\Tutelar;

/**
 * `/config` — the whole of Tutelar's per-guild wiring, editable from Discord so
 * a server never has to touch `config.json` on the host.
 *
 * Today that wiring is two named channels:
 *
 *  - **`log`** — where {@see EventLogger} posts the audit feed, and the
 *    fallback target for moderation cases.
 *  - **`modlog`** — where {@see Moderation} posts numbered cases; falls back to
 *    `log` when unset.
 *
 * `config.json` still supplies each guild's *defaults*; `/config set` /
 * `/config unset` layer runtime overrides on top via {@see \Tutelar\Store}, and
 * `/config reset` drops the overrides so the guild falls back to the file.
 * Guild-only, gated on {@see Permissions::MANAGER} (Administrator / Manage
 * Server) — regular members can't see or change any of it.
 *
 * @since 2.2.0
 */
final class Configuration implements Module
{
    /**
     * The editable settings, keyed by the {@see GuildConfig} channel name.
     *
     * @var array<string, array{label: string, blurb: string}>
     */
    public const SETTINGS = [
        'log' => [
            'label' => 'Log channel',
            'blurb' => 'Server audit feed (joins, leaves, edits, deletes, bans) — also the fallback for moderation cases.',
        ],
        'modlog' => [
            'label' => 'Mod-log channel',
            'blurb' => 'Numbered moderation cases (warn / kick / ban / timeout / purge / lock). Falls back to the log channel when unset.',
        ],
    ];

    public function name(): string
    {
        return 'config';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $bot->listenCommand('config', fn (Interaction $i) => $this->route($bot, $i));
    }

    private function define(Tutelar $bot, GlobalCommandRepository $repo): void
    {
        if ($repo->get('name', 'config') !== null) {
            return;
        }

        $targetOption = function () use ($bot): Option {
            $o = (new Option($bot))
                ->setType(Option::STRING)
                ->setName('setting')
                ->setDescription('Which setting to change.')
                ->setRequired(true);
            foreach (self::SETTINGS as $key => $meta) {
                $o->addChoice((new Choice($bot))->setName($meta['label'])->setValue($key));
            }

            return $o;
        };

        $sub = static fn (string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

        CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName('config')
            ->setDescription("Configure Tutelar for this server (channels it posts to). Manage Server only.")
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addOption($sub('view', 'Show every setting, its value, and where that value comes from.'))
            ->addOption($sub('set', 'Point a setting at a channel.')
                ->addOption($targetOption())
                ->addOption((new Option($bot))
                    ->setType(Option::CHANNEL)
                    ->setName('channel')
                    ->setDescription('The channel to use.')
                    ->setRequired(true)))
            ->addOption($sub('unset', 'Clear a setting (fall back to the config.json default, if any).')
                ->addOption($targetOption()))
            ->addOption($sub('reset', 'Clear every runtime override for this server.'))
            ->create($repo)
            ->save('config command');
    }

    private function route(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }
        if (! Permissions::memberHasAny(Permissions::MANAGER, $interaction->member)) {
            return $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent('You need **Manage Server** (or Administrator) to change the bot config.'),
                true,
            );
        }

        $sub = $interaction->data->options?->first();
        $name = (string) ($sub?->name ?? '');
        $arg = static fn (string $k): mixed => $sub?->options?->get('name', $k)?->value;

        return match ($name) {
            'view' => $this->view($bot, $interaction, $guild),
            'set' => $this->set($bot, $interaction, $guild, (string) $arg('setting'), (string) $arg('channel')),
            'unset' => $this->unset($bot, $interaction, $guild, (string) $arg('setting')),
            'reset' => $this->reset($bot, $interaction, $guild),
            default => $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Unknown sub-command.'), true),
        };
    }

    private function view(Tutelar $bot, Interaction $interaction, Guild $guild): PromiseInterface
    {
        $effective = $bot->guild($guild->id);
        $defaults = $bot->getConfig()->guild($guild->id);

        return $interaction->respondWithMessage(
            Tutelar::reply(false)->setContent(self::summary($effective->channels, $defaults->channels)),
            true,
        );
    }

    private function set(Tutelar $bot, Interaction $interaction, Guild $guild, string $setting, string $channelId): PromiseInterface
    {
        if (! isset(self::SETTINGS[$setting])) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Unknown setting.'), true);
        }

        $bot->getStore()->setGuildChannel($guild->id, $setting, $channelId);

        return $interaction->respondWithMessage(
            Tutelar::reply(false)->setContent(sprintf('✅ **%s** is now <#%s>.', self::SETTINGS[$setting]['label'], $channelId)),
            true,
        );
    }

    private function unset(Tutelar $bot, Interaction $interaction, Guild $guild, string $setting): PromiseInterface
    {
        if (! isset(self::SETTINGS[$setting])) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Unknown setting.'), true);
        }

        $bot->getStore()->clearGuildChannel($guild->id, $setting);
        $fallback = $bot->getConfig()->guild($guild->id)->channel($setting);
        $tail = $fallback !== null
            ? sprintf(' It falls back to the config.json default, <#%s>.', $fallback)
            : ($setting === 'modlog' ? ' Cases will post to the log channel instead.' : ' That feature is now off until you set it again.');

        return $interaction->respondWithMessage(
            Tutelar::reply(false)->setContent(sprintf('🧹 Cleared **%s**.%s', self::SETTINGS[$setting]['label'], $tail)),
            true,
        );
    }

    private function reset(Tutelar $bot, Interaction $interaction, Guild $guild): PromiseInterface
    {
        $bot->getStore()->forgetGuild($guild->id);

        return $interaction->respondWithMessage(
            Tutelar::reply(false)->setContent('♻️ Cleared every runtime override for this server — back to the `config.json` defaults.'),
            true,
        );
    }

    /**
     * The `/config view` body: one line per setting with its current value and
     * whether that value is a runtime override, a `config.json` default, or
     * unset. Pure so it can be unit-tested.
     *
     * @param array<string, string> $effective name => channel id, defaults with overrides merged on top
     * @param array<string, string> $defaults  name => channel id, straight from `config.json`
     */
    public static function summary(array $effective, array $defaults): string
    {
        $lines = ['**Tutelar configuration**', ''];
        foreach (self::SETTINGS as $key => $meta) {
            $value = $effective[$key] ?? null;
            $default = $defaults[$key] ?? null;
            $source = match (true) {
                $value === null => '_not set_',
                $value !== $default => sprintf('<#%s> · set here', $value),
                default => sprintf('<#%s> · from config.json', $value),
            };
            $lines[] = sprintf('**%s** — %s', $meta['label'], $source);
            $lines[] = sprintf('_%s_', $meta['blurb']);
            $lines[] = '';
        }
        $lines[] = '`/config set` to point one at a channel · `/config unset` to clear one · `/config reset` for all.';

        return implode("\n", $lines);
    }
}
