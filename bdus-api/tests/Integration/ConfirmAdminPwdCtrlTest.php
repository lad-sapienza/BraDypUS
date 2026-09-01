<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for ConfirmAdminPwd::check_pwd().
 *
 * Note: the response codes in the controller contain intentional typos
 * ("valid_pasword", "invalid_pasword") — tests assert the actual codes.
 */
class ConfirmAdminPwdCtrlTest extends BdusTestCase
{
    protected static string $adminPassword = 'Admin_1234!';

    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        $hash = password_hash(static::$adminPassword, PASSWORD_DEFAULT);

        // id=1 matches CurrentUser::id() set by BdusTestCase.
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (1, 'Test Admin', 'test@example.com', '{$hash}', 1)"
        );
    }

    // ── check_pwd ─────────────────────────────────────────────────────────────

    public function testCheckPwdCorrectPasswordReturnsValid(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\ConfirmAdminPwd',
            [],
            ['pwd' => static::$adminPassword]
        );
        $res = $this->callController($ctrl, 'check_pwd');

        $this->assertSame('success',       $res['status']);
        $this->assertSame('valid_pasword', $res['code']); // intentional typo in source
    }

    public function testCheckPwdWrongPasswordReturnsInvalid(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\ConfirmAdminPwd',
            [],
            ['pwd' => 'wrongpassword']
        );
        $res = $this->callController($ctrl, 'check_pwd');

        $this->assertSame('error',           $res['status']);
        $this->assertSame('invalid_pasword', $res['code']); // intentional typo in source
    }

    public function testCheckPwdEmptyPasswordReturnsInvalid(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\ConfirmAdminPwd', [], ['pwd' => '']);
        $res  = $this->callController($ctrl, 'check_pwd');

        $this->assertSame('error',           $res['status']);
        $this->assertSame('invalid_pasword', $res['code']);
    }

    public function testCheckPwdNotSuperAdminReturnsForbidden(): void
    {
        $this->setPrivilege(2); // anything > 1 is not super_admin

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\ConfirmAdminPwd',
            [],
            ['pwd' => static::$adminPassword]
        );
        $res = $this->callController($ctrl, 'check_pwd');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('not_a_super_admin_user', $res['code']);

        $this->setPrivilege(1);
    }
}
