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
use Tutelar\GuildConfig;

/**
 * @covers \Tutelar\GuildConfig
 */
final class GuildConfigTest extends TestCase
{
    public function testFromArrayCoercesIdsToStringsAndIgnoresJunk(): void
    {
        $config = GuildConfig::fromArray([
            'channels' => ['log' => 1234, 'welcome' => 'w1', 'bad' => ['nested']],
            'roles' => ['mod' => 55],
        ]);

        $this->assertSame(['log' => '1234', 'welcome' => 'w1'], $config->channels);
        $this->assertSame(['mod' => '55'], $config->roles);
    }

    public function testChannelAndRoleLookupsReturnNullWhenUnset(): void
    {
        $config = GuildConfig::fromArray(['channels' => ['log' => '1']]);

        $this->assertSame('1', $config->channel('log'));
        $this->assertNull($config->channel('missing'));
        $this->assertNull($config->role('missing'));
    }

    public function testMergedWithLayersOverridesOnTopOfDefaults(): void
    {
        $defaults = GuildConfig::fromArray([
            'channels' => ['log' => 'default-log', 'rules' => 'rules-chan'],
            'roles' => ['mod' => 'default-mod'],
        ]);

        $merged = $defaults->mergedWith([
            'channels' => ['log' => 'override-log'],
            'roles' => ['helper' => 'new-helper'],
        ]);

        $this->assertSame('override-log', $merged->channel('log'), 'an override replaces a default');
        $this->assertSame('rules-chan', $merged->channel('rules'), 'an untouched default survives');
        $this->assertSame('default-mod', $merged->role('mod'));
        $this->assertSame('new-helper', $merged->role('helper'), 'a new key is added');
        $this->assertSame('default-log', $defaults->channel('log'), 'the original is not mutated');
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $data = ['channels' => ['log' => '1'], 'roles' => ['mod' => '2']];

        $this->assertSame($data, GuildConfig::fromArray($data)->toArray());
    }
}
