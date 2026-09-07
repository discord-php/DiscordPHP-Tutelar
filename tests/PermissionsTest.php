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
use Tutelar\Support\Permissions;

/**
 * @covers \Tutelar\Support\Permissions
 */
final class PermissionsTest extends TestCase
{
    public function testAnyGrantedIsTrueWhenAtLeastOneAcceptedPermissionIsHeld(): void
    {
        $this->assertTrue(Permissions::anyGranted(
            ['administrator', 'manage_guild'],
            ['manage_guild' => true, 'administrator' => false],
        ));
    }

    public function testAnyGrantedIsFalseWhenNoAcceptedPermissionIsHeld(): void
    {
        $this->assertFalse(Permissions::anyGranted(
            ['administrator', 'manage_guild'],
            ['ban_members' => true],
        ));
    }

    public function testAnyGrantedTreatsMissingKeysAsNotHeld(): void
    {
        $this->assertFalse(Permissions::anyGranted(['manage_roles'], []));
    }

    public function testAnyGrantedWithAnEmptyAcceptListIsAlwaysFalse(): void
    {
        $this->assertFalse(Permissions::anyGranted([], ['administrator' => true]));
    }

    public function testMemberHasAnyIsFalseForANullMember(): void
    {
        $this->assertFalse(Permissions::memberHasAny(Permissions::MANAGER, null));
    }

    public function testTheCannedSetsAreNonEmptyAndDistinct(): void
    {
        $this->assertNotEmpty(Permissions::MODERATOR);
        $this->assertContains('manage_guild', Permissions::MANAGER);
        $this->assertContains('ban_members', Permissions::MODERATOR);
        $this->assertNotContains('ban_members', Permissions::MANAGER);
    }
}
