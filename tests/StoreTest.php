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

namespace Tutelar\Tests;

use PHPUnit\Framework\TestCase;
use Tutelar\Config;
use Tutelar\Store;

/**
 * @covers \Tutelar\Store
 */
final class StoreTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tutelar-store-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/state.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function config(array $data = []): Config
    {
        $file = $this->dir . '/config.json';
        @mkdir($this->dir, 0o777, true);
        file_put_contents($file, json_encode(['token' => 't'] + $data));

        return Config::load($file, []);
    }

    public function testSetGuildChannelAndRolePersistAcrossInstances(): void
    {
        $store = new Store($this->path);
        $store->setGuildChannel('999', 'log', '1234');
        $store->setGuildRole('999', 'mod', '5678');

        $reloaded = new Store($this->path);
        $config = $reloaded->guildConfig($this->config(), '999');

        $this->assertSame('1234', $config->channel('log'));
        $this->assertSame('5678', $config->role('mod'));
    }

    public function testGuildConfigLayersRuntimeOverridesOnFileDefaults(): void
    {
        $config = $this->config([
            'guilds' => ['999' => ['channels' => ['log' => 'file-log', 'rules' => 'file-rules']]],
        ]);

        $store = new Store($this->path);
        $store->setGuildChannel('999', 'log', 'runtime-log');

        $merged = $store->guildConfig($config, '999');

        $this->assertSame('runtime-log', $merged->channel('log'), 'runtime wins');
        $this->assertSame('file-rules', $merged->channel('rules'), 'file default survives');
    }

    public function testForgetGuildDropsOnlyThatGuild(): void
    {
        $store = new Store($this->path);
        $store->setGuildChannel('111', 'log', 'a');
        $store->setGuildChannel('222', 'log', 'b');

        $store->forgetGuild('111');

        $this->assertNull($store->guildConfig($this->config(), '111')->channel('log'));
        $this->assertSame('b', $store->guildConfig($this->config(), '222')->channel('log'));
    }

    public function testModuleScratchSpaceRoundTrips(): void
    {
        $store = new Store($this->path);

        $this->assertSame('fallback', $store->moduleGet('greeter', 'template', 'fallback'));

        $store->moduleSet('greeter', 'template', 'Welcome, {user}!');

        $this->assertSame('Welcome, {user}!', (new Store($this->path))->moduleGet('greeter', 'template'));
    }

    public function testWritesAreAtomicAndLeaveNoTempFile(): void
    {
        $store = new Store($this->path);
        $store->setGuildRole('999', 'mod', '1');

        $this->assertFileExists($this->path);
        $this->assertJson((string) file_get_contents($this->path));
        $this->assertSame([], glob($this->dir . '/*.tmp'), 'the temp file is renamed away, not left behind');
    }

    public function testAStaleTempFileIsSweptOnConstruction(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, json_encode(['guilds' => []]));
        $orphan = $this->path . '.999999.tmp';
        file_put_contents($orphan, 'garbage');

        new Store($this->path);

        $this->assertFileDoesNotExist($orphan);
    }
}
