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
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Onboarding as OnboardingPart;
use Discord\Parts\Guild\OnboardingPrompt;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;
use Tutelar\Support\Permissions;
use Tutelar\Tutelar;

/**
 * Channel/role self-assignment, done Discord's way.
 *
 * The legacy bot shipped its own reaction-role engine. Discord now has that
 * built in — **Server Settings → Onboarding** and the **Channels &amp; Roles**
 * tab — so Tutelar doesn't reimplement it. This module is just the thin
 * management surface on top: `/onboarding view` audits the live flow (prompts,
 * the roles/channels each option grants, the auto-opt-in channels) and
 * `/onboarding enable|disable` flips it, both gated on Manage Server.
 *
 * Guild-only; requires the bot to have `MANAGE_GUILD` + `MANAGE_ROLES` to write.
 *
 * @since 2.0.0
 */
final class Onboarding implements Module
{
    private const COLOR = 0xA7C5FD;

    public function name(): string
    {
        return 'onboarding';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(fn(GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $bot->listenCommand('onboarding', fn(Interaction $i) => $this->route($bot, $i));
    }

    private function define(Tutelar $bot, GlobalCommandRepository $repo): void
    {
        if ($repo->get('name', 'onboarding') !== null) {
            return;
        }

        $sub = static fn(string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

        CommandBuilder::new()
            ->setName('onboarding')
            ->setType(Command::CHAT_INPUT)
            ->setDescription('Inspect or toggle this server\'s built-in onboarding / Channels & Roles flow.')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL)
            ->addOption($sub('view', 'Show the current onboarding prompts and what each option grants.'))
            ->addOption($sub('enable', 'Turn onboarding on.'))
            ->addOption($sub('disable', 'Turn onboarding off.'))
            ->create($repo)
            ->save('onboarding command');
    }

    private function route(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if ($guild === null) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('This command only works in a server.'), true);
        }

        if (! Permissions::memberHasAny(Permissions::MANAGER, $interaction->member)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need **Manage Server** to use this.'), true);
        }

        $action = (string) ($interaction->data->options?->first()?->name ?? 'view');

        return match ($action) {
            'enable' => $this->toggle($bot, $interaction, $guild, true),
            'disable' => $this->toggle($bot, $interaction, $guild, false),
            default => $this->view($bot, $interaction, $guild),
        };
    }

    private function view(Tutelar $bot, Interaction $interaction, object $guild): PromiseInterface
    {
        return $guild->getOnboarding()->then(
            fn(OnboardingPart $onboarding) => $interaction->respondWithMessage(
                Tutelar::reply(false)->addEmbed($this->embed($bot, $onboarding)),
                true,
            ),
            fn(\Throwable $e) => $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent('Could not read onboarding: ' . $e->getMessage()),
                true,
            ),
        );
    }

    private function toggle(Tutelar $bot, Interaction $interaction, object $guild, bool $enabled): PromiseInterface
    {
        return $guild->modifyOnboarding(['enabled' => $enabled], 'Tutelar /onboarding by ' . $interaction->user->id)->then(
            fn() => $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent($enabled ? '✅ Onboarding is now **on**.' : '✅ Onboarding is now **off**.'),
                true,
            ),
            fn(\Throwable $e) => $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent('Could not update onboarding: ' . $e->getMessage() . "\n(the bot needs **Manage Server** + **Manage Roles**)"),
                true,
            ),
        );
    }

    private function embed(Tutelar $bot, OnboardingPart $onboarding): Embed
    {
        $embed = (new Embed($bot))
            ->setColor(self::COLOR)
            ->setTitle('Server onboarding')
            ->setDescription($onboarding->enabled ? '🟢 Enabled' : '⚪ Disabled')
            ->setTimestamp();

        if ($github = $bot->getConfig()->github) {
            $embed->setFooter($github);
        }

        $defaults = self::mentionList((array) $onboarding->default_channel_ids, '#');
        $embed->addFieldValues('Auto opt-in channels', $defaults === '' ? '*(none)*' : $defaults);

        $count = 0;
        foreach (($onboarding->prompts ?? []) as $prompt) {
            if (++$count > 20) {
                break;
            }
            $embed->addFieldValues(
                self::promptHeading(
                    (string) $prompt->title,
                    (bool) $prompt->required,
                    (bool) $prompt->single_select,
                    (bool) $prompt->in_onboarding,
                ),
                self::promptBody(self::optionRows($prompt)),
            );
        }

        if ($count === 0) {
            $embed->addFieldValues('Prompts', '*(none configured — set them up in Server Settings → Onboarding)*');
        }

        return $embed;
    }

    /**
     * Flattens a prompt's options Part collection to plain rows for
     * {@see promptBody()}.
     *
     * @return list<array{title: string, role_ids: list<string>, channel_ids: list<string>}>
     */
    private static function optionRows(OnboardingPrompt $prompt): array
    {
        $rows = [];
        foreach ($prompt->options ?? [] as $option) {
            $rows[] = [
                'title' => (string) $option->title,
                'role_ids' => array_map('strval', (array) $option->role_ids),
                'channel_ids' => array_map('strval', (array) $option->channel_ids),
            ];
        }

        return $rows;
    }

    // --- pure helpers (unit-tested) ------------------------------------

    public static function promptHeading(string $title, bool $required, bool $singleSelect, bool $inOnboarding): string
    {
        $flags = [];
        if ($required) {
            $flags[] = 'required';
        }
        $flags[] = $singleSelect ? 'pick one' : 'pick any';
        $flags[] = $inOnboarding ? 'in onboarding' : 'Channels & Roles only';

        return EventLogger::trim(($title ?: 'Untitled prompt') . ' — ' . implode(', ', $flags), 240);
    }

    /**
     * @param list<array{title: string, role_ids: list<string>, channel_ids: list<string>}> $options
     */
    public static function promptBody(array $options): string
    {
        $lines = [];
        foreach ($options as $option) {
            $grants = array_filter([
                self::mentionList($option['role_ids'] ?? [], '@&'),
                self::mentionList($option['channel_ids'] ?? [], '#'),
            ]);
            $lines[] = '• ' . (($option['title'] ?? '') ?: 'Untitled') . ($grants ? ' → ' . implode('  ', $grants) : '');
        }

        return EventLogger::trim($lines === [] ? '*(no options)*' : implode("\n", $lines), 1000);
    }

    /** `<#id> <#id>` / `<@&id> <@&id>` for a list of ids, capped so the field fits. */
    public static function mentionList(array $ids, string $sigil): string
    {
        $out = [];
        foreach (array_slice($ids, 0, 15) as $id) {
            $out[] = '<' . $sigil . $id . '>';
        }

        return implode(' ', $out);
    }
}
