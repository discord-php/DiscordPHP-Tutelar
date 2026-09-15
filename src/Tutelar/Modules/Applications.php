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
use Discord\Builders\Components\Label;
use Discord\Builders\Components\MentionableSelect;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\GuildJoinRequest;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\WebSockets\Event;
use React\Promise\PromiseInterface;
use Tutelar\Moderation\CaseBook;
use Tutelar\Support\Mention;
use Tutelar\Support\Permissions;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Server applications (Discord **join requests**) — announced, reviewable, and
 * optionally auto-approved.
 *
 * When a member-verification form is submitted Discord sends
 * `GUILD_JOIN_REQUEST_CREATE` / `_UPDATE`. Tutelar posts a card to the guild's
 * log channel that **pings whoever the server nominated**, shows who applied,
 * how old the account is and what they answered, and carries **Approve** /
 * **Deny** buttons for the staff team.
 *
 * Who gets that ping is any mix of members and roles, picked with
 * `/applications notify` — a Discord **mentionable select**, so one picker
 * covers both. Until a server chooses, it's the guild owner, which is what the
 * module did before it was configurable. The card's `allowed_mentions` names
 * exactly those ids: a role pings whether or not it is "mentionable" (given
 * `MENTION_EVERYONE`), and nothing else in the message can ping at all.
 *
 * Before posting, the application is measured against this guild's rules
 * ({@see evaluate()}): account age, whether every required form field was
 * answered, and whether the applicant has moderation history here. When
 * auto-approval is on and every rule passes, Tutelar approves the request
 * itself and the card says so; otherwise the card lists what held it back and
 * waits for a human.
 *
 * Rules and ping targets are per-guild, live in the {@see \Tutelar\Store}, and
 * are edited with `/applications` (Manage Server). Rule defaults are in
 * {@see DEFAULTS} — note that `auto` is **off** until a manager turns it on.
 *
 * Discord only sends these events to bots holding `KICK_MEMBERS`, which is the
 * same permission the approve/deny REST call needs; without it the module is
 * simply inert. With no log channel configured (`/config set` → *Log channel*)
 * there is nowhere to announce, so the module stays quiet and only auto-approval
 * still runs.
 *
 * @since 2.3.0
 */
final class Applications implements Module
{
    private const COLOR = 0xA7C5FD;

    private const APPROVED_COLOR = 0x57F287;

    private const DENIED_COLOR = 0xED4245;

    /** Discord caps a rejection reason at 160 characters. */
    private const REASON_LIMIT = 160;

    /** Remember at most this many request ids, so a long-lived bot can't leak memory. */
    private const SEEN_LIMIT = 500;

    /** How many members / roles one server may ping for an application. */
    private const MENTION_LIMIT = 10;

    /**
     * Every sub-command `/applications` should expose. Compared against what
     * Discord has registered on boot ({@see needsRegistration()}), so adding one
     * here is enough to make an existing install pick it up.
     *
     * @var list<string>
     */
    public const SUBCOMMANDS = ['view', 'notify', 'rules'];

    /**
     * The per-guild rules, and their out-of-the-box values.
     *
     * @var array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool}
     */
    public const DEFAULTS = [
        'auto' => false,
        'min_account_age_days' => 30,
        'require_answers' => true,
        'require_clean_record' => true,
    ];

    /** Request ids already announced, so `_CREATE` + `_UPDATE` don't double-post. */
    private array $seen = [];

    /**
     * Request ids Tutelar itself approved or denied. The resulting `_UPDATE`
     * would otherwise post an outcome note for a decision the card already
     * shows; each id is consumed by that one event.
     */
    private array $actioned = [];

    public function __construct(private readonly ?CaseBook $cases = null) {}

    public function name(): string
    {
        return 'applications';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(function ($repo) use ($bot): void {
            // NOT the usual "exists → leave it alone" guard. `/applications`
            // shipped before `notify` existed, and Discord keeps serving the
            // definition it was given — so an existing install would never see
            // the new sub-command. Re-register whenever what's registered is
            // missing one of ours; creating a global command by a name that
            // already exists updates it in place.
            $existing = $repo->get('name', 'applications');
            $registered = null;
            if ($existing !== null) {
                $registered = [];
                foreach ($existing->options ?? [] as $option) {
                    $registered[] = (string) $option->name;
                }
            }

            if (! self::needsRegistration($registered, self::SUBCOMMANDS)) {
                return;
            }

            if ($registered !== null) {
                $bot->logger->info('[applications] updating /applications — registered sub-commands (' . implode(', ', $registered) . ') are missing one of ' . implode(', ', self::SUBCOMMANDS));
            }

            $sub = static fn(string $name, string $desc): Option => (new Option($bot))
                ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

            $bool = static fn(string $name, string $desc, bool $required = false): Option => (new Option($bot))
                ->setType(Option::BOOLEAN)->setName($name)->setDescription($desc)->setRequired($required);

            CommandBuilder::new()
                ->setType(Command::CHAT_INPUT)
                ->setName('applications')
                ->setDescription('Review rules for this server\'s join applications. Manage Server only.')
                ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                ->addOption($sub('view', 'Show the current auto-approval rules and who gets pinged.'))
                ->addOption($sub('notify', 'Choose the members and/or roles pinged when an application arrives.'))
                ->addOption($sub('rules', 'Change the auto-approval rules.')
                    ->addOption($bool('auto', 'Approve applications that pass every rule below, without a human.', true))
                    ->addOption((new Option($bot))
                        ->setType(Option::INTEGER)
                        ->setName('min_account_age')
                        ->setDescription('Minimum Discord account age, in days (0 disables the check).')
                        ->setRequired(false))
                    ->addOption($bool('require_answers', 'Every required form field must be answered.'))
                    ->addOption($bool('clean_record', 'The applicant must have no moderation cases in this server.')))
                ->create($repo)
                ->save('applications command');
        });

        $bot->listenCommand('applications', fn(Interaction $i) => $this->route($bot, $i));

        // A new or re-submitted application. CREATE fires on submission;
        // UPDATE fires when a started draft is submitted *and* when the request
        // is approved/rejected — so both land here and the status decides.
        $bot->on(Event::GUILD_JOIN_REQUEST_CREATE, fn($request) => $this->onRequest($bot, $request));
        $bot->on(Event::GUILD_JOIN_REQUEST_UPDATE, fn($request) => $this->onRequest($bot, $request));

        // One dispatcher for every application component: the card's buttons
        // (`app:approve|deny:<requestId>`) and the notify picker
        // (`app:notify:<guildId>`).
        $bot->on(Event::INTERACTION_CREATE, function (Interaction $i) use ($bot): void {
            if ($i->type !== Interaction::TYPE_MESSAGE_COMPONENT || ! $i->guild instanceof Guild) {
                return;
            }
            $parts = explode(':', (string) ($i->data->custom_id ?? ''));
            if (($parts[0] ?? '') !== 'app' || ($parts[1] ?? '') === '' || ($parts[2] ?? '') === '') {
                return;
            }
            $this->onComponent($bot, $i, $i->guild, $parts[1], $parts[2]);
        });
    }

    // --- gateway ---------------------------------------------------------

    /**
     * Handle one join-request event: announce + maybe auto-approve a fresh
     * submission, or note the outcome of one that has just been actioned.
     */
    private function onRequest(Tutelar $bot, mixed $request): void
    {
        if (! $request instanceof GuildJoinRequest) {
            return;
        }

        $guildId = (string) ($request->guild_id ?? '');
        $requestId = (string) ($request->id ?? '');
        if ($guildId === '' || $requestId === '') {
            return;
        }

        $status = strtoupper((string) ($request->application_status ?? ''));

        if ($status === 'APPROVED' || $status === 'REJECTED') {
            unset($this->seen[$requestId]);

            // Our own approve/deny already said so on the card — don't echo it.
            if (isset($this->actioned[$requestId])) {
                unset($this->actioned[$requestId]);

                return;
            }

            $this->post($bot, $guildId, $this->outcomeEmbed($bot, $request, $status));

            return;
        }

        // STARTED is a draft the applicant hasn't sent yet — nothing to review.
        if ($status !== 'SUBMITTED' || isset($this->seen[$requestId])) {
            return;
        }
        $this->remember($this->seen, $requestId);

        $guild = $bot->guilds->get('id', $guildId);
        $rules = $this->rules($bot, $guildId);
        $verdict = self::evaluate($this->facts($bot, $guildId, $request), $rules);

        if (! $verdict['approve']) {
            $this->announce($bot, $guild, $guildId, $request, $verdict['reasons'], null);

            return;
        }

        $request->approve()->then(
            function () use ($bot, $guild, $guildId, $request, $requestId): void {
                $this->remember($this->actioned, $requestId);
                $this->announce($bot, $guild, $guildId, $request, [], true);
            },
            function (\Throwable $e) use ($bot, $guild, $guildId, $request): void {
                $bot->logger->warning('[applications] auto-approve failed: ' . $e->getMessage());
                $this->announce($bot, $guild, $guildId, $request, ['auto-approve failed — ' . Text::clip($e->getMessage(), 200)], false);
            },
        );
    }

    /**
     * The measurable facts about an application, gathered from the request, the
     * user snowflake and (when wired) the case book.
     *
     * @return array{accountAgeDays: ?float, unanswered: int, priorCases: int}
     */
    private function facts(Tutelar $bot, string $guildId, GuildJoinRequest $request): array
    {
        $userId = (string) ($request->user_id ?? $request->user?->id ?? '');
        $created = self::snowflakeTimestamp($userId);

        return [
            'accountAgeDays' => $created === null ? null : (time() - $created) / 86400,
            'unanswered' => self::unansweredRequired(self::formRows($request)),
            'priorCases' => $this->cases === null || $userId === '' ? 0 : count($this->cases->forUser($guildId, $userId)),
        ];
    }

    /**
     * Post the application card to the log channel, pinging whoever this guild
     * nominated with `/applications notify` (the owner by default) so a pending
     * application can't sit unseen.
     *
     * @param list<string> $reasons why it was not auto-approved (empty when it was)
     * @param bool|null    $auto    true = auto-approved, false = auto-approve attempted and failed, null = not attempted
     */
    private function announce(Tutelar $bot, ?Guild $guild, string $guildId, GuildJoinRequest $request, array $reasons, ?bool $auto): void
    {
        $mentions = self::effectiveTargets($this->targets($bot, $guildId), (string) ($guild?->owner_id ?? ''));
        $userId = (string) ($request->user_id ?? $request->user?->id ?? '');

        $embed = (new Embed($bot))
            ->setColor($auto === true ? self::APPROVED_COLOR : self::COLOR)
            ->setTitle($auto === true ? 'Application auto-approved' : 'New server application')
            ->setTimestamp();

        if ($user = $request->user) {
            // Linked, so the card's name + avatar opens the applicant's profile
            // the same way the Applicant field's mention does.
            $embed->setAuthor($user->displayname ?? $user->username ?? "User {$userId}", $user->avatar ?? null, Mention::profileUrl($userId));
        }
        if ($github = $bot->getConfig()->github) {
            $embed->setFooter($github);
        }

        $embed->addFieldValues(...Text::field('Applicant', $userId !== '' ? "<@{$userId}>" : 'unknown', true));
        $embed->addFieldValues(...Text::field('ID', $userId !== '' ? $userId : 'unknown', true));
        if (($created = self::snowflakeTimestamp($userId)) !== null) {
            $embed->addFieldValues(...Text::field('Account created', "<t:{$created}:R>", true));
        }
        if ($request->created_at !== null) {
            $embed->addFieldValues(...Text::field('Applied', '<t:' . $request->created_at->timestamp . ':R>', true));
        }
        $embed->addFieldValues(...Text::field('Answers', self::renderResponses(self::formRows($request))));
        $embed->addFieldValues(...Text::field('Review', self::verdictLine($auto, $reasons)));

        // Not Tutelar::reply(): that parses *user* mentions only, and a ping
        // target can be a role. Naming the exact ids also means the embed's
        // own `<@applicant>` can't ping anybody.
        $message = MessageBuilder::new()
            ->setAllowedMentions(self::allowedMentions($mentions))
            ->setContent(self::ping($mentions, $auto === true))
            ->addEmbed($embed);

        // Nothing left to decide once it is approved — no buttons.
        if ($auto !== true) {
            $message->addComponent(self::buttons((string) $request->id, false));
        }

        $this->send($bot, $guildId, $message);
    }

    /** The short note posted when an application is approved or rejected. */
    private function outcomeEmbed(Tutelar $bot, GuildJoinRequest $request, string $status): Embed
    {
        $approved = $status === 'APPROVED';
        $userId = (string) ($request->user_id ?? $request->user?->id ?? '');
        $actor = (string) ($request->actioned_by_user?->id ?? '');

        $embed = (new Embed($bot))
            ->setColor($approved ? self::APPROVED_COLOR : self::DENIED_COLOR)
            ->setTitle($approved ? 'Application approved' : 'Application denied')
            ->setTimestamp()
            ->addFieldValues(...Text::field('Applicant', $userId !== '' ? "<@{$userId}>" : 'unknown', true))
            ->addFieldValues(...Text::field('By', $actor !== '' ? "<@{$actor}>" : 'Tutelar', true));

        if (! $approved && ($reason = (string) ($request->rejection_reason ?? '')) !== '') {
            $embed->addFieldValues(...Text::field('Reason', $reason));
        }
        if ($github = $bot->getConfig()->github) {
            $embed->setFooter($github);
        }

        return $embed;
    }

    // --- components ------------------------------------------------------

    private function onComponent(Tutelar $bot, Interaction $ci, Guild $guild, string $action, string $arg): PromiseInterface
    {
        // The notify picker is a Manage Server setting, not a review action.
        if ($action === 'notify') {
            return $this->saveTargets($bot, $ci, $guild);
        }

        $requestId = $arg;

        if (! Permissions::forInteraction(Permissions::MODERATOR, $ci)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('You need a moderator permission to review applications.'), true);
        }

        if ($action === 'approve') {
            return $this->act($bot, $ci, $guild, $requestId, true, null);
        }

        if ($action !== 'deny') {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('Unknown application action.'), true);
        }

        return $ci->showModal(
            'Deny application',
            "app-modal:deny:{$requestId}",
            [Label::new(
                'Reason',
                TextInput::new(null, TextInput::STYLE_SHORT, 'reason')->setRequired(false)->setMaxLength(self::REASON_LIMIT),
                'Optional, shown to the applicant by Discord.',
            )],
            function (Interaction $modalI, $components) use ($bot, $guild, $requestId): PromiseInterface {
                $reason = '';
                foreach ($components as $component) {
                    if ((string) ($component->custom_id ?? '') === 'reason') {
                        $reason = trim((string) ($component->value ?? ''));
                    }
                }

                return $this->act($bot, $modalI, $guild, $requestId, false, $reason !== '' ? Text::clip($reason, self::REASON_LIMIT) : null);
            },
        );
    }

    /** Approve or reject `$requestId`, then replace the card's buttons with the outcome. */
    private function act(Tutelar $bot, Interaction $interaction, Guild $guild, string $requestId, bool $approve, ?string $reason): PromiseInterface
    {
        $actor = (string) ($interaction->user->id ?? '');

        return $interaction->acknowledgeWithResponse(true)
            ->then(fn() => $this->fetchRequest($guild, $requestId))
            ->then(fn(GuildJoinRequest $r) => $r->action($approve, $reason))
            ->then(
                function () use ($interaction, $requestId, $approve, $actor, $reason) {
                    $this->remember($this->actioned, $requestId);

                    // The card keeps its embed; the owner ping is replaced by
                    // the decision and the buttons go dead, so a settled
                    // application can't be clicked twice.
                    $line = $approve
                        ? "✅ Approved by <@{$actor}>."
                        : "⛔ Denied by <@{$actor}>." . ($reason !== null ? " Reason: {$reason}" : '');
                    $interaction->message?->edit(Tutelar::reply(false)->setContent($line)->addComponent(self::buttons($requestId, true)));

                    return $interaction->updateOriginalResponse(Tutelar::reply(false)->setContent($approve ? 'Application approved.' : 'Application denied.'));
                },
                fn(\Throwable $e) => $interaction->updateOriginalResponse(
                    Tutelar::reply(false)->setContent('⚠️ Could not action that application — ' . Text::clip($e->getMessage(), 300)),
                ),
            );
    }

    /**
     * The request part for `$requestId` — from the guild's cache when the
     * gateway put it there, else a freshen of the submitted queue. Rejects when
     * it is gone (already actioned elsewhere, or withdrawn).
     *
     * @return PromiseInterface<GuildJoinRequest>
     */
    private function fetchRequest(Guild $guild, string $requestId): PromiseInterface
    {
        $cached = $guild->join_requests->get('id', $requestId);
        if ($cached instanceof GuildJoinRequest) {
            return resolve($cached);
        }

        return $guild->join_requests->freshen(['status' => 'SUBMITTED'])->then(static function ($repo) use ($requestId) {
            $found = $repo->get('id', $requestId);

            return $found instanceof GuildJoinRequest
                ? $found
                : reject(new \RuntimeException('that application is no longer pending'));
        });
    }

    // --- /applications ---------------------------------------------------

    private function route(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('Server only.'), true);
        }
        if (! Permissions::forInteraction(Permissions::MANAGER, $interaction)) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('You need **Manage Server** to change application rules.'), true);
        }

        $sub = $interaction->data->options?->first();
        $arg = static fn(string $k): mixed => $sub?->options?->get('name', $k)?->value;
        $name = (string) ($sub?->name ?? 'view');

        if ($name === 'notify') {
            // An ephemeral mentionable select: the picker Discord already has
            // for members *and* roles, rather than a second command option per
            // kind of mentionable.
            return $interaction->respondWithMessage(
                $this->notifyMessage($bot, $guild, $this->targets($bot, (string) $guild->id)),
                true,
            );
        }

        if ($name !== 'rules') {
            return $interaction->respondWithMessage(
                Tutelar::reply(false)->setContent($this->summaryFor($bot, $guild)),
                true,
            );
        }

        // Only `auto` is required: an omitted option keeps whatever the guild
        // already had rather than silently resetting it to the default.
        $current = $this->rules($bot, (string) $guild->id);
        $rules = self::normalise([
            'auto' => (bool) $arg('auto'),
            'min_account_age_days' => $arg('min_account_age') ?? $current['min_account_age_days'],
            'require_answers' => $arg('require_answers') ?? $current['require_answers'],
            'require_clean_record' => $arg('clean_record') ?? $current['require_clean_record'],
        ]);
        $bot->getStore()->moduleSet('applications', "rules:{$guild->id}", $rules);

        return $interaction->respondWithMessage(
            Tutelar::reply(false)->setContent("✅ Updated.\n\n" . $this->summaryFor($bot, $guild, $rules)),
            true,
        );
    }

    /**
     * Persist the picked mentionables and re-render the picker with them as its
     * new defaults, so one message can be adjusted until it's right.
     */
    private function saveTargets(Tutelar $bot, Interaction $ci, Guild $guild): PromiseInterface
    {
        if (! Permissions::forInteraction(Permissions::MANAGER, $ci)) {
            return $ci->respondWithMessage(Tutelar::reply(false)->setContent('You need **Manage Server** to change who gets pinged.'), true);
        }

        $targets = self::selectedTargets($ci);
        $bot->getStore()->moduleSet('applications', "notify:{$guild->id}", $targets);

        return $ci->updateMessage($this->notifyMessage($bot, $guild, $targets));
    }

    /**
     * The `/applications notify` picker, pre-filled with the guild's current
     * choice.
     *
     * @param list<array{id: string, type: string}> $targets
     */
    private function notifyMessage(Tutelar $bot, Guild $guild, array $targets): MessageBuilder
    {
        $select = MentionableSelect::new("app:notify:{$guild->id}")
            ->setPlaceholder('Members and/or roles to ping')
            ->setMinValues(0)
            ->setMaxValues(self::MENTION_LIMIT);

        // Pre-selecting the stored choice is what makes "pick none" read as
        // "clear it" rather than "I forgot to choose".
        if ($targets !== []) {
            $select->setDefaultValues(array_values($targets));
        }

        return Tutelar::reply(false)
            ->setContent(self::notifyIntro($targets, (string) ($guild->owner_id ?? '')))
            ->addComponent(ActionRow::new()->addComponent($select));
    }

    /** `/applications view` for this guild, reading the store for everything it shows. */
    private function summaryFor(Tutelar $bot, Guild $guild, ?array $rules = null): string
    {
        return self::summary(
            $rules ?? $this->rules($bot, (string) $guild->id),
            $bot->guild($guild->id)->channel('log'),
            $this->targets($bot, (string) $guild->id),
            (string) ($guild->owner_id ?? ''),
        );
    }

    /**
     * The mentionables this guild pings, as stored. Empty means "nothing
     * configured" — {@see effectiveTargets()} decides what that falls back to.
     *
     * @return list<array{id: string, type: string}>
     */
    private function targets(Tutelar $bot, string $guildId): array
    {
        return self::normaliseMentions($bot->getStore()->moduleGet('applications', "notify:{$guildId}", []));
    }

    /**
     * What the picker just returned. A mentionable select sends bare ids in
     * `values`; `resolved.roles` is what separates a role from a member.
     *
     * @return list<array{id: string, type: string}>
     */
    private static function selectedTargets(Interaction $ci): array
    {
        $resolved = $ci->data->resolved ?? null;

        $picked = [];
        foreach ((array) ($ci->data->values ?? []) as $id) {
            $id = (string) $id;
            $picked[] = ['id' => $id, 'type' => $resolved?->roles?->get('id', $id) !== null ? 'role' : 'user'];
        }

        return self::normaliseMentions($picked);
    }

    /**
     * `allowed_mentions` naming exactly the ping targets, with an empty `parse`
     * so nothing else in the message (the applicant, a quoted answer) can ping.
     *
     * Built as the raw payload rather than an {@see AllowedMentions} part:
     * that part serialises `roles`/`users` through an unguarded
     * `in_array(…, $this->parse)`, which fatals whenever `parse` was never set —
     * and `setParse([])` stores null, so there is no way to set it empty.
     * {@see MessageBuilder::setAllowedMentions()} takes an array just as
     * happily. Pure.
     *
     * @param list<array{id: string, type: string}> $targets
     *
     * @return array{parse: list<string>, users: list<string>, roles: list<string>}
     */
    public static function allowedMentions(array $targets): array
    {
        return ['parse' => []] + self::splitMentions($targets);
    }

    /**
     * This guild's rules: the stored ones, normalised, over {@see DEFAULTS}.
     *
     * @return array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool}
     */
    private function rules(Tutelar $bot, string $guildId): array
    {
        return self::normalise($bot->getStore()->moduleGet('applications', "rules:{$guildId}", []));
    }

    // --- pure helpers (unit-tested) --------------------------------------

    /**
     * Coerce whatever is in the store (or came off an interaction) into a
     * complete rule set, with the day count clamped to something sane. Pure.
     *
     * @return array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool}
     */
    public static function normalise(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'auto' => (bool) ($raw['auto'] ?? self::DEFAULTS['auto']),
            'min_account_age_days' => max(0, min(3650, (int) ($raw['min_account_age_days'] ?? self::DEFAULTS['min_account_age_days']))),
            'require_answers' => (bool) ($raw['require_answers'] ?? self::DEFAULTS['require_answers']),
            'require_clean_record' => (bool) ($raw['require_clean_record'] ?? self::DEFAULTS['require_clean_record']),
        ];
    }

    /**
     * Should this application be approved without a human? Pure — every input
     * is already measured — so the policy is unit-tested rather than inferred
     * from a live gateway.
     *
     * `reasons` is why it was *not* approved, in the order the rules are
     * checked; it is empty exactly when `approve` is true.
     *
     * @param array{accountAgeDays: ?float, unanswered: int, priorCases: int}                                 $facts
     * @param array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool} $rules
     *
     * @return array{approve: bool, reasons: list<string>}
     */
    public static function evaluate(array $facts, array $rules): array
    {
        if (! ($rules['auto'] ?? false)) {
            return ['approve' => false, 'reasons' => ['auto-approval is off for this server']];
        }

        $reasons = [];

        $minDays = (int) ($rules['min_account_age_days'] ?? 0);
        $age = $facts['accountAgeDays'] ?? null;
        if ($minDays > 0) {
            if ($age === null) {
                $reasons[] = 'the account age could not be read';
            } elseif ($age < $minDays) {
                $reasons[] = sprintf('the account is %s old (minimum %d days)', self::describeAge($age), $minDays);
            }
        }

        if (($rules['require_answers'] ?? false) && ($unanswered = (int) ($facts['unanswered'] ?? 0)) > 0) {
            $reasons[] = sprintf('%d required question(s) went unanswered', $unanswered);
        }

        if (($rules['require_clean_record'] ?? false) && ($prior = (int) ($facts['priorCases'] ?? 0)) > 0) {
            $reasons[] = sprintf('%d prior moderation case(s) in this server', $prior);
        }

        return ['approve' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * When the account behind a snowflake was created, as a unix timestamp, or
     * null for anything that isn't one. Discord's epoch is 2015-01-01. Pure —
     * and it works for an applicant the bot has never cached. Pure.
     */
    public static function snowflakeTimestamp(string $id): ?int
    {
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return intdiv(((int) $id >> 22) + 1420070400000, 1000);
    }

    /**
     * `3 days` / `5 hours` / `12 minutes` for a fractional day count, so the
     * "too new" line reads naturally either side of a day. Pure.
     */
    public static function describeAge(float $days): string
    {
        if ($days >= 1) {
            return sprintf('%d day(s)', (int) floor($days));
        }
        if ($days >= 1 / 24) {
            return sprintf('%d hour(s)', (int) floor($days * 24));
        }

        return sprintf('%d minute(s)', max(0, (int) floor($days * 1440)));
    }

    /**
     * How many *required* form fields the applicant left blank. A `TERMS` field
     * counts as answered only when acknowledged (`true`). Pure.
     *
     * @param list<array{label: string, required: bool, response: mixed}> $rows
     */
    public static function unansweredRequired(array $rows): int
    {
        $missing = 0;
        foreach ($rows as $row) {
            if (! ($row['required'] ?? false)) {
                continue;
            }
            $response = $row['response'] ?? null;
            if ($response === null || $response === false || (is_string($response) && trim($response) === '') || $response === []) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * The `**Question** — answer` block for the card, clipped to fit one embed
     * field. Pure.
     *
     * @param list<array{label: string, required: bool, response: mixed}> $rows
     */
    public static function renderResponses(array $rows): string
    {
        if ($rows === []) {
            return '*(this server\'s application form has no questions)*';
        }

        $lines = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? '')) ?: 'Question';
            $response = $row['response'] ?? null;
            $answer = match (true) {
                $response === true => '✅ acknowledged',
                $response === false, $response === null => '*(blank)*',
                is_array($response) => implode(', ', array_map('strval', $response)),
                default => (string) $response,
            };
            $lines[] = '**' . Text::clip($label, 100) . '** — ' . Text::clip(trim($answer) === '' ? '*(blank)*' : $answer, 300);
        }

        return Text::clip(implode("\n", $lines), 1024);
    }

    /**
     * The card's "Review" line: auto-approved, held back (with why), or simply
     * awaiting a decision. Pure.
     *
     * @param bool|null    $auto    true = auto-approved, false = auto-approve failed, null = not attempted
     * @param list<string> $reasons
     */
    public static function verdictLine(?bool $auto, array $reasons): string
    {
        if ($auto === true) {
            return '✅ Met every auto-approval rule — approved automatically.';
        }

        $head = $auto === false
            ? '⚠️ It passed the rules, but the approval call failed:'
            : '⏳ Waiting for a decision:';

        if ($reasons === []) {
            return $head . ' no reason recorded.';
        }

        return $head . "\n" . implode("\n", array_map(static fn(string $r): string => "• {$r}", $reasons));
    }

    /**
     * Does `/applications` need (re-)registering with Discord? True when there
     * is no command at all (`$registered` null), or when the registered one is
     * missing any sub-command this version defines — which is what strands a
     * newly added sub-command on a bot that already registered the old
     * definition. Pure.
     *
     * @param list<string>|null $registered sub-command names Discord has, or null when the command doesn't exist
     * @param list<string>      $wanted     sub-command names this version defines
     */
    public static function needsRegistration(?array $registered, array $wanted): bool
    {
        if ($registered === null) {
            return true;
        }

        return array_diff($wanted, $registered) !== [];
    }

    /**
     * Coerce stored / picked ping targets into a clean list: `user` or `role`
     * (anything else is dropped), numeric ids only, first occurrence wins, and
     * no more than {@see MENTION_LIMIT} of them. Pure.
     *
     * @return list<array{id: string, type: string}>
     */
    public static function normaliseMentions(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = (string) ($entry['id'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            if ($id === '' || ! ctype_digit($id) || ! in_array($type, ['user', 'role'], true) || isset($out[$id])) {
                continue;
            }
            $out[$id] = ['id' => $id, 'type' => $type];
        }

        return array_slice(array_values($out), 0, self::MENTION_LIMIT);
    }

    /**
     * Who to actually ping: the configured mentionables, or the server owner
     * when a guild has never chosen (the behaviour before it was configurable).
     * Pure.
     *
     * @param list<array{id: string, type: string}> $targets
     *
     * @return list<array{id: string, type: string}>
     */
    public static function effectiveTargets(array $targets, string $ownerId): array
    {
        if ($targets !== []) {
            return $targets;
        }

        return $ownerId !== '' ? [['id' => $ownerId, 'type' => 'user']] : [];
    }

    /**
     * `<@user> <@&role>` for a target list — the mention syntax a role and a
     * member each need. Pure.
     *
     * @param list<array{id: string, type: string}> $targets
     */
    public static function renderMentions(array $targets): string
    {
        return implode(' ', array_map(
            static fn(array $t): string => ($t['type'] === 'role' ? '<@&' : '<@') . $t['id'] . '>',
            $targets,
        ));
    }

    /**
     * Ping targets split into the two `allowed_mentions` lists. Pure.
     *
     * @param list<array{id: string, type: string}> $targets
     *
     * @return array{users: list<string>, roles: list<string>}
     */
    public static function splitMentions(array $targets): array
    {
        $split = ['users' => [], 'roles' => []];
        foreach ($targets as $target) {
            $split[$target['type'] === 'role' ? 'roles' : 'users'][] = $target['id'];
        }

        return $split;
    }

    /**
     * The message content that carries the ping. An auto-approved application
     * is FYI, so it says so rather than demanding attention. Pure.
     *
     * @param list<array{id: string, type: string}> $targets already resolved by {@see effectiveTargets()}
     */
    public static function ping(array $targets, bool $autoApproved): string
    {
        $who = $targets === [] ? '' : self::renderMentions($targets) . ' ';

        return $autoApproved
            ? "{$who}an application was auto-approved."
            : "{$who}a new server application needs a look.";
    }

    /**
     * The copy above the `/applications notify` picker: who it pings today, and
     * what picking nobody means. Pure.
     *
     * @param list<array{id: string, type: string}> $targets
     */
    public static function notifyIntro(array $targets, string $ownerId): string
    {
        $lines = ['**Who gets pinged for a new application?**', ''];

        if ($targets !== []) {
            $lines[] = 'Currently: ' . self::renderMentions($targets);
        } elseif ($ownerId !== '') {
            $lines[] = sprintf('Currently: <@%s> — the server owner, because nothing else is set.', $ownerId);
        } else {
            $lines[] = 'Currently: nobody.';
        }

        $lines[] = '';
        $lines[] = 'Pick any mix of members and roles below (up to ' . self::MENTION_LIMIT . '). Picking none falls back to the server owner.';
        $lines[] = '_A role that is not set "mentionable" still pings if I hold **Mention @everyone, @here and All Roles**._';

        return implode("\n", $lines);
    }

    /**
     * `/applications view` body. Pure.
     *
     * @param array{auto: bool, min_account_age_days: int, require_answers: bool, require_clean_record: bool} $rules
     * @param list<array{id: string, type: string}>                                                           $targets configured ping targets, before the owner fallback
     */
    public static function summary(array $rules, ?string $logChannelId, array $targets = [], string $ownerId = ''): string
    {
        $tick = static fn(bool $on): string => $on ? '**on**' : '**off**';

        $lines = [
            '**Server applications**',
            '',
            'Auto-approval — ' . $tick($rules['auto']),
            $rules['auto']
                ? '_An application that passes every rule below is approved without a human._'
                : '_Every application waits for Approve / Deny on the card._',
            '',
            '**Rules**',
            $rules['min_account_age_days'] > 0
                ? sprintf('• Account at least **%d day(s)** old.', $rules['min_account_age_days'])
                : '• No account-age minimum.',
            '• Every required question answered — ' . $tick($rules['require_answers']) . '.',
            '• No moderation cases in this server — ' . $tick($rules['require_clean_record']) . '.',
            '',
            '**Pings**',
            $targets !== []
                ? '• ' . self::renderMentions($targets)
                : ($ownerId !== ''
                    ? sprintf('• <@%s> — the server owner, because nothing else is set.', $ownerId)
                    : '• Nobody — set one with `/applications notify`.'),
            '',
            $logChannelId !== null
                ? sprintf('Applications are announced in <#%s>.', $logChannelId)
                : '⚠️ No log channel set, so there is nowhere to announce applications — `/config set` → *Log channel*.',
            '`/applications rules auto:<true|false> …` to change the rules · `/applications notify` to change who gets pinged.',
        ];

        return implode("\n", $lines);
    }

    // --- internal --------------------------------------------------------

    /**
     * The form fields and their answers, flattened off the request's
     * `FormFieldResponse` parts for the pure helpers.
     *
     * @return list<array{label: string, required: bool, response: mixed}>
     */
    private static function formRows(GuildJoinRequest $request): array
    {
        $rows = [];
        foreach ($request->form_responses ?? [] as $field) {
            $rows[] = [
                'label' => (string) ($field->label ?? ''),
                'required' => (bool) ($field->required ?? false),
                'response' => $field->response ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The Approve / Deny row for an application — disabled once it is settled,
     * so the card still shows what the options were.
     */
    private static function buttons(string $requestId, bool $disabled): ActionRow
    {
        return ActionRow::new()
            ->addComponent(Button::new(Button::STYLE_SUCCESS, "app:approve:{$requestId}")->setLabel('Approve')->setDisabled($disabled))
            ->addComponent(Button::new(Button::STYLE_DANGER, "app:deny:{$requestId}")->setLabel('Deny')->setDisabled($disabled));
    }

    /**
     * Note a request id in one of the bookkeeping sets, dropping the oldest ids
     * once {@see SEEN_LIMIT} is passed so neither set can grow without bound on
     * a long-lived bot.
     *
     * @param array<string, true> $ids
     */
    private function remember(array &$ids, string $requestId): void
    {
        $ids[$requestId] = true;
        if (count($ids) > self::SEEN_LIMIT) {
            $ids = array_slice($ids, -self::SEEN_LIMIT, null, true);
        }
    }

    /** Post an embed-only note to the log channel. */
    private function post(Tutelar $bot, string $guildId, Embed $embed): void
    {
        $this->send($bot, $guildId, Tutelar::reply(false)->addEmbed($embed));
    }

    /**
     * Send to the guild's log channel (falling back to the mod-log). No-ops when
     * neither is configured or the channel isn't cached; a failed send is logged,
     * never thrown.
     */
    private function send(Tutelar $bot, string $guildId, MessageBuilder $message): void
    {
        $config = $bot->guild($guildId);
        $channelId = $config->channel('log') ?? $config->channel('modlog');
        if ($channelId === null) {
            return;
        }

        $bot->getChannel($channelId)?->sendMessage($message)->then(null, function (\Throwable $e) use ($bot): void {
            $bot->logger->warning('[applications] send failed: ' . $e->getMessage());
        });
    }
}
