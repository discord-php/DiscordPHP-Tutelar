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

use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Ban;
use Discord\Parts\User\Member;
use Discord\WebSockets\Event;
use Tutelar\Support\Text;
use Tutelar\Tutelar;

/**
 * Posts an audit embed to each guild's configured `log` channel for message
 * edits/deletions and member/ban changes.
 *
 * Consolidates the legacy `log_functions.php` — one `$log_builder` closure plus
 * eight near-identical per-event handlers — into a single module whose only
 * per-event branch is which fields to add.
 *
 * Needs the `GUILD_MESSAGES` intent (default) for the message events, and the
 * privileged `GUILD_MEMBERS` + `MESSAGE_CONTENT` intents for the member events
 * and for the before/after text; without `MESSAGE_CONTENT` the message handlers
 * simply have nothing to show and skip. The log channel is
 * `guilds.<id>.channels.log` in the config / store; with none set the module is
 * inert.
 *
 * @since 2.0.0
 */
final class EventLogger implements Module
{
    private const COLOR = 0xA7C5FD;

    public function name(): string
    {
        return 'event-logger';
    }

    public function boot(Tutelar $bot): void
    {
        // A message edit: only when we can actually see both texts (cached old
        // message + MESSAGE_CONTENT intent) and they differ.
        $bot->on(Event::MESSAGE_UPDATE, function ($message, $discord, $old) use ($bot): void {
            if (! $this->loggableMessage($bot, $message) || ! $old instanceof Message || $message->content === $old->content) {
                return;
            }
            $this->send($bot, $message->guild_id, $this->messageEmbed($bot, $message, 'Message edited', [
                'Before' => Text::clip((string) $old->content),
                'After' => Text::clip((string) $message->content),
            ]));
        });

        // A message delete: only useful when the message was cached (so we have
        // its content); an uncached delete arrives as a bare {id, channel_id,
        // guild_id} stdClass with nothing to log.
        $bot->on(Event::MESSAGE_DELETE, function ($message) use ($bot): void {
            if (! $message instanceof Message || ! $this->loggableMessage($bot, $message) || (string) $message->content === '') {
                return;
            }
            $this->send($bot, $message->guild_id, $this->messageEmbed($bot, $message, 'Message deleted', [
                'Content' => Text::clip((string) $message->content),
            ]));
        });

        // Bulk delete: DiscordPHP hands us a Collection of (Message|stdClass)
        // items, each carrying guild_id/channel_id even when uncached.
        $bot->on(Event::MESSAGE_DELETE_BULK, function ($messages) use ($bot): void {
            $first = is_object($messages) && method_exists($messages, 'first') ? $messages->first() : null;
            if ($first === null) {
                return;
            }
            $count = is_countable($messages) ? count($messages) : 0;
            $embed = $this->baseEmbed($bot)
                ->setTitle('Bulk message delete')
                ->setDescription("**{$count}** messages were removed from <#{$first->channel_id}>.");
            $this->send($bot, $first->guild_id ?? null, $embed);
        });

        $bot->on(Event::GUILD_MEMBER_ADD, fn (Member $m) => $this->send($bot, $m->guild_id, $this->userEmbed($bot, $m->user, 'Member joined')?->addFieldValues(...Text::field('Account created', '<t:' . $m->user->createdTimestamp() . ':R>', true))));

        $bot->on(Event::GUILD_MEMBER_REMOVE, fn (Member $m) => $this->send($bot, $m->guild_id, $this->userEmbed($bot, $m->user, 'Member left')));

        // Member update fires for many reasons (boost, pending, timeout, avatar);
        // we only report nickname and role changes, and only with the cached
        // "before" to diff against.
        $bot->on(Event::GUILD_MEMBER_UPDATE, function (Member $new, $discord, ?Member $old) use ($bot): void {
            if ($old === null) {
                return;
            }
            $fields = array_filter([
                'Nickname' => self::describeChange($old->nick, $new->nick),
                'Roles' => self::describeRoleChange(self::roleIds($old), self::roleIds($new)),
            ]);
            if ($fields !== []) {
                $this->send($bot, $new->guild_id, $this->userEmbed($bot, $new->user, 'Member updated', $fields));
            }
        });

        $bot->on(Event::GUILD_BAN_ADD, fn (Ban $b) => $this->send($bot, $b->guild_id, $this->userEmbed($bot, $b->user, 'Member banned', $b->reason ? ['Reason' => $b->reason] : [])));

        $bot->on(Event::GUILD_BAN_REMOVE, fn (Ban $b) => $this->send($bot, $b->guild_id, $this->userEmbed($bot, $b->user, 'Ban lifted')));
    }

    // --- pure helpers (unit-tested) --------------------------------------

    /**
     * `` `old` → `new` `` when the two differ, else null. An empty / null side
     * renders as `` `—` `` so "set" and "cleared" are still legible.
     */
    public static function describeChange(?string $old, ?string $new): ?string
    {
        if ((string) $old === (string) $new) {
            return null;
        }

        return '`' . ($old === null || $old === '' ? '—' : $old) . '` → `' . ($new === null || $new === '' ? '—' : $new) . '`';
    }

    /**
     * Space-separated role mentions for the symmetric difference — added roles
     * as `+<@&id>`, removed as `-<@&id>` — or null when the sets are equal.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    public static function describeRoleChange(array $old, array $new): ?string
    {
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        if ($added === [] && $removed === []) {
            return null;
        }

        $parts = [];
        foreach ($added as $id) {
            $parts[] = "+<@&{$id}>";
        }
        foreach ($removed as $id) {
            $parts[] = "-<@&{$id}>";
        }

        return implode(' ', $parts);
    }

    // --- internal -------------------------------------------------------

    /**
     * A message we should log: in a guild, from a real human (not this bot, not
     * another bot, not a webhook). Safe to call with the bare stdClass that an
     * uncached event carries — every access is null-guarded.
     */
    private function loggableMessage(Tutelar $bot, mixed $message): bool
    {
        return $message !== null
            && ($message->guild_id ?? null) !== null
            && (string) ($message->author->id ?? '') !== (string) $bot->id
            && empty($message->webhook_id)
            && empty($message->author->bot);
    }

    /**
     * A user-authored embed (author = the message's author) plus the channel and
     * a jump link.
     *
     * @param Message               $message
     * @param array<string, string> $fields
     */
    private function messageEmbed(Tutelar $bot, Message $message, string $title, array $fields): Embed
    {
        $embed = $this->userEmbed($bot, $message->author ?? null, $title, $fields) ?? $this->baseEmbed($bot)->setTitle($title);
        $embed->addFieldValues(...Text::field('Channel', "<#{$message->channel_id}>", true));
        if ($link = $message->link) {
            $embed->addFieldValues(...Text::field('Jump', "[link]({$link})", true));
        }

        return $embed;
    }

    /**
     * A titled embed whose author is `$user`, with `$fields` appended (inline
     * when short). Returns null when `$user` is null so callers can `?->` on it.
     *
     * @param object|null           $user   A User part, or null.
     * @param array<string, string> $fields name => value
     */
    private function userEmbed(Tutelar $bot, ?object $user, string $title, array $fields = []): ?Embed
    {
        if ($user === null) {
            return null;
        }
        $embed = $this->baseEmbed($bot)->setTitle($title)->setAuthor($user->displayname ?? $user->username ?? 'unknown', $user->avatar ?? null);
        foreach ($fields as $name => $value) {
            $embed->addFieldValues(...Text::field((string) $name, (string) $value, mb_strlen((string) $value) <= 40));
        }

        return $embed;
    }

    /** A blank embed with the module's colour, a timestamp and the repo footer. */
    private function baseEmbed(Tutelar $bot): Embed
    {
        $embed = (new Embed($bot))->setColor(self::COLOR)->setTimestamp();
        if ($github = $bot->getConfig()->github) {
            $embed->setFooter($github);
        }

        return $embed;
    }

    /**
     * Sends `$embed` to the guild's configured `log` channel. No-ops when the
     * guild has no log channel set or the bot cannot see it; a failed send is
     * logged, never thrown.
     */
    private function send(Tutelar $bot, int|string|null $guildId, ?Embed $embed): void
    {
        if ($guildId === null || $embed === null) {
            return;
        }
        $channelId = $bot->guild($guildId)->channel('log');
        if ($channelId === null) {
            return;
        }
        $bot->getChannel($channelId)?->sendMessage(Tutelar::reply(false)->addEmbed($embed))->then(null, function (\Throwable $e) use ($bot): void {
            $bot->logger->warning('[event-logger] send failed: ' . $e->getMessage());
        });
    }

    /**
     * The role ids on a member. Iterating `$member->roles` yields Role parts
     * when the guild's role cache is warm and can be empty when it isn't; the
     * `is_object` check also tolerates a bare-id entry.
     *
     * @return list<string>
     */
    private static function roleIds(Member $member): array
    {
        $ids = [];
        foreach ($member->roles as $role) {
            $ids[] = is_object($role) ? (string) $role->id : (string) $role;
        }

        return $ids;
    }
}
