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
use Discord\Builders\MessageBuilder;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;
use Tutelar\Tutelar;

use function React\Promise\resolve;

/**
 * `/member json [user]` — the raw {@see Member} object, `json_encode`d.
 *
 * The debugging counterpart to `/whois`: where that renders a friendly embed,
 * this dumps exactly what DiscordPHP holds for a member of *this* server —
 * nickname, roles, joined/boosted timestamps, flags, the permission bitfield —
 * so a bug report can carry the actual payload instead of a description of it.
 *
 * Also a **Member JSON** user context-menu entry (right-click a member → Apps),
 * which is the quick way in; the slash command exists for the `user:` option and
 * for calling it on yourself.
 *
 * Guild-only and guild-install only: a member object is a guild concept, and the
 * answer comes from this guild's member cache (with a REST fetch behind it).
 * Replies are ephemeral. A dump too big for a message is attached as
 * `member-<id>.json` rather than truncated.
 *
 * @since 2.3.0
 */
final class MemberJson implements Module
{
    /** Discord's message-content ceiling is 2000; leave room for the code fence and a note. */
    private const INLINE_LIMIT = 1900;

    public function name(): string
    {
        return 'member-json';
    }

    public function boot(Tutelar $bot): void
    {
        $bot->application->commands->freshen()->then(function ($repo) use ($bot): void {
            if ($repo->get('name', 'member') === null) {
                CommandBuilder::new()
                    ->setType(Command::CHAT_INPUT)
                    ->setName('member')
                    ->setDescription('Inspect a member of this server.')
                    ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                    ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                    ->addOption((new Option($bot))
                        ->setType(Option::SUB_COMMAND)
                        ->setName('json')
                        ->setDescription('Dump a member\'s raw Member object as JSON.')
                        ->addOption((new Option($bot))
                            ->setType(Option::USER)
                            ->setName('user')
                            ->setDescription('Whose member object (defaults to you).')
                            ->setRequired(false)))
                    ->create($repo)
                    ->save('member command');
            }

            // The user context-menu twin. setType() before setName(): setName()
            // runs the CHAT_INPUT name regex (no spaces / capitals) until the
            // type says otherwise.
            if ($repo->get('name', 'Member JSON') === null) {
                CommandBuilder::new()
                    ->setType(Command::USER)
                    ->setName('Member JSON')
                    ->setContext([Interaction::CONTEXT_TYPE_GUILD])
                    ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
                    ->create($repo)
                    ->save('Member JSON user command');
            }
        });

        $bot->listenCommand('member', fn(Interaction $i) => $this->dump($bot, $i));
        $bot->listenCommand('Member JSON', fn(Interaction $i) => $this->dump($bot, $i));
    }

    private function dump(Tutelar $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;
        if (! $guild instanceof Guild) {
            return $interaction->respondWithMessage(Tutelar::reply(false)->setContent('This only works in a server — a member object belongs to one.'), true);
        }

        $targetId = self::targetId($interaction);

        // A cold member cache means a REST fetch, which can outrun the 3-second
        // interaction deadline — defer, then edit the deferred reply.
        return $interaction->acknowledgeWithResponse(true)
            ->then(fn() => $this->resolveMember($interaction, $guild, $targetId))
            ->then(function (?Member $member) use ($interaction, $targetId) {
                if (! $member instanceof Member) {
                    return $interaction->updateOriginalResponse(
                        Tutelar::reply(false)->setContent("<@{$targetId}> isn't a member of this server."),
                    );
                }

                return $interaction->updateOriginalResponse($this->render($member, $targetId));
            });
    }

    /**
     * Encode the member and package it as a message: inline in a ```json fence
     * when it fits, otherwise attached as a file.
     */
    private function render(Member $member, string $targetId): MessageBuilder
    {
        try {
            // json_encode() on the Part goes through Part::jsonSerialize(), i.e.
            // its public attributes — the same shape `json_encode($member)`
            // gives anywhere else in the app.
            $json = json_encode($member, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\Throwable $e) {
            $json = false;
            $error = $e->getMessage();
        }

        if (! is_string($json)) {
            return Tutelar::reply(false)->setContent(
                '⚠️ Could not encode that member — ' . ($error ?? (json_last_error_msg() ?: 'unknown error')) . '.',
            );
        }

        $message = Tutelar::reply(false)->setContent(self::content($json, $targetId));

        if (! self::fitsInline($json)) {
            $message->addFileFromContent("member-{$targetId}.json", $json);
        }

        return $message;
    }

    /**
     * The member being asked about, in priority order:
     *
     *   • `target_id`       — the **Member JSON** user context-menu entry;
     *   • the `user` option — `/member json user:@someone`;
     *   • the caller        — bare `/member json`.
     *
     * Pure enough to test: it only reads the interaction payload.
     */
    public static function targetId(Interaction $interaction): string
    {
        $data = $interaction->data;

        return (string) (
            ($data->target_id ?? null)
            ?? ($data->options?->first()?->options?->get('name', 'user')?->value ?? null)
            ?? ($interaction->user->id ?? '')
        );
    }

    // --- pure helpers (unit-tested) --------------------------------------

    /** Does the encoded member fit in a message rather than a file attachment? Pure. */
    public static function fitsInline(string $json): bool
    {
        return mb_strlen($json) <= self::INLINE_LIMIT;
    }

    /**
     * The message body: the JSON in a fenced block when it fits, else a one-line
     * note pointing at the attachment. Pure. */
    public static function content(string $json, string $targetId): string
    {
        if (self::fitsInline($json)) {
            return "```json\n{$json}\n```";
        }

        return sprintf('Member object for <@%s> — %s, too big to inline, so it\'s attached.', $targetId, self::describeSize(strlen($json)));
    }

    /** `1234 bytes` / `12.1 KB`, for the attachment note. Pure. */
    public static function describeSize(int $bytes): string
    {
        return $bytes < 1024 ? "{$bytes} bytes" : sprintf('%.1f KB', $bytes / 1024);
    }

    // --- internal --------------------------------------------------------

    /**
     * The target's Member part: the guild cache first, then a REST fetch, then
     * whatever the interaction resolved (a context-menu / option payload carries
     * a partial member even when the cache is cold).
     *
     * @return PromiseInterface<?Member>
     */
    private function resolveMember(Interaction $interaction, Guild $guild, string $targetId): PromiseInterface
    {
        $cached = $guild->members->get('id', $targetId);
        if ($cached instanceof Member) {
            return resolve($cached);
        }

        return $guild->members->fetch($targetId)->then(null, static function () use ($interaction, $targetId): ?Member {
            $resolved = $interaction->data->resolved ?? null;
            $member = $resolved?->members?->get('id', $targetId) ?? $resolved?->members?->first();

            return $member instanceof Member ? $member : null;
        });
    }
}
