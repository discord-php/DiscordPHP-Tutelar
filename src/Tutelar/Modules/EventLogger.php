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

use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Ban;
use Discord\Parts\User\Member;
use Discord\WebSockets\Event;
use Tutelar\Tutelar;

/**
 * Posts an audit embed to each guild's configured `log` channel for message
 * edits/deletions and member/ban changes.
 *
 * Consolidates the legacy `log_functions.php` — one `$log_builder` closure plus
 * eight near-identical per-event handlers — into a single module whose only
 * per-event branch is which fields to add.
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
        $bot->on(Event::MESSAGE_UPDATE, function ($message, $discord, $old) use ($bot): void {
            if (! $this->loggableMessage($bot, $message) || $old === null || $message->content === $old->content) {
                return;
            }
            $this->send($bot, $message->guild_id, $this->messageEmbed($bot, $message, 'Message edited', [
                'Before' => self::trim((string) $old->content),
                'After' => self::trim((string) $message->content),
            ]));
        });

        $bot->on(Event::MESSAGE_DELETE, function ($message) use ($bot): void {
            if (! $this->loggableMessage($bot, $message) || (string) $message->content === '') {
                return;
            }
            $this->send($bot, $message->guild_id, $this->messageEmbed($bot, $message, 'Message deleted', [
                'Content' => self::trim((string) $message->content),
            ]));
        });

        $bot->on(Event::MESSAGE_DELETE_BULK, function ($messages) use ($bot): void {
            $first = is_iterable($messages) ? (iterator_to_array($messages)[0] ?? null) : null;
            $guildId = $first->guild_id ?? null;
            $count = is_countable($messages) ? count($messages) : iterator_count($messages);
            if ($guildId !== null) {
                $this->send($bot, $guildId, $this->baseEmbed($bot)->setTitle('Bulk message delete')->setDescription("**{$count}** messages were removed."));
            }
        });

        $bot->on(Event::GUILD_MEMBER_ADD, fn(Member $m) => $this->send($bot, $m->guild_id, $this->userEmbed($bot, $m->user, 'Member joined')?->addFieldValues('Account created', '<t:' . $m->user->createdTimestamp() . ':R>', true)));

        $bot->on(Event::GUILD_MEMBER_REMOVE, fn(Member $m) => $this->send($bot, $m->guild_id, $this->userEmbed($bot, $m->user, 'Member left')));

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

        $bot->on(Event::GUILD_BAN_ADD, fn(Ban $b) => $this->send($bot, $b->guild_id, $this->userEmbed($bot, $b->user, 'Member banned', $b->reason ? ['Reason' => $b->reason] : [])));

        $bot->on(Event::GUILD_BAN_REMOVE, fn(Ban $b) => $this->send($bot, $b->guild_id, $this->userEmbed($bot, $b->user, 'Ban lifted')));
    }

    // --- pure helpers (unit-tested) --------------------------------------

    /** `` `old` → `new` `` when they differ, else null. */
    public static function describeChange(?string $old, ?string $new): ?string
    {
        if ((string) $old === (string) $new) {
            return null;
        }

        return '`' . ($old === null || $old === '' ? '—' : $old) . '` → `' . ($new === null || $new === '' ? '—' : $new) . '`';
    }

    /**
     * "+RoleA, +RoleB / -RoleC" for the symmetric difference, or null when equal.
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

    public static function trim(string $text, int $limit = 1000): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : ($text === '' ? '*(empty)*' : $text);
    }

    // --- internal -------------------------------------------------------

    private function loggableMessage(Tutelar $bot, $message): bool
    {
        return $message !== null
            && ($message->guild_id ?? null) !== null
            && (string) ($message->author->id ?? '') !== (string) $bot->id
            && empty($message->webhook_id)
            && empty($message->author->bot);
    }

    /** @param array<string, string> $fields */
    private function messageEmbed(Tutelar $bot, $message, string $title, array $fields): Embed
    {
        $embed = $this->userEmbed($bot, $message->author ?? null, $title, $fields) ?? $this->baseEmbed($bot)->setTitle($title);
        $embed->addFieldValues('Channel', "<#{$message->channel_id}>", true);
        if ($link = $message->getLinkAttribute()) {
            $embed->addFieldValues('Jump', "[link]({$link})", true);
        }

        return $embed;
    }

    /**
     * @param array<string, string> $fields
     */
    private function userEmbed(Tutelar $bot, $user, string $title, array $fields = []): ?Embed
    {
        if ($user === null) {
            return null;
        }
        $embed = $this->baseEmbed($bot)->setTitle($title)->setAuthor($user->displayname ?? $user->username ?? 'unknown', $user->avatar ?? null);
        foreach ($fields as $name => $value) {
            $embed->addFieldValues($name, (string) $value, mb_strlen((string) $value) <= 40);
        }

        return $embed;
    }

    private function baseEmbed(Tutelar $bot): Embed
    {
        $embed = (new Embed($bot))->setColor(self::COLOR)->setTimestamp();
        if ($github = $bot->getConfig()->github) {
            $embed->setFooter($github);
        }

        return $embed;
    }

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

    /** @return list<string> */
    private static function roleIds($member): array
    {
        $ids = [];
        foreach ($member->roles ?? [] as $role) {
            $ids[] = (string) ($role->id ?? $role);
        }

        return $ids;
    }
}
