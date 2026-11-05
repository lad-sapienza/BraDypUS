<?php

namespace Tests\Unit;

use Auth\Authorization;
use Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

/** Tables nobody may write to (read-only SQL views): can() says no, whatever the privilege. */
class AuthorizationReadOnlyTablesTest extends TestCase
{
    protected function setUp(): void
    {
        Authorization::setAppStatus('on');
        Authorization::setAccessProvider(null);
        Authorization::setReadOnlyTables(['ro_view']);
    }

    protected function tearDown(): void
    {
        Authorization::setReadOnlyTables([]);
    }

    private function as(int $privilege): void
    {
        CurrentUser::set(['id' => 1, 'name' => 'u', 'email' => 'u@example.com', 'privilege' => $privilege, 'app' => 't']);
    }

    public function testWritesAreRefusedForEveryone(): void
    {
        foreach ([Authorization::SUPERADM, Authorization::ADM, Authorization::UPDATE] as $privilege) {
            $this->as($privilege);
            foreach (['add_new', 'edit', 'delete', 'multiple_edit'] as $action) {
                $this->assertFalse(Authorization::can($action, null, 'ro_view'), "$action at $privilege");
            }
        }
    }

    public function testReadingIsNotAffected(): void
    {
        $this->as(Authorization::READ);
        $this->assertTrue(Authorization::can('read', null, 'ro_view'));
        $this->assertTrue(Authorization::can('preview', null, 'ro_view'));
    }

    public function testOtherTablesAndTableLessChecksAreNotAffected(): void
    {
        $this->as(Authorization::SUPERADM);
        $this->assertTrue(Authorization::can('edit', null, 'a_normal_table'));
        $this->assertTrue(Authorization::can('edit'), 'without a table the check is the global tier');
    }

    public function testAbilitiesAndResetting(): void
    {
        $this->as(Authorization::SUPERADM);
        $this->assertSame(['create' => false, 'read' => true, 'update' => false, 'delete' => false], Authorization::tableAbilities('ro_view'));
        Authorization::setReadOnlyTables([]);
        $this->assertTrue(Authorization::can('edit', null, 'ro_view'));
    }
}
