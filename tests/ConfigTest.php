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

final class ConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/tutelar-config-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function writeConfig(array $data): void
    {
        file_put_contents($this->path, json_encode($data));
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testTheEnvironmentTokenWinsOverTheFile(): void
    {
        $this->writeConfig(['token' => 'from-file']);

        $config = Config::load($this->path, ['TOKEN' => 'from-env']);

        $this->assertSame('from-env', $config->token);
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testTheFileTokenIsUsedWhenTheEnvironmentHasNone(): void
    {
        $this->writeConfig(['token' => 'from-file']);

        $config = Config::load($this->path, []);

        $this->assertSame('from-file', $config->token);
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testAMissingTokenThrows(): void
    {
        $this->writeConfig(['github' => 'https://example.test']);

        $this->expectException(\RuntimeException::class);

        Config::load($this->path, []);
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testMalformedJsonThrowsRatherThanSilentlyEmptyingTheConfig(): void
    {
        file_put_contents($this->path, '{ "token": "t",  <-- oops');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        Config::load($this->path, ['TOKEN' => 't']);
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testAnOutOfRangePresenceTypeIsClampedToPlaying(): void
    {
        $this->writeConfig([
            'token' => 't',
            'presence' => [
                ['name' => 'bad type', 'type' => 99],
                ['name' => 'negative', 'type' => -3],
                ['name' => 'watching', 'type' => 3],
            ],
        ]);

        $config = Config::load($this->path, []);

        $this->assertSame(0, $config->presence[0]['type']);
        $this->assertSame(0, $config->presence[1]['type']);
        $this->assertSame(3, $config->presence[2]['type'], 'a valid type is left alone');
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testAMissingFileIsFineAsLongAsTheEnvironmentHasAToken(): void
    {
        $config = Config::load($this->path . '.nope', ['TOKEN' => 't']);

        $this->assertSame('t', $config->token);
        $this->assertSame([], $config->presence);
        $this->assertSame([], $config->guilds);
        $this->assertNull($config->ownerId);
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testPresenceEntriesAreNormalisedFromStringsAndObjects(): void
    {
        $this->writeConfig([
            'token' => 't',
            'presence_interval' => 90,
            'presence' => [
                'just a string',
                ['name' => 'with a type', 'type' => 3],
                ['name' => 'with a state', 'state' => 'dnd'],
                ['type' => 2], // no name -> dropped
                42,            // not a string/array -> dropped
            ],
        ]);

        $config = Config::load($this->path, []);

        $this->assertSame(90, $config->presenceInterval);
        $this->assertCount(3, $config->presence);
        $this->assertSame(['name' => 'just a string', 'type' => 0], $config->presence[0]);
        $this->assertSame(3, $config->presence[1]['type']);
        $this->assertSame('dnd', $config->presence[2]['state']);
    }

    /**
     * @covers \Tutelar\Config::load
     * @covers \Tutelar\Config::guild
     */
    public function testPerGuildDefaultsAreParsedAndFetchable(): void
    {
        $this->writeConfig([
            'token' => 't',
            'owner_id' => '111',
            'guilds' => [
                '999' => ['channels' => ['log' => '1234'], 'roles' => ['mod' => '5678']],
            ],
        ]);

        $config = Config::load($this->path, []);

        $this->assertSame('111', $config->ownerId);
        $this->assertSame('1234', $config->guild('999')->channel('log'));
        $this->assertSame('5678', $config->guild(999)->role('mod'));
        $this->assertSame([], $config->guild('nope')->channels, 'an unknown guild yields empty defaults');
    }

    /**
     * @covers \Tutelar\Config::load
     */
    public function testOwnerIdFromEnvironmentOverridesTheFile(): void
    {
        $this->writeConfig(['token' => 't', 'owner_id' => 'file-owner']);

        $config = Config::load($this->path, ['OWNER_ID' => 'env-owner']);

        $this->assertSame('env-owner', $config->ownerId);
    }
}
