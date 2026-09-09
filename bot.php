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
use Tutelar\Moderation\CaseBook;
use Tutelar\Modules\Configuration;
use Tutelar\Modules\EventLogger;
use Tutelar\Modules\Help;
use Tutelar\Modules\Moderation;
use Tutelar\Modules\ModPanel;
use Tutelar\Modules\Onboarding;
use Tutelar\Modules\PresenceRotator;
use Tutelar\Modules\SlashCommands;

use function React\Promise\set_rejection_handler;

/**
 * The project base directory. Works when run as `php bot.php` from the repo, and
 * when run as a phpacker/phpmicro binary (nested under
 * `bin/build/<name>/<platform>/`) launched directly or from a shortcut, from any
 * working directory: walk up from the real executable path, then the working
 * directory, to the first ancestor with `vendor/autoload.php`, a `.env`, or a
 * `config.json`.
 */
$baseDir = (static function (): string {
    $seen = [];
    foreach ([\Phar::running(false) ?: null, __FILE__, \getcwd() ?: null] as $start) {
        if ($start === null) {
            continue;
        }
        $dir = \is_dir($start) ? $start : \dirname((string) \preg_replace('#^phar://#', '', $start));
        for ($i = 0; $i < 12; $i++) {
            if (isset($seen[$dir])) {
                break;
            }
            $seen[$dir] = true;
            if (\is_file($dir . '/vendor/autoload.php') || \is_file($dir . '/.env') || \is_file($dir . '/config.json')) {
                return $dir;
            }
            if (($up = \dirname($dir)) === $dir) {
                break;
            }
            $dir = $up;
        }
    }

    return \getcwd() ?: __DIR__;
})();

require is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php'
    : (is_file($baseDir . '/vendor/autoload.php') ? $baseDir . '/vendor/autoload.php'
    : throw new \RuntimeException('Composer autoloader not found. Run `composer install`, or keep the binary inside the project directory.'));

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
        $value = preg_replace('/^([\'"])(.*)\1$/', '$2', $value); // strip matching surrounding quotes
        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
})($baseDir . '/.env');

$logger = new Logger('tutelar', [
    (new StreamHandler('php://stdout', Level::Info))->setFormatter(new LineFormatter(null, null, true, true)),
]);

set_rejection_handler(static function (\Throwable $e) use ($logger): void {
    $logger->warning('Unhandled rejection: ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']');
});

$configPath = getenv('TUTELAR_CONFIG') ?: ($baseDir . '/config.json');
$statePath = getenv('TUTELAR_STATE_PATH') ?: ($baseDir . '/var/state.json');

$config = Config::load($configPath, $_ENV + getenv());
$store = new Store($statePath);

// GUILD_MEMBERS and MESSAGE_CONTENT are privileged — enable them for the
// application in the Discord Developer Portal or the gateway will refuse the
// connection. They power EventLogger's member events and message diffs;
// GUILD_MODERATION (ban add/remove) is not privileged.
$bot = new Tutelar($config, $store, [
    'logger' => $logger,
    'intents' => Intents::getDefaultIntents()
        | Intents::GUILD_MEMBERS
        | Intents::GUILD_MODERATION
        | Intents::MESSAGE_CONTENT,
    'disableVoiceClient' => true,
    'loadAllMembers' => false,
]);

$moderation = new Moderation(new CaseBook(getenv('TUTELAR_MODERATION_PATH') ?: ($baseDir . '/var/moderation.json')));

$bot
    ->addModule(new PresenceRotator())
    ->addModule(new SlashCommands())
    ->addModule(new Help())
    ->addModule(new Configuration())
    ->addModule(new Onboarding())
    ->addModule($moderation)
    ->addModule(new ModPanel($moderation))
    ->addModule(new EventLogger());

$bot->run();
