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

namespace Tutelar;

use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Tutelar\Modules\EventLogger;
use Tutelar\Modules\Onboarding;
use Tutelar\Modules\PresenceRotator;
use Tutelar\Modules\SlashCommands;

use function React\Promise\set_rejection_handler;

require file_exists(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : throw new \RuntimeException('Run `composer install` first.');

/**
 * Minimal `KEY=value` .env loader (no dependency). The real environment wins.
 */
(static function (string $path): void {
    if (! is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
})(__DIR__ . '/.env');

$logger = new Logger('tutelar', [
    (new StreamHandler('php://stdout', Level::Info))->setFormatter(new LineFormatter(null, null, true, true)),
]);

set_rejection_handler(static function (\Throwable $e) use ($logger): void {
    $logger->warning('Unhandled rejection: ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']');
});

$configPath = getenv('TUTELAR_CONFIG') ?: (__DIR__ . '/config.json');
$statePath = getenv('TUTELAR_STATE_PATH') ?: (__DIR__ . '/var/state.json');

$config = Config::load($configPath, $_ENV + getenv());
$store = new Store($statePath);

$bot = new Tutelar($config, $store, [
    'logger' => $logger,
    'intents' => Intents::getDefaultIntents()
        | Intents::GUILD_MEMBERS
        | Intents::GUILD_MODERATION
        | Intents::MESSAGE_CONTENT,
    'disableVoiceClient' => true,
    'loadAllMembers' => false,
]);

$bot
    ->addModule(new PresenceRotator())
    ->addModule(new SlashCommands())
    ->addModule(new Onboarding())
    ->addModule(new EventLogger());

$bot->run();
