<?php

namespace Tests\Unit;

use Auth\Authorization;
use Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

/**
 * Authorization::can() must honour the application status ('on' | 'frozen' |
 * 'off') that the Dispatcher injects from bdus_cfg_app. It used to read
 * cfg/app_data.json, which no longer exists, so the status was never applied.
 */
class AuthorizationStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Authorization::setAppStatus('on');
    }

    private function actAs(int $privilege, int $id = 7): void
    {
        CurrentUser::set([
            'id' => $id, 'privilege' => $privilege, 'tkv' => 0,
            'name' => 'T', 'email' => 't@example.org', 'app' => 'test',
        ]);
    }

    public function testStatusOnAllowsNormalOperations(): void
    {
        Authorization::setAppStatus('on');
        $this->actAs(20);

        $this->assertTrue(Authorization::can('read'));
        $this->assertTrue(Authorization::can('edit'));
        $this->assertTrue(Authorization::can('add_new'));
    }

    public function testFrozenBlocksWritesButNotReads(): void
    {
        Authorization::setAppStatus('frozen');
        $this->actAs(10);

        $this->assertTrue(Authorization::can('read'));
        $this->assertFalse(Authorization::can('edit'));
        $this->assertFalse(Authorization::can('add_new'));
        $this->assertFalse(Authorization::can('multiple_edit'));
        $this->assertFalse(Authorization::can('admin'));
    }

    public function testOffBlocksEveryoneExceptSuperAdmin(): void
    {
        Authorization::setAppStatus('off');

        $this->actAs(10);
        $this->assertFalse(Authorization::can('enter'));
        $this->assertFalse(Authorization::can('read'));

        $this->actAs(1);
        $this->assertTrue(Authorization::can('enter'));
        $this->assertTrue(Authorization::can('read'));
        $this->assertTrue(Authorization::can('super_admin'));
    }

    public function testUnknownStatusFallsBackToOn(): void
    {
        Authorization::setAppStatus('banana');
        $this->actAs(20);

        $this->assertTrue(Authorization::can('edit'));
    }
}
