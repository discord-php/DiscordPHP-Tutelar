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

use Discord\Parts\User\Activity;
use Tutelar\Tutelar;

/**
 * Rotates the bot's presence through {@see \Tutelar\Config::$presence} on a
 * timer. Replaces the legacy `status_changer_random` closure + `status.txt`
 * (the list now lives in `config.json`).
 *
 * @since 2.0.0
 */
final class PresenceRotator implements Module
{
    private int $last = -1;

    public function name(): string
    {
        return 'presence-rotator';
    }

    public function boot(Tutelar $bot): void
    {
        $entries = $bot->getConfig()->presence;
        if ($entries === []) {
            return;
        }

        $interval = max(15, $bot->getConfig()->presenceInterval);
        $rotate = function () use ($bot, $entries): void {
            $i = count($entries) === 1 ? 0 : $this->pickDifferentIndex(count($entries));
            $this->last = $i;
            $entry = $entries[$i];

            $bot->updatePresence(
                new Activity($bot, ['name' => $entry['name'], 'type' => $entry['type']]),
                false,
                $entry['state'] ?? 'online',
            );
        };

        $rotate();
        $bot->getLoop()->addPeriodicTimer($interval, $rotate);
    }

    /** A random index that isn't the one shown last (so it visibly changes). */
    private function pickDifferentIndex(int $count): int
    {
        do {
            $i = random_int(0, $count - 1);
        } while ($i === $this->last);

        return $i;
    }
}
