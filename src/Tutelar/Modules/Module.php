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

use Tutelar\Tutelar;

/**
 * A self-contained feature. The legacy bot wired every listener from one giant
 * `$options['functions']` closure bag; here each concern is a module that
 * attaches its own gateway listeners / timers in {@see boot()} once the client
 * is ready.
 *
 * @since 2.0.0
 */
interface Module
{
    /** Stable short name, used for logging and {@see \Tutelar\Store} scratch keys. */
    public function name(): string;

    /** Attach listeners, timers, and slash-command handlers. Called once, after the gateway is ready. */
    public function boot(Tutelar $bot): void;
}
