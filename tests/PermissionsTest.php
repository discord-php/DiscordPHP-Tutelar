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

    public function testGrantsAnyFoldsInAdministratorImpliesEverything(): void
    {
        // administrator held → true even though it isn't in the accept list.
        $this->assertTrue(Permissions::grantsAny(['manage_guild'], ['administrator' => true, 'manage_guild' => false]));

        // no administrator, no accepted permission → false.
        $this->assertFalse(Permissions::grantsAny(['manage_guild'], ['administrator' => false, 'ban_members' => true]));

        // a directly-held accepted permission → true.
        $this->assertTrue(Permissions::grantsAny(['manage_guild'], ['manage_guild' => true]));
    }

    public function testResolveGrantsTheGuildOwnerOutright(): void
    {
        $this->assertTrue(Permissions::resolve(Permissions::MANAGER, true, null, []));
        $this->assertTrue(Permissions::resolve([], true, null, []), 'owner wins even with an empty accept list');
    }

    public function testResolveAcceptsTheInteractionBitsetWhenItGrants(): void
    {
        $this->assertTrue(Permissions::resolve(
            Permissions::MANAGER,
            false,
            ['administrator' => false, 'manage_guild' => true],
            [],
        ));
    }

    public function testResolveFallsBackToAnyRoleMapWhenTheBitsetIsAbsentOrEmpty(): void
    {
        // Bitset missing (interaction payload had none), but an admin role in the walk.
        $this->assertTrue(Permissions::resolve(
            Permissions::MANAGER,
            false,
            null,
            [
                ['administrator' => false, 'manage_guild' => false], // @everyone
                ['administrator' => true, 'manage_guild' => false],  // an admin role
            ],
        ));

        // Bitset present but says no; a later role map still rescues it.
        $this->assertTrue(Permissions::resolve(
            Permissions::MANAGER,
            false,
            ['administrator' => false, 'manage_guild' => false],
            [['administrator' => false, 'manage_guild' => true]],
        ));
    }

    public function testResolveIsFalseWhenNoSourceGrants(): void
    {
        $this->assertFalse(Permissions::resolve(
            Permissions::MANAGER,
            false,
            ['administrator' => false, 'manage_guild' => false],
            [
                ['administrator' => false, 'manage_guild' => false],
                ['administrator' => false, 'manage_guild' => false],
            ],
        ));
        $this->assertFalse(Permissions::resolve(Permissions::MANAGER, false, null, []), 'nothing to go on → fail closed');
    }

    public function testTheCannedSetsAreNonEmptyAndDistinct(): void
    {
        $this->assertNotEmpty(Permissions::MODERATOR);
        $this->assertContains('manage_guild', Permissions::MANAGER);
        $this->assertContains('ban_members', Permissions::MODERATOR);
        $this->assertNotContains('ban_members', Permissions::MANAGER);
    }
}
