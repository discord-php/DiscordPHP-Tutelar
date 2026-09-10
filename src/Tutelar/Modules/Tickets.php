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

use Discord\Builders\ChannelBuilder;
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
use Discord\Parts\Guild\Role;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use Discord\WebSockets\Event;
use React\Promise\PromiseInterface;
use Tutelar\Support\Permissions;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Ticketing: a report (or a mod running `/ticket`) opens a **fresh private
 * channel** at the guild root — no category `parent_id` — visible only to the
 * bot and to every role that already holds a moderator permission
 * ({@see Permissions::MODERATOR}). `@everyone` is denied `view_channel` in the
 * channel's creation overwrites, so it is private from the first tick.
 *
 * The opening message is a Components V2 card with three buttons —
 * **Close**, **Warn user**, **Add user** — carrying stable
 * `tkt:<action>:<channelId>` custom_ids routed by one `INTERACTION_CREATE`
 * dispatcher (so nothing stacks listeners and the buttons survive a restart).
 * **Add user** just writes a permission overwrite for the subject — there is
 * no invite to accept; they can see and talk in the channel immediately.
 *
 * Every step is appended to the ticket's log in {@see \Tutelar\Store} and
 * echoed as a short V2 note in-channel. **Closing deletes the channel** and
 * posts the full transcript to the mod-log (or log) channel as a `.txt`
 * attachment — the only durable record once the channel is gone.
 *
 * @since 2.3.0
 */
final class Tickets implements Module
{
    private const ACCENT = 0x5865F2;

    private const NOTE_ACCENT = 0x4E5058;

    /** Channel-permission bit VALUES (1 << position, from Discord's permission flags). */
    private const P_VIEW = 1 << 10;

    private const P_SEND = 1 << 11;

    private const P_EMBED = 1 << 14;

    private const P_ATTACH = 1 << 15;

    private const P_HISTORY = 1 << 16;

    private const P_MANAGE_CHANNEL = 1 << 4;

    /** What a staff role / the bot may do in a ticket channel. */
    private const STAFF_ALLOW = self::P_VIEW | self::P_SEND | self::P_EMBED | self::P_ATTACH | self::P_HISTORY;

    /** What an added subject may do (read + talk, no embeds/files). */
    private const GUEST_ALLOW = self::P_VIEW | self::P_SEND | self::P_HISTORY;

    public function __construct(private readonly Moderation $moderation)
    {
    }

    public function name(): string
    {
        return 'tickets';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(function ($repo) use ($bot): void {
            if ($repo->get('name', 'ticket') !== null) {
                return;
            }
            CommandBuilder::new()
                ->setType(Command::CHAT_INPUT)
                ->setName('ticket')
                ->setDescription('Open a private staff ticket channel.')
                ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                ->addOption((new Option($bot))
                    ->setType(Option::USER)
                    ->setName('user')
                    ->setDescription('The member this ticket is about (optional).')
                    ->setRequired(false))
                ->addOption((new Option($bot))
                    ->setType(Option::STRING)
                    ->setName('reason')
                    ->setDescription('What the ticket is about (optional).')
                    ->setRequired(false))
                ->create($repo)
                ->save('ticket command');
        });

        $bot->listenCommand('ticket', fn (Interaction $i) => $this->slashOpen($bot, $i));

        // One dispatcher for every ticket button. `tkt:<action>:<channelId>`.
        $bot->on(Event::INTERACTION_CREATE, function (Interaction $i) use ($bot): void {
            if ($i->type !== Interaction::TYPE_MESSAGE_COMPONENT || ! $i->guild instanceof Guild) {
                return;
            }
            $parts = explode(':', (string) ($i->data->custom_id ?? ''));
            if (($parts[0] ?? '') !== 'tkt' || ($parts[1] ?? '') === '' || ($parts[2] ?? '') === '') {
                return;
            }
            $this->onButton($bot, $i, $i->guild, $parts[1], $parts[2]);
        });
    }

    // --- opening ---------------------------------------------------------

    private function slashOpen(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }
        if (! Permissions::forInteraction(Permissions::MODERATOR, $interaction)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission to open a ticket.'), true);
        }

        $opts = $interaction->data->options;
        $subjectId = (string) ($opts?->get('name', 'user')?->value ?? '');
        $reason = trim((string) ($opts?->get('name', 'reason')?->value ?? ''));

        return $interaction->acknowledgeWithResponse(true)->then(fn () => $this->open($bot, $guild, [
            'kind' => 'manual',
            'subjectId' => $subjectId,
            'openerId' => (string) ($interaction->user->id ?? ''),
            'reason' => $reason !== '' ? $reason : 'Opened with /ticket.',
            'sourceChannelId' => '',
            'sourceMessageId' => '',
            'content' => '',
        ])->then(
            fn (Channel $c) => $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent("✅ Ticket opened: <#{$c->id}>")),
            fn (\Throwable $e) => $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent('⚠️ Could not open a ticket — ' . Text::clip($e->getMessage(), 300))),
        ));
    }

    /**
     * Open a ticket from a `Report to mods` action. Returns the reporter-facing
     * outcome string; the caller relays it.
     *
     * @param array{authorId:string,reporterId:string,channelId:string,messageId:string,content:string} $r
     *
     * @return PromiseInterface<string>
     */
    public function openFromReport(Tutelar $bot, Guild $guild, array $r): PromiseInterface
    {
        return $this->open($bot, $guild, [
            'kind' => 'report',
            'subjectId' => $r['authorId'],
            'openerId' => $r['reporterId'],
            'reason' => 'Message reported to the mods.',
            'sourceChannelId' => $r['channelId'],
            'sourceMessageId' => $r['messageId'],
            'content' => $r['content'],
        ])->then(
            static fn () => '✅ Sent to the mods — a private ticket has been opened for the team. Thanks for the report.',
            static fn (\Throwable $e) => '⚠️ Could not open a ticket for that report: ' . Text::clip($e->getMessage(), 200),
        );
    }

    /**
     * Create the private channel, post the opening card, and register the
     * ticket in the store.
     *
     * @param array{kind:string,subjectId:string,openerId:string,reason:string,sourceChannelId:string,sourceMessageId:string,content:string} $ctx
     *
     * @return PromiseInterface<Channel>
     */
    public function open(Tutelar $bot, Guild $guild, array $ctx): PromiseInterface
    {
        $botId = (string) ($bot->user?->id ?? $bot->application?->id ?? '');
        if ($botId === '') {
            return reject(new \RuntimeException('bot identity not ready'));
        }

        $seqKey = "seq:{$guild->id}";
        $seq = ((int) $bot->getStore()->moduleGet('tickets', $seqKey, 0)) + 1;
        $bot->getStore()->moduleSet('tickets', $seqKey, $seq);

        $staffRoleIds = self::staffRoleIds(self::rolePermMap($guild->roles), (string) $guild->id);
        $overwrites = self::overwrites((string) $guild->id, $botId, $staffRoleIds, null);

        $builder = ChannelBuilder::new("ticket-{$seq}")
            ->setType(Channel::TYPE_GUILD_TEXT)
            ->setTopic(Text::clip('Tutelar ticket #' . $seq . ($ctx['subjectId'] !== '' ? " · subject <@{$ctx['subjectId']}>" : '') . ' · ' . $ctx['reason'], 1000))
            ->setPermissionOverwrites($overwrites);
        // Deliberately NO setParentId(): the ticket sits at the guild root.

        return $guild->channels->createChannel($guild->id, $builder, 'Tutelar ticket opened')->then(function (Channel $channel) use ($bot, $guild, $ctx, $seq): PromiseInterface {
            $now = time();
            $ticket = [
                'guildId' => (string) $guild->id,
                'channelId' => (string) $channel->id,
                'seq' => $seq,
                'kind' => $ctx['kind'],
                'subjectId' => $ctx['subjectId'],
                'openerId' => $ctx['openerId'],
                'reason' => $ctx['reason'],
                'source' => ['channelId' => $ctx['sourceChannelId'], 'messageId' => $ctx['sourceMessageId']],
                'opened' => $now,
                'log' => [
                    self::entry($now, $ctx['openerId'] !== '' ? "<@{$ctx['openerId']}>" : 'system', 'opened the ticket — ' . $ctx['reason']),
                ],
            ];
            $bot->getStore()->moduleSet('tickets', (string) $channel->id, $ticket);

            return $channel->sendMessage($this->openingCard($ticket))->then(static fn () => $channel);
        });
    }

    // --- buttons -------------------------------------------------------

    private function onButton(Tutelar $bot, Interaction $ci, Guild $guild, string $action, string $channelId): PromiseInterface
    {
        if (! Permissions::forInteraction(Permissions::MODERATOR, $ci)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission to act on a ticket.'), true);
        }

        $ticket = $bot->getStore()->moduleGet('tickets', $channelId);
        if (! is_array($ticket)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('This ticket is no longer tracked.'), true);
        }

        return match ($action) {
            'add' => $this->addSubject($bot, $ci, $guild, $ticket),
            'warn' => $this->warn($bot, $ci, $guild, $ticket),
            'close' => $this->promptClose($bot, $ci, $guild, $ticket),
            default => $ci->respondWithMessage(Tutelar::reply(false)->setContent('Unknown ticket action.'), true),
        };
    }

    /**
     * Add the subject to the ticket by writing a channel permission overwrite
     * (`view` + `send` + history). There is nothing for them to accept — the
     * overwrite takes effect immediately.
     */
    private function addSubject(Tutelar $bot, Interaction $ci, Guild $guild, array $ticket): PromiseInterface
    {
        $subjectId = (string) ($ticket['subjectId'] ?? '');
        if ($subjectId === '') {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('This ticket has no subject member to add.'), true);
        }
        $channel = $bot->getChannel((string) $ticket['channelId']);
        if (! $channel instanceof Channel) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('The ticket channel is gone.'), true);
        }

        // Defer: a member fetch + a permission-overwrite write can outrun the
        // 3s interaction window.
        return $ci->acknowledgeWithResponse(true)
            ->then(fn () => $this->resolveMember($guild, $subjectId))
            ->then(function (?Member $m) use ($bot, $ci, $channel, $ticket, $subjectId) {
                if (! $m instanceof Member) {
                    return $ci->updateOriginalResponse(Tutelar::reply(false)->setContent("<@{$subjectId}> is not in this server."));
                }

                return $channel->setPermissions($m, ['view_channel', 'send_messages', 'read_message_history'], [], 'Ticket: add subject')->then(function () use ($bot, $ci, $channel, $ticket, $subjectId) {
                    $actor = '<@' . ($ci->user->id ?? '?') . '>';
                    $this->appendLog($bot, $ticket, $actor, "added <@{$subjectId}> to the channel");
                    $channel->sendMessage($this->note("➕ {$actor} added <@{$subjectId}> to this ticket — they can see and reply here now.", true));

                    return $ci->updateOriginalResponse(Tutelar::reply()->setContent("Added <@{$subjectId}> to the channel."));
                }, fn (\Throwable $e) => $ci->updateOriginalResponse(Tutelar::reply(false)->setContent('Could not add them: ' . Text::clip($e->getMessage(), 200))));
            });
    }

    /** Warn the subject through the same {@see Moderation} path as `/mod warn`. */
    private function warn(Tutelar $bot, Interaction $ci, Guild $guild, array $ticket): PromiseInterface
    {
        $subjectId = (string) ($ticket['subjectId'] ?? '');
        if ($subjectId === '') {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('This ticket has no subject member to warn.'), true);
        }
        $refusal = $this->moderation->guardMember($guild, $ci->member instanceof Member ? $ci->member : null, $subjectId);
        if ($refusal !== null) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent($refusal), true);
        }

        return $ci->showModal(
            'Warn ticket subject',
            "tkt-modal:warn:{$ticket['channelId']}",
            [Label::new(
                'Reason',
                TextInput::new(null, TextInput::STYLE_PARAGRAPH, 'reason')->setRequired(true)->setMaxLength(400),
                'Shown to the member, in the mod-log, and in this ticket.',
            )],
            function (Interaction $modalI, $components) use ($bot, $guild, $ticket, $subjectId): PromiseInterface {
                $reason = '';
                foreach ($components as $c) {
                    if ((string) ($c->custom_id ?? '') === 'reason') {
                        $reason = trim((string) ($c->value ?? ''));
                    }
                }
                if ($reason === '') {
                    return $modalI->respondWithMessage(Tutelar::reply(false)->setContent('A reason is required to warn.'), true);
                }

                return $this->moderation->actOnMember($bot, $guild, 'warn', $subjectId, (string) ($modalI->user->id ?? ''), $reason, null)->then(
                    function (array $case) use ($bot, $modalI, $ticket, $subjectId, $reason) {
                        $actor = '<@' . ($modalI->user->id ?? '?') . '>';
                        $tail = isset($case['id']) ? " · case #{$case['id']}" : '';
                        $this->appendLog($bot, $ticket, $actor, "warned <@{$subjectId}>{$tail} — {$reason}");
                        $channel = $bot->getChannel((string) $ticket['channelId']);
                        if ($channel instanceof Channel) {
                            $channel->sendMessage($this->note("⚠️ {$actor} warned <@{$subjectId}>{$tail}.\n> " . Text::clip($reason, 900), true));
                        }

                        return $modalI->respondWithMessage(Tutelar::reply()->setContent("Warned <@{$subjectId}>{$tail}."), true);
                    },
                    fn (\Throwable $e) => $modalI->respondWithMessage(Tutelar::reply(false)->setContent("Couldn't warn <@{$subjectId}>: " . Text::clip($e->getMessage(), 300)), true),
                );
            },
        );
    }

    private function promptClose(Tutelar $bot, Interaction $ci, Guild $guild, array $ticket): PromiseInterface
    {
        return $ci->showModal(
            'Close ticket',
            "tkt-modal:close:{$ticket['channelId']}",
            [Label::new(
                'Closing note',
                TextInput::new(null, TextInput::STYLE_PARAGRAPH, 'note')->setRequired(false)->setMaxLength(500),
                'Optional — added to the end of the transcript.',
            )],
            function (Interaction $modalI, $components) use ($bot, $guild, $ticket): PromiseInterface {
                $note = '';
                foreach ($components as $c) {
                    if ((string) ($c->custom_id ?? '') === 'note') {
                        $note = trim((string) ($c->value ?? ''));
                    }
                }

                // Defer: the close does cross-channel IO (transcript) then a
                // channel delete — more than the 3s modal-response window.
                return $modalI->acknowledgeWithResponse(true)->then(fn () => $this->close($bot, $modalI, $guild, $ticket, $note));
            },
        );
    }

    /**
     * Finalise the log, ship the transcript to the mod-log / log channel as a
     * `.txt` attachment, then delete the ticket channel and drop it from the
     * store. The attachment is the only record once the channel is gone, so it
     * goes out BEFORE the delete and the delete only runs if it succeeded.
     */
    private function close(Tutelar $bot, Interaction $interaction, Guild $guild, array $ticket, string $note): PromiseInterface
    {
        $actor = '<@' . ($interaction->user->id ?? '?') . '>';
        $now = time();
        $ticket['log'][] = self::entry($now, $actor, 'closed the ticket' . ($note !== '' ? " — {$note}" : ''));
        $ticket['closed'] = $now;

        $logId = $bot->guild($guild->id)->channel('modlog') ?? $bot->guild($guild->id)->channel('log');
        $logChannel = $logId !== null ? $bot->getChannel($logId) : null;

        $transcript = self::renderTranscript($ticket);
        $filename = "ticket-{$ticket['seq']}-{$ticket['channelId']}.txt";

        // Plain (non-V2) message: a V2 flag pairs badly with a bare file
        // attachment. The card carries the summary; the .txt is the record.
        $deliver = ($logChannel instanceof Channel)
            ? $logChannel->sendMessage(
                MessageBuilder::new()
                    ->setAllowedMentions(AllowedMentions::none())
                    ->setContent(
                        "🎫 **Ticket #{$ticket['seq']} closed** — " . self::summaryLine($ticket)
                        . "\nClosed by {$actor}. Full transcript attached.",
                    )
                    ->addFileFromContent($filename, $transcript),
            )
            : reject(new \RuntimeException('no mod-log / log channel is configured for the transcript'));

        return $deliver->then(function () use ($bot, $interaction, $ticket) {
            $bot->getStore()->moduleSet('tickets', (string) $ticket['channelId'], null);
            $channel = $bot->getChannel((string) $ticket['channelId']);
            $done = $channel instanceof Channel
                ? $channel->delete('Ticket closed')
                : resolve(null);

            // The reply lands in the ticket channel, which is about to be
            // deleted — best-effort; the transcript is the real receipt.
            return $done->then(static fn () => $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent('🎫 Ticket closed, transcript filed, channel deleted.'))->then(null, static fn () => null));
        }, fn (\Throwable $e) => $interaction->updateOriginalResponse(
            Tutelar::reply(false)->setContent('⚠️ Not closing — the transcript could not be filed (' . Text::clip($e->getMessage(), 200) . '). Fix the log channel with `/config set` and try again; nothing was deleted.'),
        ));
    }

    // --- store helpers -----------------------------------------------

    private function appendLog(Tutelar $bot, array $ticket, string $actor, string $text): void
    {
        $current = $bot->getStore()->moduleGet('tickets', (string) $ticket['channelId']);
        if (! is_array($current)) {
            return;
        }
        $current['log'][] = self::entry(time(), $actor, $text);
        $bot->getStore()->moduleSet('tickets', (string) $ticket['channelId'], $current);
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

    // --- rendering ---------------------------------------------------

    private function openingCard(array $ticket): MessageBuilder
    {
        $lines = ["## 🎫 Ticket #{$ticket['seq']}", self::summaryLine($ticket)];
        if (($ticket['source']['messageId'] ?? '') !== '') {
            $jump = "https://discord.com/channels/{$ticket['guildId']}/{$ticket['source']['channelId']}/{$ticket['source']['messageId']}";
            $lines[] = "[Jump to the reported message]({$jump})";
        }
        $content = trim((string) ($ticket['content'] ?? ''));
        if ($content !== '') {
            $quoted = implode("\n", array_map(static fn (string $l): string => "> {$l}", explode("\n", Text::clip($content, 1000))));
            $lines[] = $quoted;
        }
        $lines[] = "\nStaff-only. **Close** files the transcript to the log channel and deletes this channel.";

        $subjectId = (string) ($ticket['subjectId'] ?? '');
        $id = static fn (string $a): string => "tkt:{$a}:{$ticket['channelId']}";
        $row = ActionRow::new()
            ->addComponent(Button::new(Button::STYLE_DANGER, $id('close'))->setLabel('Close'))
            ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('warn'))->setLabel('Warn user')->setDisabled($subjectId === ''))
            ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('add'))->setLabel('Add user')->setDisabled($subjectId === ''));

        return MessageBuilder::new()
            ->setIsComponentsV2Flag(true)
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent(Container::new()
                ->setAccentColor(self::ACCENT)
                ->addComponent(TextDisplay::new(implode("\n", $lines)))
                ->addComponent(Separator::new())
                ->addComponent($row));
    }

    /** A short V2 progress note posted into the ticket channel. */
    private function note(string $text, bool $allowMentions = false): MessageBuilder
    {
        return MessageBuilder::new()
            ->setIsComponentsV2Flag(true)
            ->setAllowedMentions($allowMentions ? AllowedMentions::new()->setParse([AllowedMentions::TYPE_USER]) : AllowedMentions::none())
            ->addComponent(Container::new()->setAccentColor(self::NOTE_ACCENT)->addComponent(TextDisplay::new($text)));
    }

    /** One "· "-joined descriptor line for a ticket. Pure. */
    public static function summaryLine(array $ticket): string
    {
        $bits = [];
        $bits[] = ($ticket['kind'] ?? 'manual') === 'report' ? 'Reported message' : 'Manual ticket';
        if (($ticket['subjectId'] ?? '') !== '') {
            $bits[] = "subject <@{$ticket['subjectId']}>";
        }
        if (($ticket['openerId'] ?? '') !== '') {
            $bits[] = "opened by <@{$ticket['openerId']}>";
        }
        $bits[] = 'opened <t:' . (int) ($ticket['opened'] ?? time()) . ':R>';

        return implode(' · ', $bits);
    }

    /**
     * A `{ts, actor, text}` log entry. Pure.
     *
     * @return array{ts:int,actor:string,text:string}
     */
    public static function entry(int $ts, string $actor, string $text): array
    {
        return ['ts' => $ts, 'actor' => $actor, 'text' => $text];
    }

    /**
     * The plain-text transcript filed to the log channel when a ticket closes.
     * Pure — the close path's only durable artefact, and the unit-test surface.
     *
     * @param array{seq:int,guildId:string,channelId:string,kind:string,subjectId:string,openerId:string,reason:string,opened:int,closed?:int,log:list<array{ts:int,actor:string,text:string}>} $ticket
     */
    public static function renderTranscript(array $ticket): string
    {
        $fmt = static fn (int $ts): string => gmdate('Y-m-d H:i:s', $ts) . ' UTC';
        $strip = static fn (string $s): string => preg_replace('/<@!?(\d+)>/', '@$1', $s) ?? $s;

        $out = [];
        $out[] = "Tutelar ticket #{$ticket['seq']}";
        $out[] = str_repeat('=', 40);
        $out[] = 'Guild:    ' . ($ticket['guildId'] ?? '?');
        $out[] = 'Channel:  ' . ($ticket['channelId'] ?? '?') . ' (deleted on close)';
        $out[] = 'Kind:     ' . ($ticket['kind'] ?? 'manual');
        if (($ticket['subjectId'] ?? '') !== '') {
            $out[] = 'Subject:  @' . $ticket['subjectId'];
        }
        if (($ticket['openerId'] ?? '') !== '') {
            $out[] = 'Opened by:@' . $ticket['openerId'];
        }
        $out[] = 'Reason:   ' . $strip((string) ($ticket['reason'] ?? ''));
        $out[] = 'Opened:   ' . $fmt((int) ($ticket['opened'] ?? 0));
        if (($ticket['closed'] ?? 0) > 0) {
            $out[] = 'Closed:   ' . $fmt((int) $ticket['closed']);
        }
        $src = $ticket['source'] ?? [];
        if (($src['messageId'] ?? '') !== '') {
            $out[] = 'Source:   https://discord.com/channels/' . ($ticket['guildId'] ?? '@me') . '/' . ($src['channelId'] ?? '') . '/' . $src['messageId'];
        }
        $out[] = '';
        $out[] = 'Log';
        $out[] = str_repeat('-', 40);
        foreach ($ticket['log'] ?? [] as $e) {
            $e = (array) $e;
            $out[] = $fmt((int) ($e['ts'] ?? 0)) . '  ' . $strip((string) ($e['actor'] ?? '?')) . ' ' . $strip((string) ($e['text'] ?? ''));
        }
        $out[] = '';

        return implode("\n", $out);
    }

    // --- permission wiring (pure, unit-tested) ----------------------

    /**
     * `roleId => {permName => bool}` for every role part in the collection, so
     * {@see staffRoleIds()} can stay pure.
     *
     * @param iterable<Role> $roles
     *
     * @return array<string, array<string, bool>>
     */
    public static function rolePermMap(iterable $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            if (! $role instanceof Role) {
                continue;
            }
            $perms = $role->permissions;
            $map = [];
            foreach (Permissions::MODERATOR as $name) {
                $map[$name] = (bool) ($perms->{$name} ?? false);
            }
            $out[(string) $role->id] = $map;
        }

        return $out;
    }

    /**
     * The role ids that should see a ticket channel: every role (except
     * `@everyone`) whose permissions include any {@see Permissions::MODERATOR}
     * flag. Pure.
     *
     * @param array<string, array<string, bool>> $rolePerms roleId => {permName => held?}
     *
     * @return list<string>
     */
    public static function staffRoleIds(array $rolePerms, string $everyoneId): array
    {
        $ids = [];
        foreach ($rolePerms as $roleId => $held) {
            if ((string) $roleId === $everyoneId) {
                continue;
            }
            if (Permissions::anyGranted(Permissions::MODERATOR, $held) || ! empty($held['administrator'])) {
                $ids[] = (string) $roleId;
            }
        }

        return $ids;
    }

    /**
     * The `permission_overwrites` array for a new ticket channel: `@everyone`
     * denied `view_channel`, the bot and every staff role allowed the staff
     * set, and — when `$guestId` is given — that member allowed the guest set.
     * Pure; Discord wants `allow` / `deny` as decimal strings.
     *
     * @param list<string> $staffRoleIds
     *
     * @return list<array{id:string,type:int,allow:string,deny:string}>
     */
    public static function overwrites(string $everyoneId, string $botId, array $staffRoleIds, ?string $guestId): array
    {
        $ov = [
            ['id' => $everyoneId, 'type' => 0, 'allow' => '0', 'deny' => (string) self::P_VIEW],
            ['id' => $botId, 'type' => 1, 'allow' => (string) (self::STAFF_ALLOW | self::P_MANAGE_CHANNEL), 'deny' => '0'],
        ];
        foreach (array_values(array_unique($staffRoleIds)) as $roleId) {
            if ((string) $roleId === $everyoneId || (string) $roleId === $botId) {
                continue;
            }
            $ov[] = ['id' => (string) $roleId, 'type' => 0, 'allow' => (string) self::STAFF_ALLOW, 'deny' => '0'];
        }
        if ($guestId !== null && $guestId !== '' && $guestId !== $botId) {
            $ov[] = ['id' => $guestId, 'type' => 1, 'allow' => (string) self::GUEST_ALLOW, 'deny' => '0'];
        }

        return $ov;
    }
}
