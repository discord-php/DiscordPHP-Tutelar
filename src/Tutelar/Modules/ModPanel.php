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
 * @since 2.2.0
 */
final class ModPanel implements Module
{
    private const ACCENT = 0xA7C5FD;

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
        });

        $bot->listenCommand('Moderate', fn (Interaction $i) => $this->open($bot, $i, (string) ($i->data->target_id ?? '')));
        $bot->listenCommand('modpanel', fn (Interaction $i) => $this->open($bot, $i, (string) ($i->data->options?->first()?->value ?? '')));

        // One dispatcher for every panel button — stable `mp:<action>:<target>`
        // custom_ids, so a self-refreshing panel never stacks listeners.
        $bot->on(Event::INTERACTION_CREATE, function (Interaction $i) use ($bot): void {
            if ($i->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }
            $id = (string) ($i->data->custom_id ?? '');
            if (! str_starts_with($id, 'mp:')) {
                return;
            }
            [, $action, $targetId] = explode(':', $id, 3) + [null, '', ''];
            if ($i->guild instanceof Guild && $action !== '' && $targetId !== '') {
                $this->onButton($bot, $i, $i->guild, $targetId, $action);
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
            $lines[] = 'Account created <t:' . $user->createdTimestamp() . ':R>';
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
}
