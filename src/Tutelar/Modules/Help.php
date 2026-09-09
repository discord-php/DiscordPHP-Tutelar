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
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;
use Tutelar\Support\Permissions;
use Tutelar\Tutelar;

/**
 * `/help` — a permission-aware guide. Every caller sees the public commands;
 * moderators additionally get the `/mod` catalogue and the `Report to mods`
 * context command; anyone with Manage Server also gets `/onboarding` and
 * `/config`. Sections the caller can't use are left out, so the reply only ever
 * shows things they can actually do. Ephemeral, and usable in DMs (where only
 * the public section applies).
 *
 * @since 2.2.0
 */
final class Help implements Module
{
    private const COLOR = 0xA7C5FD;

    /**
     * The guide, in render order. Each section names the permission tier that
     * unlocks it ({@see tierFor()}) and its lines are `"cmd — what it does"`.
     *
     * @var list<array{tier: string, title: string, lines: list<string>}>
     */
    public const SECTIONS = [
        [
            'tier' => 'everyone',
            'title' => 'Everyone',
            'lines' => [
                '`/help` — this guide.',
                '`/whois [user]` — account age, join date and roles for a member (or yourself).',
                '`/invite` — a link to add Tutelar to another server.',
                '`/ping` — the bot\'s current gateway latency.',
            ],
        ],
        [
            'tier' => 'moderator',
            'title' => 'Moderation — `/mod` (needs Kick / Ban / Timeout / Manage Server)',
            'lines' => [
                '**Cases & history**',
                '`/mod warn <user> <reason>` — record a warning. Auto-escalates: 3 → 1h timeout, 5 → 1d timeout, 7 → kick, 10 → ban.',
                '`/mod warnings <user>` · `/mod modlogs <user>` — a member\'s active warnings / full case history.',
                '`/mod case <case>` — show one numbered case.',
                '`/mod note <user> <text>` — attach a private mod note.',
                '`/mod reason <case> <text>` — rewrite a case\'s reason.',
                '`/mod delwarn <case>` — void one warning or case (drops the escalation count).',
                '**Member actions**',
                '`/mod kick <user> [reason]` · `/mod ban <user|user_id> [reason] [duration] [delete_days]` · `/mod unban <user_id> [reason]`',
                '`/mod timeout <user> <duration> [reason]` · `/mod untimeout <user> [reason]` — duration like `10m`, `2h`, `1d` (max 28d).',
                '**Channels**',
                '`/mod purge <count> [user] [contains] [bots]` — bulk-delete recent messages, optionally filtered.',
                '`/mod slowmode <seconds>` — set this channel\'s slowmode (0 turns it off).',
                '`/mod lock [channel] [reason]` · `/mod unlock [channel] [reason]` — stop / restore @everyone sending.',
                '**Panel & context menus**',
                '`/modpanel <user>` or right-click a member → Apps → **Moderate** — an interactive panel: warn / timeout / kick / ban / unban / history, all in one place.',
                '`Report to mods` — right-click any message → Apps → report it to this server\'s mod channel.',
                'Every action writes a numbered case to the mod-log channel (`/config set` → *Mod-log channel*).',
            ],
        ],
        [
            'tier' => 'manager',
            'title' => 'Onboarding — `/onboarding` (needs Manage Server)',
            'lines' => [
                '`/onboarding view` — the server\'s current onboarding prompts and what each option grants.',
                '`/onboarding enable` · `/onboarding disable` — turn Discord\'s built-in onboarding / Channels & Roles flow on or off.',
                'Editing the prompts themselves is **Server Settings → Onboarding**. Enable/disable needs the bot to hold Manage Server + Manage Roles.',
            ],
        ],
        [
            'tier' => 'manager',
            'title' => 'Configuration — `/config` (needs Manage Server)',
            'lines' => [
                '`/config view` — every setting, its current value, and whether it\'s a per-server override or a default.',
                '`/config set <setting> <channel>` — point a setting at a channel. Settings: **Log channel** (audit feed + case fallback), **Mod-log channel** (numbered cases).',
                '`/config unset <setting>` — clear one setting (falls back to the default, if any).',
                '`/config reset` — clear every runtime override for this server.',
                'Nothing here needs a file on the host — it\'s all set from Discord and persists across restarts.',
            ],
        ],
    ];

    public function name(): string
    {
        return 'help';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(function ($repo) use ($bot): void {
            if ($repo->get('name', 'help') !== null) {
                return;
            }
            CommandBuilder::new()
                ->setType(Command::CHAT_INPUT)
                ->setName('help')
                ->setDescription('What can I do with Tutelar? A guide to the commands you have access to.')
                ->setContext([Interaction::CONTEXT_TYPE_GUILD, Interaction::CONTEXT_TYPE_BOT_DM, Interaction::CONTEXT_TYPE_PRIVATE_CHANNEL])
                ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL)
                ->create($repo)
                ->save('help command');
        });

        $bot->listenCommand('help', fn (Interaction $i) => $this->show($bot, $i));
    }

    private function show(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $member = $interaction->member instanceof Member ? $interaction->member : null;
        $isModerator = $member !== null && Permissions::memberHasAny(Permissions::MODERATOR, $member);
        $isManager = $member !== null && Permissions::memberHasAny(Permissions::MANAGER, $member);

        $embed = (new Embed($bot))
            ->setColor(self::COLOR)
            ->setTitle('Tutelar — what you can do')
            ->setDescription($member === null
                ? 'You\'re in a DM, so this lists the commands that work anywhere. Run `/help` in a server to see its moderation and configuration commands (if you have the permissions).'
                : 'The commands available to you on this server. Options in `[brackets]` are optional.');

        foreach (self::sectionsFor($isModerator, $isManager) as $section) {
            $embed->addFieldValues($section['title'], implode("\n", $section['lines']));
        }

        if ($member !== null && ! $isModerator && ! $isManager) {
            $embed->addFieldValues('Want more?', 'Moderation and configuration commands appear here once you have a role with Kick / Ban / Timeout or Manage Server.');
        }

        return $interaction->respondWithMessage(Tutelar::reply(false)->addEmbed($embed), true);
    }

    /**
     * The {@see SECTIONS} entries a caller with these permission tiers should
     * see, in order. Pure, so the gating is unit-tested without a gateway.
     *
     * @return list<array{tier: string, title: string, lines: list<string>}>
     */
    public static function sectionsFor(bool $isModerator, bool $isManager): array
    {
        $allowed = ['everyone' => true, 'moderator' => $isModerator, 'manager' => $isManager];

        return array_values(array_filter(
            self::SECTIONS,
            static fn (array $s): bool => $allowed[$s['tier']] ?? false,
        ));
    }
}
