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
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Onboarding as OnboardingPart;
use Discord\Parts\Guild\OnboardingPrompt;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;
use Tutelar\Support\Permissions;
use Tutelar\Support\Text;
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
 * Guild-only. Every sub-command reads or writes onboarding over the REST API
 * before it can reply, so the handler defers the interaction first (see
 * {@see route()}). Writing (`enable`/`disable`) needs the bot itself to hold
 * `MANAGE_GUILD` + `MANAGE_ROLES`.
 *
 * @since 2.0.0
 */
final class Onboarding implements Module
{
    private const COLOR = 0xA7C5FD;

    /** Stop adding prompt fields once the embed's text nears Discord's 6000-char ceiling. */
    private const EMBED_BUDGET = 5000;

    /** Discord allows 25 embed fields; one is spent on "Auto opt-in channels". */
    private const MAX_PROMPT_FIELDS = 24;

    public function name(): string
    {
        return 'onboarding';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $bot->listenCommand('onboarding', fn (Interaction $i) => $this->route($bot, $i));
    }

    private function define(Tutelar $bot, GlobalCommandRepository $repo): void
    {
        if ($repo->get('name', 'onboarding') !== null) {
            return;
        }

        $sub = static fn (string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

        // Guild-only (onboarding is a guild concept) and guild-install only — a
        // user-installed copy could only ever run in a guild anyway, and it is
        // gated on Manage Server on top.
        CommandBuilder::new()
            ->setName('onboarding')
            ->setType(Command::CHAT_INPUT)
            ->setDescription('Inspect or toggle this server\'s built-in onboarding / Channels & Roles flow.')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addOption($sub('view', 'Show the current onboarding prompts and what each option grants.'))
            ->addOption($sub('enable', 'Turn onboarding on.'))
            ->addOption($sub('disable', 'Turn onboarding off.'))
            ->create($repo)
            ->save('onboarding command');
    }

    private function route(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('This command only works in a server.'), true);
        }

        if (! Permissions::forInteraction(Permissions::MANAGER, $interaction)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need **Manage Server** to use this.'), true);
        }

        $action = (string) ($interaction->data->options?->first()?->name ?? 'view');

        // Every branch does a Discord API round-trip before it can reply, which
        // can outrun the 3-second interaction deadline — so defer first, then
        // edit the deferred (ephemeral) response.
        return $interaction->acknowledgeWithResponse(true)->then(fn () => match ($action) {
            'enable' => $this->toggle($interaction, $guild, true),
            'disable' => $this->toggle($interaction, $guild, false),
            default => $this->view($bot, $interaction, $guild),
        });
    }

    private function view(Tutelar $bot, Interaction $interaction, Guild $guild): PromiseInterface
    {
        return $guild->getOnboarding()->then(
            fn (OnboardingPart $onboarding) => $interaction->updateOriginalResponse(
                Tutelar::reply(false)->addEmbed($this->embed($bot, $onboarding)),
            ),
            fn (\Throwable $e) => $interaction->updateOriginalResponse(
                Tutelar::reply(false)->setContent('Could not read onboarding: ' . $e->getMessage()),
            ),
        );
    }

    private function toggle(Interaction $interaction, Guild $guild, bool $enabled): PromiseInterface
    {
        // Discord's Modify Guild Onboarding takes each field as optional, so
        // sending only `enabled` leaves the prompts and opt-in channels intact.
        return $guild->modifyOnboarding(['enabled' => $enabled], 'Tutelar /onboarding by ' . $interaction->user->id)->then(
            fn () => $interaction->updateOriginalResponse(
                Tutelar::reply(false)->setContent($enabled ? '✅ Onboarding is now **on**.' : '✅ Onboarding is now **off**.'),
            ),
            fn (\Throwable $e) => $interaction->updateOriginalResponse(
                Tutelar::reply(false)->setContent('Could not update onboarding: ' . $e->getMessage() . "\n(the bot needs **Manage Server** + **Manage Roles**)"),
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
        $embed->addFieldValues(...Text::field('Auto opt-in channels', $defaults === '' ? '*(none)*' : $defaults));

        $shown = 0;
        $budget = self::EMBED_BUDGET;
        $prompts = $onboarding->prompts ?? [];
        foreach ($prompts as $prompt) {
            $heading = self::promptHeading(
                (string) $prompt->title,
                (bool) $prompt->required,
                (bool) $prompt->single_select,
                (bool) $prompt->in_onboarding,
            );
            $body = self::promptBody(self::optionRows($prompt));
            $budget -= mb_strlen($heading) + mb_strlen($body);
            if ($shown >= self::MAX_PROMPT_FIELDS || $budget < 0) {
                $embed->addFieldValues(...Text::field('…', sprintf('and %d more prompt(s) — open Server Settings → Onboarding.', count($prompts) - $shown)));
                break;
            }
            $embed->addFieldValues(...Text::field($heading, $body));
            $shown++;
        }

        if ($shown === 0 && count($prompts) === 0) {
            $embed->addFieldValues(...Text::field('Prompts', '*(none configured — set them up in Server Settings → Onboarding)*'));
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

        return Text::clip(($title ?: 'Untitled prompt') . ' — ' . implode(', ', $flags), 240);
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

        return Text::clip($lines === [] ? '*(no options)*' : implode("\n", $lines), 1000);
    }

    /**
     * `<#id> <#id>` / `<@&id> <@&id>` for a list of ids, showing at most 15 and
     * a `+N` tail for the rest so a field can't overflow.
     *
     * @param list<string|int> $ids
     * @param string           $sigil `#` for channels, `@&` for roles
     */
    public static function mentionList(array $ids, string $sigil): string
    {
        $shown = array_slice($ids, 0, 15);
        $out = array_map(static fn ($id): string => '<' . $sigil . $id . '>', $shown);
        if (count($ids) > count($shown)) {
            $out[] = '+' . (count($ids) - count($shown));
        }

        return implode(' ', $out);
    }
}
