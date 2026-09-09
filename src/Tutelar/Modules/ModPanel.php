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
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Label;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use Discord\WebSockets\Event;
use React\Promise\PromiseInterface;
use Tutelar\Moderation\Duration;
use Tutelar\Support\Permissions;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

use function React\Promise\resolve;

/**
 * A Components V2 moderation panel: one ephemeral message with the target's
 * details and a row of action buttons (warn / timeout / kick / ban …). Opened
 * from the `Moderate` user context-menu (right-click a member → Apps) or
 * `/modpanel [user]`. Every button re-checks the clicker's permission and the
 * role hierarchy, takes a reason (and duration) in a modal, and then runs the
 * SAME {@see Moderation::actOnMember()} path as `/mod` — so cases, escalation
 * counts and the mod-log stay consistent however a mod acts.
 *
 * The panel is ephemeral. Buttons carry stable `mp:<action>:<target>`
 * custom_ids and one `INTERACTION_CREATE` dispatcher routes every click, so a
 * self-refreshing panel never stacks listeners.
 *
 * The same module also owns the **`Report to mods`** message context-menu: any
 * member right-clicks a message → Apps → Report to mods, and Tutelar posts a
 * V2 report card to the log channel with Timeout / Kick / Ban / Dismiss buttons
 * (`mpr:<action>:<author>:<channel>:<message>`) the mod team acts on inline.
 * Those buttons are on a persistent channel message, so the dispatcher — keyed
 * only on the custom_id — keeps working across restarts.
 *
 * @since 2.2.0
 */
final class ModPanel implements Module
{
    private const ACCENT = 0xA7C5FD;

    private const REPORT_ACCENT = 0xE8B923;

    public function __construct(private readonly Moderation $moderation)
    {
    }

    public function name(): string
    {
        return 'mod-panel';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(function ($repo) use ($bot): void {
            if ($repo->get('name', 'Moderate') === null) {
                CommandBuilder::new()
                    ->setType(Command::USER)
                    ->setName('Moderate')
                    ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                    ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                    ->create($repo)
                    ->save('Moderate context command');
            }

            if ($repo->get('name', 'modpanel') === null) {
                CommandBuilder::new()
                    ->setType(Command::CHAT_INPUT)
                    ->setName('modpanel')
                    ->setDescription('Open the moderation panel for a member.')
                    ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                    ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                    ->addOption((new Option($bot))
                        ->setType(Option::USER)
                        ->setName('user')
                        ->setDescription('The member to act on.')
                        ->setRequired(true))
                    ->create($repo)
                    ->save('modpanel command');
            }

            if ($repo->get('name', 'Report to mods') === null) {
                CommandBuilder::new()
                    // setType() before setName(): setName() runs the CHAT_INPUT
                    // name regex (no spaces / capitals) until the type says
                    // otherwise, and this is a MESSAGE context-menu entry.
                    ->setType(Command::MESSAGE)
                    ->setName('Report to mods')
                    ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                    ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                    ->create($repo)
                    ->save('Report to mods command');
            }
        });

        $bot->listenCommand('Moderate', fn (Interaction $i) => $this->open($bot, $i, (string) ($i->data->target_id ?? '')));
        $bot->listenCommand('modpanel', fn (Interaction $i) => $this->open($bot, $i, (string) ($i->data->options?->first()?->value ?? '')));
        $bot->listenCommand('Report to mods', fn (Interaction $i) => $this->report($bot, $i));

        // One dispatcher for every panel button. Both flows use stable
        // custom_ids (`mp:` for the ephemeral member panel, `mpr:` for the
        // persistent report card), so nothing stacks listeners and the report
        // buttons keep working across restarts.
        $bot->on(Event::INTERACTION_CREATE, function (Interaction $i) use ($bot): void {
            if ($i->type !== Interaction::TYPE_MESSAGE_COMPONENT || ! $i->guild instanceof Guild) {
                return;
            }
            $parts = explode(':', (string) ($i->data->custom_id ?? ''));
            if (($parts[0] ?? '') === 'mp' && ($parts[1] ?? '') !== '' && ($parts[2] ?? '') !== '') {
                $this->onButton($bot, $i, $i->guild, $parts[2], $parts[1]);
            } elseif (($parts[0] ?? '') === 'mpr' && count($parts) === 5) {
                [, $action, $authorId, $channelId, $messageId] = $parts;
                $this->onReportButton($bot, $i, $i->guild, $action, $authorId, $channelId, $messageId);
            }
        });
    }

    /** Entry point for both commands: validate, then send the panel ephemerally. */
    private function open(Tutelar $bot, Interaction $interaction, string $targetId): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }
        if (! Permissions::memberHasAny(Permissions::MODERATOR, $interaction->member)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission (kick / ban / timeout / manage server) to use this.'), true);
        }
        if ($targetId === '') {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('No member selected.'), true);
        }

        return $this->resolveMember($guild, $targetId)->then(
            fn (?Member $m) => $interaction->respondWithMessage($this->render($bot, $guild, $targetId, $m, null), true),
        );
    }

    // --- panel rendering ------------------------------------------------

    /**
     * Build the panel message for `$targetId`. `$status` is an optional line
     * shown at the top after an action ("✅ Timed out for 1h · case #42").
     */
    private function render(Tutelar $bot, Guild $guild, string $targetId, ?Member $member, ?string $status): MessageBuilder
    {
        $cases = $this->moderation->cases();
        $warnings = $cases->warningCount($guild->id, $targetId);
        $user = $member?->user ?? $bot->users->get('id', $targetId);

        $lines = ["## Moderating <@{$targetId}>", "ID `{$targetId}`"];
        if ($user !== null) {
            $lines[] = 'Account created <t:' . (int) $user->createdTimestamp() . ':R>';
        }
        if ($member instanceof Member) {
            $lines[] = 'Joined ' . ($member->joined_at ? '<t:' . $member->joined_at->timestamp . ':R>' : 'unknown');
            $until = $member->communication_disabled_until;
            if ($until !== null && $until->isFuture()) {
                $lines[] = '⏳ **Timed out** until <t:' . $until->timestamp . ':R>';
            }
        } else {
            $lines[] = '_Not currently in this server._';
        }
        $lines[] = "**{$warnings}** active warning(s)";
        if (($hint = self::escalationHint($warnings)) !== null) {
            $lines[] = "_{$hint}_";
        }

        $header = TextDisplay::new(($status !== null ? "{$status}\n\n" : '') . implode("\n", $lines));

        $container = Container::new()
            ->setAccentColor(self::ACCENT)
            ->addComponent($header)
            ->addComponent(Separator::new());

        $present = $member instanceof Member;
        $timedOut = $present
            && $member->communication_disabled_until !== null
            && $member->communication_disabled_until->isFuture();

        $btn = static fn (string $action, string $label, int $style, bool $disabled = false): Button => Button::new($style, "mp:{$action}:{$targetId}")
            ->setLabel($label)
            ->setDisabled($disabled);

        $msg = MessageBuilder::new()
            ->setIsComponentsV2Flag(true)
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent($container);
        $msg->addComponent(ActionRow::new()
            ->addComponent($btn('warn', 'Warn', Button::STYLE_SECONDARY, ! $present))
            ->addComponent($btn('timeout', 'Timeout', Button::STYLE_SECONDARY, ! $present))
            ->addComponent($btn('untimeout', 'Remove timeout', Button::STYLE_SECONDARY, ! $timedOut)));
        $msg->addComponent(ActionRow::new()
            ->addComponent($btn('kick', 'Kick', Button::STYLE_DANGER, ! $present))
            ->addComponent($btn('ban', 'Ban', Button::STYLE_DANGER))
            ->addComponent($btn('unban', 'Unban', Button::STYLE_SECONDARY)));
        $msg->addComponent(ActionRow::new()
            ->addComponent($btn('modlogs', 'History', Button::STYLE_SECONDARY))
            ->addComponent($btn('refresh', 'Refresh', Button::STYLE_SECONDARY)));

        return $msg;
    }

    // --- button handling ---------------------------------------------------

    private function onButton(Tutelar $bot, Interaction $ci, Guild $guild, string $targetId, string $action): PromiseInterface
    {
        if (! Permissions::memberHasAny(Permissions::MODERATOR, $ci->member)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission to use this panel.'), true);
        }

        // `refresh` / `modlogs` never mutate — no guard, no modal.
        if ($action === 'refresh') {
            return $this->resolveMember($guild, $targetId)->then(
                fn (?Member $m) => $ci->updateMessage($this->render($bot, $guild, $targetId, $m, '🔄 Refreshed.')),
            );
        }
        if ($action === 'modlogs') {
            return $ci->respondWithMessage(
                Tutelar::reply(false)->addEmbed($this->moderation->caseListEmbed($bot, $guild, $targetId, null)),
                true,
            );
        }

        $refusal = $this->moderation->guardMember($guild, $ci->member instanceof Member ? $ci->member : null, $targetId, allowAbsent: $action === 'unban');
        if ($refusal !== null) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent($refusal), true);
        }

        // Reversible / no-reason actions run straight away.
        if ($action === 'untimeout' || $action === 'unban') {
            return $this->run($bot, $ci, $guild, $targetId, $action, '', null);
        }

        // The rest collect a reason (+ duration for a timeout) in a modal.
        $fields = [Label::new(
            'Reason',
            TextInput::new(null, TextInput::STYLE_PARAGRAPH, 'reason')->setRequired($action === 'warn')->setMaxLength(400),
            'Shown in the mod-log and the audit log.',
        )];
        if ($action === 'timeout') {
            $fields[] = Label::new(
                'Duration',
                TextInput::new(null, TextInput::STYLE_SHORT, 'duration')->setRequired(true)->setPlaceholder('e.g. 10m, 2h, 1d (max 28d)')->setMaxLength(16),
            );
        }

        $verb = ucfirst($action);

        return $ci->showModal(
            "{$verb} member",
            "mp-modal:{$action}:{$targetId}",
            $fields,
            function (Interaction $modalI, $components) use ($bot, $guild, $targetId, $action): PromiseInterface {
                $values = [];
                foreach ($components as $component) {
                    $values[(string) ($component->custom_id ?? '')] = (string) ($component->value ?? '');
                }
                $reason = trim($values['reason'] ?? '');
                $duration = trim($values['duration'] ?? '');

                return $this->run($bot, $modalI, $guild, $targetId, $action, $reason, $duration !== '' ? $duration : null);
            },
        );
    }

    /**
     * Apply one action via {@see Moderation::actOnMember()} and refresh the
     * panel in place with a status line. `$interaction` is the button or modal
     * interaction the panel message is attached to.
     */
    private function run(Tutelar $bot, Interaction $interaction, Guild $guild, string $targetId, string $action, string $reason, ?string $duration): PromiseInterface
    {
        $seconds = null;
        if ($action === 'timeout') {
            $seconds = Duration::toSeconds((string) $duration);
            if ($seconds === null || $seconds < 1) {
                return $interaction->respondWithMessage(Tutelar::reply(false)->setContent("Couldn't read the duration \"{$duration}\". Try `10m`, `2h`, `1d`."), true);
            }
        }

        $modId = (string) ($interaction->user->id ?? '');

        return $this->moderation->actOnMember($bot, $guild, $action, $targetId, $modId, $reason, $seconds)->then(
            fn (array $case) => $this->resolveMember($guild, $targetId)->then(
                fn (?Member $m) => $interaction->updateMessage($this->render(
                    $bot,
                    $guild,
                    $targetId,
                    $m,
                    $this->statusLine($action, $case, $duration),
                )),
            ),
            fn (\Throwable $e) => $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent("Couldn't {$action} <@{$targetId}>: " . Text::clip($e->getMessage(), 300)),
                true,
            ),
        );
    }

    /**
     * The one-line confirmation shown at the top of the refreshed panel after
     * an action. Pure, so it is unit-tested.
     *
     * @param array<string,mixed> $case the stored {@see \Tutelar\Moderation\CaseBook} case (`id` present)
     */
    public static function statusLine(string $action, array $case, ?string $duration): string
    {
        $caseTail = isset($case['id']) ? " · case #{$case['id']}" : '';

        return match ($action) {
            'warn' => "⚠️ Warned{$caseTail}",
            'timeout' => "⏳ Timed out for {$duration}{$caseTail}",
            'untimeout' => "🔈 Timeout cleared{$caseTail}",
            'kick' => "👢 Kicked{$caseTail}",
            'ban' => "🔨 Banned{$caseTail}",
            'unban' => "🕊️ Unbanned{$caseTail}",
            default => "✅ Done{$caseTail}",
        };
    }

    /**
     * "Next warning auto-kick" style hint for the panel header, or null when the
     * current warning count isn't one below an {@see Moderation::ESCALATION}
     * rung. Pure.
     */
    public static function escalationHint(int $warnings): ?string
    {
        $next = Moderation::ESCALATION[$warnings + 1] ?? null;
        if ($next === null) {
            return null;
        }

        return "Next warning auto-{$next['action']}" . ($next['duration'] ? " ({$next['duration']})" : '') . '.';
    }

    /** @return PromiseInterface<?Member> */
    private function resolveMember(Guild $guild, string $userId): PromiseInterface
    {
        $cached = $guild->members->get('id', $userId);
        if ($cached instanceof Member) {
            return resolve($cached);
        }

        return $guild->members->fetch($userId)->then(null, static fn () => null);
    }

    // --- report to mods --------------------------------------------------

    /**
     * `Report to mods` message context-menu: any member can run it. Posts a V2
     * report card to the log channel and quietly acknowledges the reporter.
     */
    private function report(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }

        $messageId = (string) ($interaction->data->target_id ?? '');
        $channelId = (string) ($interaction->channel_id ?? '');
        $message = $interaction->data->resolved?->messages?->get('id', $messageId);
        $authorId = $message instanceof Message ? (string) ($message->author?->id ?? '') : '';
        $content = $message instanceof Message ? (string) $message->content : '';

        $logId = $bot->guild($guild->id)->channel('modlog') ?? $bot->guild($guild->id)->channel('log');
        if ($logId === null) {
            return $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent('This server has no log channel set — a mod needs to run `/config set` first, so reports have nowhere to go.'),
                true,
            );
        }

        $channel = $bot->getChannel($logId);
        if (! $channel instanceof Channel) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('The configured log channel is not reachable.'), true);
        }

        $missing = Configuration::missingPostPerms($channel->getBotPermissions());
        if ($missing !== []) {
            return $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent("⚠️ I can't deliver reports right now — an admin needs to grant me **" . implode('**, **', $missing) . "** in <#{$logId}> (or set a different channel with `/config set`)."),
                true,
            );
        }

        $panel = $this->reportPanel([
            'guildId' => (string) $guild->id,
            'authorId' => $authorId,
            'reporterId' => (string) ($interaction->user->id ?? ''),
            'channelId' => $channelId,
            'messageId' => $messageId,
            'content' => $content,
            'status' => null,
        ]);

        // Defer (15-min window), post the card, then report the real outcome —
        // never tell the reporter "sent" when the write actually failed.
        return $interaction->acknowledgeWithResponse(true)->then(
            fn () => $channel->sendMessage($panel)->then(
                fn () => $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent('✅ Sent to the mods. Thanks for the report.')),
                function (\Throwable $e) use ($bot, $interaction, $logId): PromiseInterface {
                    $bot->logger->warning('[mod-panel] report card post failed: ' . $e->getMessage());
                    $hint = str_contains($e->getMessage(), '50001') || str_contains($e->getMessage(), 'Missing Access')
                        ? "I don't have access to <#{$logId}> — an admin needs to grant me **View Channel** + **Send Messages** + **Embed Links** there (or set another channel with `/config set`)."
                        : Text::clip($e->getMessage(), 200);

                    return $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent("⚠️ Couldn't deliver the report — {$hint}"));
                },
            ),
        );
    }

    /**
     * Build the report card. With `status` set it renders the resolved receipt
     * (no buttons); otherwise it carries the Timeout / Kick / Ban / Dismiss row.
     *
     * @param array{guildId:string,authorId:string,reporterId:string,channelId:string,messageId:string,content:string,status:?string} $d
     */
    private function reportPanel(array $d): MessageBuilder
    {
        $jump = "https://discord.com/channels/{$d['guildId']}/{$d['channelId']}/{$d['messageId']}";

        $lines = ['## ⚠️ Message reported'];
        $meta = $d['authorId'] !== '' ? "Author <@{$d['authorId']}>" : 'Author unknown';
        if ($d['reporterId'] !== '') {
            $meta .= " · reported by <@{$d['reporterId']}>";
        }
        $meta .= " · in <#{$d['channelId']}>";
        $lines[] = $meta;
        if (trim($d['content']) !== '') {
            $quoted = implode("\n", array_map(static fn (string $l): string => "> {$l}", explode("\n", Text::clip($d['content'], 1200))));
            $lines[] = $quoted;
        }
        $lines[] = "[Jump to message]({$jump})";
        if ($d['status'] !== null) {
            $lines[] = "\n**{$d['status']}**";
        }

        $container = Container::new()
            ->setAccentColor(self::REPORT_ACCENT)
            ->addComponent(TextDisplay::new(implode("\n", $lines)));

        $msg = MessageBuilder::new()
            ->setIsComponentsV2Flag(true)
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent($container);

        if ($d['status'] === null && $d['authorId'] !== '') {
            $id = fn (string $a): string => "mpr:{$a}:{$d['authorId']}:{$d['channelId']}:{$d['messageId']}";
            $msg->addComponent(ActionRow::new()
                ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('timeout'))->setLabel('Timeout'))
                ->addComponent(Button::new(Button::STYLE_DANGER, $id('kick'))->setLabel('Kick'))
                ->addComponent(Button::new(Button::STYLE_DANGER, $id('ban'))->setLabel('Ban'))
                ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('dismiss'))->setLabel('Dismiss')));
        }

        return $msg;
    }

    /** A click on a report card's action button. */
    private function onReportButton(Tutelar $bot, Interaction $ci, Guild $guild, string $action, string $authorId, string $channelId, string $messageId): PromiseInterface
    {
        if (! Permissions::memberHasAny(Permissions::MODERATOR, $ci->member)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission to act on a report.'), true);
        }

        $modMention = '<@' . ($ci->user->id ?? '?') . '>';

        if ($action === 'dismiss') {
            return $ci->updateMessage($this->reportPanel($this->resolvedCard($guild, $authorId, $channelId, $messageId, "🚫 Dismissed by {$modMention}")));
        }

        $refusal = $this->moderation->guardMember($guild, $ci->member instanceof Member ? $ci->member : null, $authorId);
        if ($refusal !== null) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent($refusal), true);
        }

        $fields = [Label::new(
            'Reason',
            TextInput::new(null, TextInput::STYLE_PARAGRAPH, 'reason')->setRequired(false)->setMaxLength(400),
            'Shown in the mod-log and the audit log.',
        )];
        if ($action === 'timeout') {
            $fields[] = Label::new(
                'Duration',
                TextInput::new(null, TextInput::STYLE_SHORT, 'duration')->setRequired(true)->setPlaceholder('e.g. 10m, 2h, 1d (max 28d)')->setMaxLength(16),
            );
        }

        return $ci->showModal(
            ucfirst($action) . ' reported member',
            "mpr-modal:{$action}:{$authorId}",
            $fields,
            function (Interaction $modalI, $components) use ($bot, $guild, $action, $authorId, $channelId, $messageId, $modMention): PromiseInterface {
                $values = [];
                foreach ($components as $component) {
                    $values[(string) ($component->custom_id ?? '')] = (string) ($component->value ?? '');
                }
                $reason = trim($values['reason'] ?? '');
                $duration = trim($values['duration'] ?? '');

                $seconds = null;
                if ($action === 'timeout') {
                    $seconds = Duration::toSeconds($duration);
                    if ($seconds === null || $seconds < 1) {
                        return $modalI->respondWithMessage(Tutelar::reply(false)->setContent("Couldn't read the duration \"{$duration}\"."), true);
                    }
                }

                return $this->moderation->actOnMember($bot, $guild, $action, $authorId, (string) ($modalI->user->id ?? ''), $reason, $seconds)->then(
                    fn (array $case) => $modalI->updateMessage($this->reportPanel($this->resolvedCard(
                        $guild,
                        $authorId,
                        $channelId,
                        $messageId,
                        self::statusLine($action, $case, $duration !== '' ? $duration : null) . " by {$modMention}",
                    ))),
                    fn (\Throwable $e) => $modalI->respondWithMessage(
                        Tutelar::reply(false)->setContent("Couldn't {$action} <@{$authorId}>: " . Text::clip($e->getMessage(), 300)),
                        true,
                    ),
                );
            },
        );
    }

    /**
     * The `$d` payload for {@see reportPanel()} in its resolved (buttonless)
     * form — the reporter and message content aren't in a button's context, so
     * the receipt keeps just the author, the jump link and the outcome.
     *
     * @return array{guildId:string,authorId:string,reporterId:string,channelId:string,messageId:string,content:string,status:?string}
     */
    private function resolvedCard(Guild $guild, string $authorId, string $channelId, string $messageId, string $status): array
    {
        return [
            'guildId' => (string) $guild->id,
            'authorId' => $authorId,
            'reporterId' => '',
            'channelId' => $channelId,
            'messageId' => $messageId,
            'content' => '',
            'status' => $status,
        ];
    }
}
