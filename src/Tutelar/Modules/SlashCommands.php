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
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Guild\Role;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

/**
 * Registers and handles Tutelar's global commands: `/whois` (plus its
 * right-click **Whois** user context-menu twin), `/invite`, `/ping`. User- and
 * guild-installable, usable in guilds, the bot's DMs and group DMs; every reply
 * is ephemeral, and all answer from the interaction payload / cache so they
 * respond well inside the deadline.
 *
 * Command definitions are created once and then left alone (the
 * `$repo->get('name', …)` guard in {@see define()}): edit a name or option and
 * you must bump it by hand or clear the command, same as the sibling bots.
 *
 * Replaces the legacy `variable_functions.php` slash-command closures.
 *
 * @since 2.0.0
 */
final class SlashCommands implements Module
{
    /** Last gateway round-trip in ms, from the `heartbeat-ack` event. */
    private ?float $lastLatencyMs = null;

    public function name(): string
    {
        return 'slash-commands';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $bot->on('heartbeat-ack', function ($time): void {
            $this->lastLatencyMs = (float) $time;
        });

        $bot->listenCommand('whois', fn (Interaction $i) => $this->whois($bot, $i));
        $bot->listenCommand('Whois', fn (Interaction $i) => $this->whois($bot, $i));   // right-click a user → Apps → Whois
        $bot->listenCommand('invite', fn (Interaction $i) => $this->invite($bot, $i));
        $bot->listenCommand('ping', fn (Interaction $i) => $i->respondWithMessage(
            Tutelar::reply(false)->setContent($this->lastLatencyMs === null
                ? '🏓 pong (no heartbeat sample yet)'
                : sprintf('🏓 pong — gateway round-trip ~%dms', (int) round($this->lastLatencyMs))),
            true,
        ));
    }

    private function define(Tutelar $bot, GlobalCommandRepository $repo): void
    {
        $mk = function (string $name, string $desc, ?Option $option = null) use ($bot, $repo): void {
            if ($repo->get('name', $name) !== null) {
                return;
            }
            $builder = CommandBuilder::new()
                ->setName($name)
                ->setType(Command::CHAT_INPUT)
                ->setDescription($desc)
                ->setContext([Interaction::CONTEXT_TYPE_GUILD, Interaction::CONTEXT_TYPE_BOT_DM, Interaction::CONTEXT_TYPE_PRIVATE_CHANNEL])
                ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL);
            if ($option !== null) {
                $builder->addOption($option);
            }
            $builder->create($repo)->save($name . ' command');
        };

        $mk('whois', 'Show account and membership details for a user.', (new Option($bot))
            ->setName('user')->setDescription('Whose details (defaults to you).')->setType(Option::USER));
        $mk('invite', "Get Tutelar's invite link.");
        $mk('ping', 'Check the bot\'s gateway latency.');

        // The user context-menu twin of `/whois` — "right-click a member → Apps
        // → Whois". A USER command has no options; the target is `target_id`.
        // setType() before setName(): setName() runs the slash-name regex (no
        // spaces / caps) until the type says otherwise.
        if ($repo->get('name', 'Whois') === null) {
            CommandBuilder::new()
                ->setType(Command::USER)
                ->setName('Whois')
                ->setContext([Interaction::CONTEXT_TYPE_GUILD, Interaction::CONTEXT_TYPE_BOT_DM, Interaction::CONTEXT_TYPE_PRIVATE_CHANNEL])
                ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL)
                ->create($repo)
                ->save('Whois user command');
        }
    }

    private function whois(Tutelar $bot, Interaction $interaction): \React\Promise\PromiseInterface
    {
        $data = $interaction->data;

        // Target, in priority order:
        //   • `target_id`            — the user context-menu command ("Whois");
        //   • the `user` option      — `/whois user:@someone` (by NAME, not
        //                              `->first()`, which was returning nothing
        //                              and silently falling back to the caller);
        //   • the caller             — bare `/whois`.
        $targetId = (string) (
            ($data->target_id ?? null)
            ?? ($data->options?->get('name', 'user')?->value ?? null)
            ?? $interaction->user->id
        );
        $isSelf = $targetId === (string) ($interaction->user->id ?? '');

        // `resolved` carries the target's user + partial member even when the
        // bot doesn't have them cached (a user-installed invocation).
        $resolved = $data->resolved ?? null;
        $user = $resolved?->users?->get('id', $targetId)
            ?? $bot->users->get('id', $targetId)
            ?? ($isSelf ? $interaction->user : null);
        $member = $resolved?->members?->first()
            ?? $interaction->guild?->members?->get('id', $targetId)
            ?? ($isSelf && $interaction->member instanceof Member ? $interaction->member : null);

        $embed = (new Embed($bot))
            ->setColor(0xA7C5FD)
            ->setTitle('whois')
            ->setAuthor($user?->displayname ?? "User {$targetId}", $user?->avatar)
            ->addFieldValues(...Text::field('User', "<@{$targetId}>", true))
            ->addFieldValues(...Text::field('ID', $targetId, true));

        // Only when the user is actually cached — otherwise createdTimestamp()
        // would render "<t:0:R>" (1970).
        if ($user !== null) {
            $embed->addFieldValues(...Text::field('Account created', '<t:' . (int) $user->createdTimestamp() . ':R>', true));
        }

        if ($member instanceof Member) {
            $embed->addFieldValues(...Text::field('Joined', $member->joined_at ? '<t:' . $member->joined_at->timestamp . ':R>' : 'unknown', true));
            $roles = [];
            foreach ($member->roles as $role) {
                if ($role instanceof Role) {
                    $roles[] = (string) $role;
                }
            }
            if ($roles !== []) {
                $embed->addFieldValues(...Text::field('Roles', implode(' ', $roles)));
            }
        }

        return $interaction->respondWithMessage(Tutelar::reply(false)->addEmbed($embed), true);
    }

    private function invite(Tutelar $bot, Interaction $interaction): \React\Promise\PromiseInterface
    {
        $clientId = (string) ($bot->application->id ?? $bot->id);
        // permissions=1101927640118 — the exact set the modules use, no
        // Administrator:
        //   kick_members / ban_members / moderate_members  → /mod kick|ban|timeout
        //   manage_channels                                → /mod slowmode
        //   manage_roles                                   → /mod lock|unlock (channel overwrites)
        //   manage_messages + read_message_history         → /mod purge
        //   manage_guild                                   → /onboarding enable|disable
        //   view_channel / send_messages / embed_links     → event-logger + replies
        //   use_application_commands                       → slash + context-menu commands
        $url = "https://discord.com/oauth2/authorize?client_id={$clientId}&scope=bot+applications.commands&permissions=1101927640118";

        return $interaction->respondWithMessage(Tutelar::reply(false)->setContent("Add Tutelar to a server: {$url}"), true);
    }
}
