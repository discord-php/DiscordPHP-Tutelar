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

use Carbon\Carbon;
use Discord\Builders\CommandBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;
use Tutelar\Moderation\CaseBook;
use Tutelar\Moderation\Duration;
use Tutelar\Support\Permissions;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Everything a moderator needs that Discord's own UI doesn't give a bot-run
 * server: numbered cases, a persistent warning history with escalation, notes,
 * scheduled tempbans and timeouts, filtered message purges, channel lockdown
 * with restore, and a "report to *this server's* mods" message command.
 *
 * Native actions (kick / ban / timeout / slowmode / permission overwrites) are
 * thin wrappers that additionally write a {@see CaseBook} case and post it to
 * the guild's `modlog` (or `log`) channel. Everything is one `/mod` command with
 * sub-commands, guild-only, gated on {@see Permissions::MODERATOR} plus a role
 * hierarchy check.
 *
 * @since 2.1.0
 */
final class Moderation implements Module
{
    private const COLOUR = [
        'warn' => 0xE8B923, 'note' => 0x8F8F9C, 'kick' => 0xE07A2E, 'ban' => 0xD84B4B,
        'unban' => 0x4B9E6B, 'timeout' => 0xE8B923, 'untimeout' => 0x4B9E6B,
        'purge' => 0x8F8F9C, 'lock' => 0xD84B4B, 'unlock' => 0x4B9E6B,
    ];

    /**
     * Warning-count → automatic action. Reached exactly (not "or more"), so a
     * later manual `delwarn` that drops the count back below a rung re-arms it.
     * Public so {@see ModPanel} can show the next rung as a hint.
     *
     * @var array<int, array{action: string, duration: ?string}>
     */
    public const ESCALATION = [
        3 => ['action' => 'timeout', 'duration' => '1h'],
        5 => ['action' => 'timeout', 'duration' => '1d'],
        7 => ['action' => 'kick', 'duration' => null],
        10 => ['action' => 'ban', 'duration' => null],
    ];

    /** How often the scheduled-reversal sweep runs. */
    private const SWEEP_SECONDS = 30;

    public function __construct(private readonly CaseBook $cases)
    {
    }

    public function name(): string
    {
        return 'moderation';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $bot->listenCommand('mod', fn (Interaction $i) => $this->route($bot, $i));

        // Scheduled tempban / timeout expiry. Discord auto-lifts a member
        // timeout, but not a ban, and it never posts a "case closed" note.
        $bot->getLoop()->addPeriodicTimer(self::SWEEP_SECONDS, fn () => $this->sweep($bot));
    }

    // --- command definition ------------------------------------------------

    private function define(Tutelar $bot, GlobalCommandRepository $repo): void
    {
        // The `Report to mods` message context-menu lives in {@see ModPanel}
        // now — it posts an actionable panel, not just a notice.
        if ($repo->get('name', 'mod') !== null) {
            return;
        }

        $sub = fn (string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);
        $opt = fn (string $name, string $desc, int $type, bool $required = false): Option => (new Option($bot))
            ->setType($type)->setName($name)->setDescription($desc)->setRequired($required);

        $user = fn (bool $required = true) => $opt('user', 'The member.', Option::USER, $required);
        $reason = fn () => $opt('reason', 'Shown in the mod log and the audit log.', Option::STRING);
        $dur = fn (string $d) => $opt('duration', $d, Option::STRING);

        CommandBuilder::new()
            ->setName('mod')
            ->setType(Command::CHAT_INPUT)
            ->setDescription('Moderation: warnings, cases, bans, timeouts, purges, lockdown.')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addOption($sub('warn', 'Warn a member and record a case.')->addOption($user())->addOption($opt('reason', 'Why.', Option::STRING, true)))
            ->addOption($sub('warnings', 'List a member\'s active warnings.')->addOption($user()))
            ->addOption($sub('delwarn', 'Void one warning (or any case) by number.')->addOption($opt('case', 'Case number.', Option::INTEGER, true)))
            ->addOption($sub('note', 'Attach a private mod note to a member.')->addOption($user())->addOption($opt('text', 'The note.', Option::STRING, true)))
            ->addOption($sub('case', 'Show one case.')->addOption($opt('case', 'Case number.', Option::INTEGER, true)))
            ->addOption($sub('modlogs', 'Show every case for a member.')->addOption($user()))
            ->addOption($sub('reason', 'Rewrite a case\'s reason.')->addOption($opt('case', 'Case number.', Option::INTEGER, true))->addOption($opt('text', 'New reason.', Option::STRING, true)))
            ->addOption($sub('kick', 'Remove a member from the server.')->addOption($user())->addOption($reason()))
            ->addOption($sub('ban', 'Ban a member or a raw user id.')
                ->addOption($opt('user', 'Member.', Option::USER))
                ->addOption($opt('user_id', 'Raw id, for someone not in the server.', Option::STRING))
                ->addOption($reason())
                ->addOption($dur('How long before an automatic unban (e.g. 7d). Omit for permanent.'))
                ->addOption($opt('delete_days', 'Delete this many days of their messages (0-7).', Option::INTEGER)))
            ->addOption($sub('unban', 'Lift a ban.')->addOption($opt('user_id', 'The banned user\'s id.', Option::STRING, true))->addOption($reason()))
            ->addOption($sub('timeout', 'Mute a member for a while (max 28d).')->addOption($user())->addOption($dur('e.g. 10m, 2h, 1d.')->setRequired(true))->addOption($reason()))
            ->addOption($sub('untimeout', 'Clear a member\'s timeout.')->addOption($user())->addOption($reason()))
            ->addOption($sub('purge', 'Bulk-delete recent messages, optionally filtered.')
                ->addOption($opt('count', 'How many to scan (1-100).', Option::INTEGER, true))
                ->addOption($opt('user', 'Only this member\'s messages.', Option::USER))
                ->addOption($opt('contains', 'Only messages containing this text.', Option::STRING))
                ->addOption($opt('bots', 'Only bot / webhook messages.', Option::BOOLEAN)))
            ->addOption($sub('slowmode', 'Set this channel\'s slowmode.')->addOption($opt('seconds', 'Seconds between messages (0 turns it off, max 21600).', Option::INTEGER, true)))
            ->addOption($sub('lock', 'Stop @everyone sending in a channel.')->addOption($opt('channel', 'Defaults to here.', Option::CHANNEL))->addOption($reason()))
            ->addOption($sub('unlock', 'Restore the overwrite a lock changed.')->addOption($opt('channel', 'Defaults to here.', Option::CHANNEL))->addOption($reason()))
            ->create($repo)
            ->save('mod command');
    }

    // --- routing ---------------------------------------------------------

    private function route(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }
        if (! Permissions::memberHasAny(Permissions::MODERATOR, $interaction->member)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission (kick / ban / timeout / manage server) to use this.'), true);
        }

        $sub = $interaction->data->options?->first();
        $name = (string) ($sub?->name ?? '');
        $arg = static fn (string $k, mixed $default = null): mixed => $sub?->options?->get('name', $k)?->value ?? $default;

        // Reads answer from cache/store immediately; writes defer (they make an
        // API call before they can reply).
        return match ($name) {
            'case' => $this->showCase($bot, $interaction, $guild, (int) $arg('case')),
            'warnings' => $this->showUserCases($bot, $interaction, $guild, (string) $arg('user'), 'warn'),
            'modlogs' => $this->showUserCases($bot, $interaction, $guild, (string) $arg('user'), null),
            'note' => $this->note($bot, $interaction, $guild, (string) $arg('user'), (string) $arg('text')),
            'reason' => $this->editReason($bot, $interaction, $guild, (int) $arg('case'), (string) $arg('text')),
            'delwarn' => $this->delCase($bot, $interaction, $guild, (int) $arg('case')),
            default => $interaction->acknowledgeWithResponse(true)->then(fn () => match ($name) {
                'warn' => $this->warn($bot, $interaction, $guild, (string) $arg('user'), (string) $arg('reason')),
                'kick' => $this->kick($bot, $interaction, $guild, (string) $arg('user'), (string) $arg('reason', '')),
                'ban' => $this->ban($bot, $interaction, $guild, (string) ($arg('user') ?? $arg('user_id', '')), (string) $arg('reason', ''), $arg('duration'), $arg('delete_days')),
                'unban' => $this->unban($bot, $interaction, $guild, (string) $arg('user_id'), (string) $arg('reason', '')),
                'timeout' => $this->timeout($bot, $interaction, $guild, (string) $arg('user'), (string) $arg('duration'), (string) $arg('reason', '')),
                'untimeout' => $this->untimeout($bot, $interaction, $guild, (string) $arg('user'), (string) $arg('reason', '')),
                'purge' => $this->purge($interaction, $guild, (int) $arg('count'), $arg('user'), $arg('contains'), (bool) $arg('bots', false)),
                'slowmode' => $this->slowmode($bot, $interaction, $guild, (int) $arg('seconds')),
                'lock' => $this->lock($bot, $interaction, $guild, $arg('channel'), (string) $arg('reason', ''), true),
                'unlock' => $this->lock($bot, $interaction, $guild, $arg('channel'), (string) $arg('reason', ''), false),
                default => $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent('Unknown sub-command.')),
            }),
        };
    }

    // --- infraction commands -------------------------------------------

    private function warn(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $reason): PromiseInterface
    {
        if ($refuse = $this->guard($guild, $i->member, $userId)) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($refuse));
        }

        $case = $this->cases->add($guild->id, 'warn', $userId, $i->user->id, $reason);
        $this->postCase($bot, $guild, $case);

        $count = $this->cases->warningCount($guild->id, $userId);
        $tail = " (warning {$count})";

        // Escalate on an exact rung.
        if (isset(self::ESCALATION[$count])) {
            $step = self::ESCALATION[$count];
            $tail .= " — auto-{$step['action']}";
            $auto = match ($step['action']) {
                'timeout' => $this->applyTimeout($guild, $userId, Duration::toSeconds($step['duration']), "Reached {$count} warnings"),
                'kick' => $this->applyKick($guild, $userId, "Reached {$count} warnings"),
                'ban' => $this->applyBan($guild, $userId, "Reached {$count} warnings", null, null),
                default => resolve(null),
            };

            return $auto->then(function () use ($bot, $guild, $userId, $i, $step, $count, $tail) {
                $autoCase = $this->cases->add(
                    $guild->id,
                    $step['action'],
                    $userId,
                    (string) $bot->id,
                    "Automatic: reached {$count} warnings",
                    $step['action'] === 'timeout' ? Duration::toSeconds($step['duration']) : null,
                );
                $this->postCase($bot, $guild, $autoCase);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("⚠️ Warned <@{$userId}>{$tail} · case #" . ($autoCase['id'] - 1)));
            }, fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("⚠️ Warned <@{$userId}>{$tail}, but the auto-{$step['action']} failed: {$e->getMessage()}")));
        }

        return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("⚠️ Warned <@{$userId}>{$tail} · case #{$case['id']}"));
    }

    private function kick(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $reason): PromiseInterface
    {
        if ($refuse = $this->guard($guild, $i->member, $userId)) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($refuse));
        }

        return $this->applyKick($guild, $userId, $this->auditReason($i, $reason))->then(
            function () use ($bot, $guild, $userId, $i, $reason) {
                $case = $this->cases->add($guild->id, 'kick', $userId, $i->user->id, $reason);
                $this->postCase($bot, $guild, $case);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("👢 Kicked <@{$userId}> · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't kick: {$e->getMessage()}")),
        );
    }

    private function ban(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $reason, ?string $duration, ?int $deleteDays): PromiseInterface
    {
        if ($userId === '') {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('Give a `user` or a `user_id`.'));
        }
        if ($refuse = $this->guard($guild, $i->member, $userId, allowAbsent: true)) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($refuse));
        }

        try {
            $seconds = Duration::toSeconds($duration);
        } catch (\InvalidArgumentException $e) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($e->getMessage()));
        }
        $deleteDays = $deleteDays === null ? null : max(0, min(7, $deleteDays));

        return $this->applyBan($guild, $userId, $this->auditReason($i, $reason), $seconds, $deleteDays)->then(
            function () use ($bot, $guild, $userId, $i, $reason, $seconds) {
                $case = $this->cases->add($guild->id, 'ban', $userId, $i->user->id, $reason, $seconds);
                $this->postCase($bot, $guild, $case);
                $for = $seconds === null ? 'permanently' : 'for ' . Duration::humanize($seconds);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("🔨 Banned <@{$userId}> {$for} · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't ban: {$e->getMessage()}")),
        );
    }

    private function unban(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $reason): PromiseInterface
    {
        return $guild->unban($userId)->then(
            function () use ($bot, $guild, $userId, $i, $reason) {
                $this->cases->clearReversal($guild->id, $userId);
                $case = $this->cases->add($guild->id, 'unban', $userId, $i->user->id, $reason);
                $this->postCase($bot, $guild, $case);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("✅ Unbanned <@{$userId}> · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't unban: {$e->getMessage()} (are they actually banned?)")),
        );
    }

    private function timeout(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $duration, string $reason): PromiseInterface
    {
        if ($refuse = $this->guard($guild, $i->member, $userId)) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($refuse));
        }

        try {
            $seconds = Duration::clampToTimeout(Duration::toSeconds($duration));
        } catch (\InvalidArgumentException $e) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent($e->getMessage()));
        }

        return $this->applyTimeout($guild, $userId, $seconds, $this->auditReason($i, $reason))->then(
            function () use ($bot, $guild, $userId, $i, $reason, $seconds) {
                $case = $this->cases->add($guild->id, 'timeout', $userId, $i->user->id, $reason, $seconds);
                $this->postCase($bot, $guild, $case);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('🔇 Timed out <@' . $userId . '> for ' . Duration::humanize($seconds) . " · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't time out: {$e->getMessage()}")),
        );
    }

    private function untimeout(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $reason): PromiseInterface
    {
        return $this->member($guild, $userId)->then(
            fn (?Member $m) => $m?->timeoutMember(null, $this->auditReason($i, $reason)) ?? resolve(null),
        )->then(
            function () use ($bot, $guild, $userId, $i, $reason) {
                $this->cases->clearReversal($guild->id, $userId);
                $case = $this->cases->add($guild->id, 'untimeout', $userId, $i->user->id, $reason);
                $this->postCase($bot, $guild, $case);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("🔈 Cleared the timeout on <@{$userId}> · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't clear the timeout: {$e->getMessage()}")),
        );
    }

    private function note(Tutelar $bot, Interaction $i, Guild $guild, string $userId, string $text): PromiseInterface
    {
        $case = $this->cases->add($guild->id, 'note', $userId, $i->user->id, $text);
        $this->postCase($bot, $guild, $case);

        return $i->respondWithMessage(Tutelar::reply(false)->setContent("📝 Noted on <@{$userId}> · case #{$case['id']}"), true);
    }

    // --- housekeeping commands ---------------------------------------

    private function editReason(Tutelar $bot, Interaction $i, Guild $guild, int $caseId, string $text): PromiseInterface
    {
        $ok = $this->cases->setReason($guild->id, $caseId, $text);

        return $i->respondWithMessage(Tutelar::reply(false)->setContent($ok ? "✅ Case #{$caseId} reason updated." : "No case #{$caseId} in this server."), true);
    }

    private function delCase(Tutelar $bot, Interaction $i, Guild $guild, int $caseId): PromiseInterface
    {
        $case = $this->cases->get($guild->id, $caseId);
        $ok = $case !== null && $this->cases->remove($guild->id, $caseId);
        if ($ok && ($case['type'] ?? '') === 'ban') {
            $this->cases->clearReversal($guild->id, (string) $case['user']);
        }

        return $i->respondWithMessage(Tutelar::reply(false)->setContent($ok ? "🗑️ Case #{$caseId} voided." : "No case #{$caseId} in this server."), true);
    }

    private function showCase(Tutelar $bot, Interaction $i, Guild $guild, int $caseId): PromiseInterface
    {
        $case = $this->cases->get($guild->id, $caseId);
        if ($case === null) {
            return $i->respondWithMessage(Tutelar::reply(false)->setContent("No case #{$caseId} in this server."), true);
        }

        return $i->respondWithMessage(Tutelar::reply(false)->addEmbed($this->caseEmbed($bot, $case)), true);
    }

    private function showUserCases(Tutelar $bot, Interaction $i, Guild $guild, string $userId, ?string $type): PromiseInterface
    {
        $list = $this->cases->forUser($guild->id, $userId, $type);
        $label = $type === 'warn' ? 'warnings' : 'cases';
        if ($list === []) {
            return $i->respondWithMessage(Tutelar::reply(false)->setContent("<@{$userId}> has no {$label}."), true);
        }

        $lines = [];
        foreach (array_slice($list, 0, 15) as $c) {
            $when = '<t:' . (int) $c['at'] . ':d>';
            $lines[] = "**#{$c['id']}** · `{$c['type']}` · {$when} · " . Text::clip((string) $c['reason'], 120);
        }
        $more = count($list) > 15 ? "\n… and " . (count($list) - 15) . ' more' : '';

        $embed = (new Embed($bot))->setColor(0xA7C5FD)
            ->setTitle(ucfirst($label) . " for a member ({$list[0]['user']})")
            ->setDescription(implode("\n", $lines) . $more);

        return $i->respondWithMessage(Tutelar::reply(false)->addEmbed($embed), true);
    }

    // --- purge / slowmode / lock -----------------------------------

    private function purge(Interaction $i, Guild $guild, int $count, mixed $userId, mixed $contains, bool $botsOnly): PromiseInterface
    {
        $count = max(1, min(100, $count));
        $channel = $i->channel;
        if (! $channel instanceof Channel) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('Run this in a text channel.'));
        }

        return $channel->getMessageHistory(['limit' => $count])->then(function ($messages) use ($channel, $i, $count, $userId, $contains, $botsOnly) {
            $keep = self::filterMessages(
                is_iterable($messages) ? iterator_to_array($messages) : (array) $messages,
                $userId ? (string) $userId : null,
                $contains !== null && $contains !== '' ? (string) $contains : null,
                $botsOnly,
            );
            if ($keep === []) {
                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Nothing in the last {$count} messages matched."));
            }

            return $channel->deleteMessages($keep, 'Purge by ' . $i->user->id)->then(
                fn () => $i->updateOriginalResponse(Tutelar::reply(false)->setContent('🧹 Deleted ' . count($keep) . ' message(s).')),
                fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Purge failed: {$e->getMessage()} (messages older than 14 days can't be bulk-deleted).")),
            );
        }, fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't read the channel: {$e->getMessage()}")));
    }

    private function slowmode(Tutelar $bot, Interaction $i, Guild $guild, int $seconds): PromiseInterface
    {
        $seconds = max(0, min(21600, $seconds));
        $channel = $i->channel;
        if (! $channel instanceof Channel) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('Run this in a text channel.'));
        }

        $channel->rate_limit_per_user = $seconds;

        return $guild->channels->save($channel)->then(
            fn () => $i->updateOriginalResponse(Tutelar::reply(false)->setContent($seconds === 0 ? '🐢 Slowmode off.' : "🐢 Slowmode set to {$seconds}s.")),
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't set slowmode: {$e->getMessage()}")),
        );
    }

    private function lock(Tutelar $bot, Interaction $i, Guild $guild, mixed $channelId, string $reason, bool $lock): PromiseInterface
    {
        $channel = $channelId ? $bot->getChannel((string) $channelId) : $i->channel;
        if (! $channel instanceof Channel) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('Pick a text channel.'));
        }

        $everyone = $guild->roles->get('id', $guild->id);
        if (! $everyone instanceof Role) {
            return $i->updateOriginalResponse(Tutelar::reply(false)->setContent('Could not resolve @everyone.'));
        }

        if ($lock) {
            $prior = $channel->overwrites->get('id', $everyone->id);
            $priorState = $prior?->deny?->send_messages ? 'deny' : ($prior?->allow?->send_messages ? 'allow' : 'inherit');

            return $channel->setPermissions($everyone, [], ['send_messages'], $this->auditReason($i, $reason))->then(
                function () use ($bot, $guild, $channel, $i, $reason, $priorState) {
                    $case = $this->cases->add($guild->id, 'lock', $channel->id, $i->user->id, $reason, null, ['prior_send' => $priorState]);
                    $this->postCase($bot, $guild, $case);

                    return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("🔒 Locked <#{$channel->id}> · case #{$case['id']}"));
                },
                fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't lock: {$e->getMessage()}")),
            );
        }

        // Unlock: restore whatever send_messages was before the most recent lock.
        $priorLock = $this->cases->forUser($guild->id, $channel->id, 'lock')[0] ?? null;
        $priorState = $priorLock['extra']['prior_send'] ?? 'inherit';
        $allow = $priorState === 'allow' ? ['send_messages'] : [];
        $deny = $priorState === 'deny' ? ['send_messages'] : [];

        return $channel->setPermissions($everyone, $allow, $deny, $this->auditReason($i, $reason))->then(
            function () use ($bot, $guild, $channel, $i, $reason) {
                $case = $this->cases->add($guild->id, 'unlock', $channel->id, $i->user->id, $reason);
                $this->postCase($bot, $guild, $case);

                return $i->updateOriginalResponse(Tutelar::reply(false)->setContent("🔓 Unlocked <#{$channel->id}> · case #{$case['id']}"));
            },
            fn (\Throwable $e) => $i->updateOriginalResponse(Tutelar::reply(false)->setContent("Couldn't unlock: {$e->getMessage()}")),
        );
    }

    // --- scheduled reversal sweep ---------------------------------

    private function sweep(Tutelar $bot): void
    {
        foreach ($this->cases->dueReversals() as $r) {
            $guild = $bot->guilds->get('id', $r['guild']);
            $this->cases->clearReversal($r['guild'], $r['user']);
            if (! $guild instanceof Guild) {
                continue;
            }

            $undo = $r['type'] === 'ban'
                ? $guild->unban($r['user'])
                : $this->member($guild, $r['user'])->then(fn (?Member $m) => $m?->timeoutMember(null, 'Timeout expired') ?? resolve(null));

            $undo->then(function () use ($bot, $guild, $r) {
                $case = $this->cases->add($guild->id, $r['type'] === 'ban' ? 'unban' : 'untimeout', $r['user'], (string) $bot->id, "Scheduled expiry of case #{$r['case']}");
                $this->postCase($bot, $guild, $case);
            }, function (\Throwable $e) use ($bot): void {
                $bot->logger->warning('[moderation] scheduled reversal failed: ' . $e->getMessage());
            });
        }
    }

    // --- collaborator surface (used by ModPanel) ---------------

    /** The case book, so a collaborating module can read history / counts. */
    public function cases(): CaseBook
    {
        return $this->cases;
    }

    /**
     * The self / bot / owner / role-hierarchy check, resolved from live Parts.
     * Returns a refusal string, or null when `$mod` may act on `$targetId`.
     */
    public function guardMember(Guild $guild, ?Member $mod, string $targetId, bool $allowAbsent = false): ?string
    {
        return $this->guard($guild, $mod, $targetId, $allowAbsent);
    }

    /**
     * Run one moderation action, write its {@see CaseBook} case and post it to
     * the mod-log — the shared path behind both `/mod` and the {@see ModPanel}
     * buttons. Resolves with the stored case array (the reversible ones —
     * `unban` / `untimeout` — resolve with a synthetic case for the log line).
     *
     * @param 'kick'|'ban'|'unban'|'timeout'|'untimeout'|'warn'|'note' $kind
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function actOnMember(
        Tutelar $bot,
        Guild $guild,
        string $kind,
        string $targetId,
        string $modId,
        string $reason,
        ?int $seconds = null,
        ?int $deleteDays = null,
    ): PromiseInterface {
        $auditReason = Text::clip(trim(($reason !== '' ? $reason : 'No reason given.') . " — by mod {$modId}"), 400);

        $applied = match ($kind) {
            'kick' => $this->applyKick($guild, $targetId, $auditReason),
            'ban' => $this->applyBan($guild, $targetId, $auditReason, $seconds, $deleteDays),
            'unban' => $guild->unban($targetId),
            'timeout' => $this->applyTimeout($guild, $targetId, $seconds, $auditReason),
            'untimeout' => $this->member($guild, $targetId)->then(
                static fn (?Member $m) => $m instanceof Member ? $m->timeoutMember(null, $auditReason) : resolve(null),
            ),
            'warn', 'note' => resolve(null),
            default => reject(new \InvalidArgumentException("Unknown action {$kind}")),
        };

        return $applied->then(function () use ($bot, $guild, $kind, $targetId, $modId, $reason, $seconds): array {
            $case = $this->cases->add($guild->id, $kind, $targetId, $modId, $reason, $kind === 'timeout' ? $seconds : null);
            $this->postCase($bot, $guild, $case);

            return $case;
        });
    }

    /** The "cases for a member" embed, shared with {@see ModPanel}'s Modlogs button. */
    public function caseListEmbed(Tutelar $bot, Guild $guild, string $userId, ?string $type): Embed
    {
        $list = $this->cases->forUser($guild->id, $userId, $type);
        $label = $type === 'warn' ? 'warnings' : 'cases';
        if ($list === []) {
            return (new Embed($bot))->setColor(0xA7C5FD)
                ->setTitle(ucfirst($label) . ' for a member')
                ->setDescription("<@{$userId}> has no {$label}.");
        }

        $lines = [];
        foreach (array_slice($list, 0, 15) as $c) {
            $lines[] = "**#{$c['id']}** · `{$c['type']}` · <t:" . (int) $c['at'] . ':d> · ' . Text::clip((string) $c['reason'], 120);
        }
        $more = count($list) > 15 ? "\n… and " . (count($list) - 15) . ' more' : '';

        return (new Embed($bot))->setColor(0xA7C5FD)
            ->setTitle(ucfirst($label) . " for a member ({$list[0]['user']})")
            ->setDescription(implode("\n", $lines) . $more);
    }

    // --- API helpers (return promises) --------------------------

    /** @return PromiseInterface<?Member> */
    private function member(Guild $guild, string $userId): PromiseInterface
    {
        $cached = $guild->members->get('id', $userId);

        return $cached instanceof Member ? resolve($cached) : $guild->members->fetch($userId)->then(null, static fn () => null);
    }

    private function applyKick(Guild $guild, string $userId, string $reason): PromiseInterface
    {
        return $this->member($guild, $userId)->then(fn (?Member $m) => $m instanceof Member ? $m->kick($reason) : resolve(null));
    }

    private function applyBan(Guild $guild, string $userId, string $reason, ?int $seconds, ?int $deleteDays): PromiseInterface
    {
        $options = [];
        if ($deleteDays !== null) {
            $options['delete_message_seconds'] = max(0, min(604800, $deleteDays * 86400));
        }

        return $guild->bans->ban($userId, $options, $reason);
    }

    private function applyTimeout(Guild $guild, string $userId, ?int $seconds, string $reason): PromiseInterface
    {
        $until = Carbon::now()->addSeconds(Duration::clampToTimeout($seconds));

        return $this->member($guild, $userId)->then(fn (?Member $m) => $m instanceof Member ? $m->timeoutMember($until, $reason) : resolve(null));
    }

    // --- pure helpers (unit-tested) ----------------------------

    /**
     * A refusal string when this moderator may not act on this target, or null
     * when it's allowed. `$targetTop`/`$modTop` are highest-role positions;
     * a target absent from the guild (raw-id ban) is allowed when `$allowAbsent`.
     */
    public static function refusal(
        bool $targetIsSelf,
        bool $targetIsBot,
        bool $targetIsOwner,
        bool $targetPresent,
        int $modTop,
        int $targetTop,
        bool $modIsOwner,
        bool $allowAbsent = false,
    ): ?string {
        if ($targetIsSelf) {
            return "You can't moderate yourself.";
        }
        if ($targetIsBot) {
            return "That's a bot.";
        }
        if ($targetIsOwner) {
            return "You can't moderate the server owner.";
        }
        if (! $targetPresent) {
            return $allowAbsent ? null : 'That member is not in the server.';
        }
        if (! $modIsOwner && $targetTop >= $modTop) {
            return "That member's highest role is not below yours.";
        }

        return null;
    }

    /**
     * Keep only the messages a `/mod purge` filter selects. Bulk delete can't
     * touch messages older than 14 days, so those are dropped here too.
     *
     * @param list<mixed> $messages
     *
     * @return list<mixed>
     */
    public static function filterMessages(array $messages, ?string $userId, ?string $contains, bool $botsOnly): array
    {
        $cutoff = time() - 14 * 86400 + 60;
        $needle = $contains !== null ? mb_strtolower($contains) : null;

        return array_values(array_filter($messages, static function ($m) use ($userId, $needle, $botsOnly, $cutoff): bool {
            $author = is_object($m) ? ($m->author ?? null) : null;
            $ts = is_object($m) && isset($m->timestamp) ? (is_object($m->timestamp) ? $m->timestamp->getTimestamp() : (int) $m->timestamp) : time();
            if ($ts < $cutoff) {
                return false;
            }
            if ($userId !== null && (string) ($author->id ?? '') !== $userId) {
                return false;
            }
            if ($botsOnly && ! (($author->bot ?? false) || ! empty($m->webhook_id))) {
                return false;
            }
            if ($needle !== null && ! str_contains(mb_strtolower((string) ($m->content ?? '')), $needle)) {
                return false;
            }

            return true;
        }));
    }

    // --- internal -------------------------------------------

    /** Resolve the guard inputs from live Parts, then delegate to {@see refusal()}. */
    private function guard(Guild $guild, ?Member $mod, string $targetId, bool $allowAbsent = false): ?string
    {
        $target = $guild->members->get('id', $targetId);
        $modTop = self::topRolePosition($mod, $guild);
        $targetTop = self::topRolePosition($target instanceof Member ? $target : null, $guild);

        return self::refusal(
            targetIsSelf: (string) ($mod?->id ?? '') === $targetId,
            // Only knowable for a member that's actually here; a raw-id ban of a
            // bot account is a legitimate thing to allow.
            targetIsBot: $target instanceof Member && (bool) ($target->user?->bot ?? false),
            targetIsOwner: (string) $guild->owner_id === $targetId,
            targetPresent: $target instanceof Member,
            modTop: $modTop,
            targetTop: $targetTop,
            modIsOwner: (string) $guild->owner_id === (string) ($mod?->id ?? ''),
            allowAbsent: $allowAbsent,
        );
    }

    private static function topRolePosition(?Member $member, Guild $guild): int
    {
        if ($member === null) {
            return 0;
        }

        $top = 0;
        foreach ($member->roles as $key => $role) {
            $resolved = is_object($role) && isset($role->position)
                ? $role
                : $guild->roles->get('id', (string) (is_object($role) ? ($role->id ?? $key) : ($role ?? $key)));
            $top = max($top, (int) ($resolved->position ?? 0));
        }

        return $top;
    }

    private function auditReason(Interaction $i, string $reason): string
    {
        return Text::clip(trim(($reason !== '' ? $reason : 'No reason given.') . ' — by ' . ($i->user->username ?? $i->user->id)), 400);
    }

    private function caseEmbed(Tutelar $bot, array $case): Embed
    {
        return (new Embed($bot))
            ->setColor(self::COLOUR[$case['type']] ?? 0xA7C5FD)
            ->setTitle("Case #{$case['id']} · " . strtoupper((string) $case['type']))
            ->setDescription(Text::clip((string) $case['reason'], 2000))
            ->addFieldValues(...Text::field('Target', '<@' . $case['user'] . '>', true))
            ->addFieldValues(...Text::field('Moderator', '<@' . $case['mod'] . '>', true))
            ->addFieldValues(...Text::field('When', '<t:' . (int) $case['at'] . ':F>', true))
            ->addFieldValues(...Text::field('Expires', $case['expires'] ? '<t:' . (int) $case['expires'] . ':R>' : '—', true));
    }

    private function postCase(Tutelar $bot, Guild $guild, array $case): void
    {
        $channelId = $bot->guild($guild->id)->channel('modlog') ?? $bot->guild($guild->id)->channel('log');
        if ($channelId === null) {
            return;
        }
        $bot->getChannel($channelId)?->sendMessage(Tutelar::reply(false)->addEmbed($this->caseEmbed($bot, $case)))
            ->then(null, static fn (\Throwable $e) => $bot->logger->warning('[moderation] mod-log post failed: ' . $e->getMessage()));
    }
}
