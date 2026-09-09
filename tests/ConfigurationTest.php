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
use Tutelar\Modules\Configuration;

/**
 * @covers \Tutelar\Modules\Configuration
 */
final class ConfigurationTest extends TestCase
{
    public function testSummaryMarksEachSettingUnsetOverriddenOrDefault(): void
    {
        $summary = Configuration::summary(
            effective: ['log' => '111', 'modlog' => '222'],
            defaults: ['log' => '111'],
        );

        // log matches the file default → "from config.json"
        $this->assertStringContainsString('**Log channel** — <#111> · from config.json', $summary);
        // modlog has no default but a value → set here
        $this->assertStringContainsString('**Mod-log channel** — <#222> · set here', $summary);
    }

    public function testSummaryShowsAnUnsetSetting(): void
    {
        $summary = Configuration::summary(effective: [], defaults: []);

        $this->assertStringContainsString('**Log channel** — _not set_', $summary);
        $this->assertStringContainsString('**Mod-log channel** — _not set_', $summary);
        $this->assertStringContainsString('/config set', $summary, 'the how-to line is always present');
    }

    public function testSummaryDistinguishesAnOverrideFromTheDefault(): void
    {
        $summary = Configuration::summary(
            effective: ['log' => 'runtime'],
            defaults: ['log' => 'from-file'],
        );

        $this->assertStringContainsString('<#runtime> · set here', $summary);
        $this->assertStringNotContainsString('from-file', $summary);
    }

    public function testMissingFromNamesEveryPostPermissionTheBotLacks(): void
    {
        $this->assertSame(
            ['View Channel', 'Send Messages', 'Embed Links'],
            Configuration::missingFrom(false, []),
        );
        $this->assertSame(
            ['Embed Links'],
            Configuration::missingFrom(false, ['view_channel' => true, 'send_messages' => true]),
        );
        $this->assertSame([], Configuration::missingFrom(false, ['view_channel' => true, 'send_messages' => true, 'embed_links' => true]));
    }

    public function testMissingFromShortCircuitsOnAdministrator(): void
    {
        $this->assertSame([], Configuration::missingFrom(true, []));
    }

    public function testMissingPostPermsTreatsNullAsFineSoAColdCacheDoesNotFalseWarn(): void
    {
        $this->assertSame([], Configuration::missingPostPerms(null));
    }

    public function testEverySettingKeyHasALabelAndBlurb(): void
    {
        foreach (Configuration::SETTINGS as $key => $meta) {
            $this->assertIsString($key);
            $this->assertArrayHasKey('label', $meta);
            $this->assertArrayHasKey('blurb', $meta);
            $this->assertNotSame('', $meta['label']);
            $this->assertNotSame('', $meta['blurb']);
        }
    }
}
