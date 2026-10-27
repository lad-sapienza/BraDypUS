<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for User controller:
 *   showList, showUserForm, saveUserData, deleteOne,
 *   getTablePrivileges, saveTablePrivilege, deleteTablePrivilege.
 */
class UserCtrlTest extends BdusTestCase
{
    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        $hash = password_hash('Test_1234!', PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES
               (1, 'Test Admin',   'test@example.com',  '{$hash}', 1),
               (2, 'Regular User', 'regular@example.com', '{$hash}', 30)"
        );
    }

    // ── showList ──────────────────────────────────────────────────────────────

    public function testShowListAdminSeesAllUsers(): void
    {
        // Default privilege is super_admin (1) — can see all users.
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'showList');

        $this->assertSame('success', $res['status']);
        $this->assertTrue($res['admin']);
        $this->assertCount(2, $res['users']);
    }

    public function testShowListUserRowShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'showList');

        $row = $res['users'][0];
        foreach (['id', 'name', 'email', 'privilege', 'privilege_value', 'editable', 'override_count', 'oauth_provider'] as $k) {
            $this->assertArrayHasKey($k, $row, "Missing key: $k");
        }
    }

    public function testShowListSurfacesOauthProvider(): void
    {
        static::$db->execInTransaction("UPDATE bdus_users SET oauth_provider = 'orcid', oauth_sub = '0000-0002-1825-0097' WHERE id = 1");

        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'showList');

        $row = current(array_filter($res['users'], fn ($u) => (int) $u['id'] === 1));
        $this->assertSame('orcid', $row['oauth_provider']);

        $unlinkedRow = current(array_filter($res['users'], fn ($u) => (int) $u['id'] !== 1));
        $this->assertNull($unlinkedRow['oauth_provider']);

        static::$db->execInTransaction("UPDATE bdus_users SET oauth_provider = NULL, oauth_sub = NULL WHERE id = 1");
    }

    public function testShowListNonAdminSeesOnlyOwnRecord(): void
    {
        $this->setPrivilege(30); // read-only user

        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'showList');

        $this->assertSame('success', $res['status']);
        $this->assertFalse($res['admin']);
        $this->assertCount(1, $res['users']);
        $this->assertSame(1, (int) $res['users'][0]['id']); // own record only

        $this->setPrivilege(1);
    }

    // ── showUserForm ──────────────────────────────────────────────────────────

    public function testShowUserFormNewUser(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'showUserForm');

        $this->assertSame('success', $res['status']);
        $this->assertNull($res['id']);
        $this->assertSame('', $res['name']);
        $this->assertSame('', $res['email']);
        $this->assertIsArray($res['privileges']);
    }

    public function testShowUserFormExistingUser(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 2]);
        $res  = $this->callController($ctrl, 'showUserForm');

        $this->assertSame('success', $res['status']);
        $this->assertSame(2, (int) $res['id']);
        $this->assertSame('Regular User', $res['name']);
        $this->assertSame('regular@example.com', $res['email']);
    }

    public function testShowUserFormOtherUserForbiddenForNonAdmin(): void
    {
        $this->setPrivilege(30);

        // User id=1 is the CurrentUser (id=1); requesting id=2 → forbidden.
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 2]);
        $res  = $this->callController($ctrl, 'showUserForm');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── saveUserData ──────────────────────────────────────────────────────────

    public function testSaveUserDataCreateNewUser(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'name'      => 'New User',
            'email'     => 'newuser@example.com',
            'password'  => 'Pass_1234!',
            'privilege' => 30,
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('user_data_saved', $res['code']);
        $this->assertIsInt($res['id']);
        $this->assertGreaterThan(2, $res['id']);
    }

    public function testSaveUserDataEditExistingUser(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'id'   => 2,
            'name' => 'Updated Name',
            'email'     => 'regular@example.com', // keep same email
            'privilege' => 30,
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('user_data_saved', $res['code']);
        $this->assertArrayNotHasKey('id', $res); // no id in edit response
    }

    public function testSaveUserDataDuplicateEmailReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'name'      => 'Duplicate',
            'email'     => 'test@example.com', // already taken by user id=1
            'password'  => 'Pass_1234!',
            'privilege' => 30,
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('error',         $res['status']);
        $this->assertSame('email_present', $res['code']);
    }

    public function testSaveUserDataNonAdminCannotCreateUser(): void
    {
        $this->setPrivilege(30);

        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'name'      => 'Hacker',
            'email'     => 'hacker@example.com',
            'password'  => 'Pass!',
            'privilege' => 30,
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── deleteOne ─────────────────────────────────────────────────────────────

    public function testDeleteOneSuccess(): void
    {
        // Create a user to delete.
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (99, 'To Delete', 'todelete@example.com', 'hash', 30)"
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 99]);
        $res  = $this->callController($ctrl, 'deleteOne');

        $this->assertSame('success',      $res['status']);
        $this->assertSame('user_deleted', $res['code']);
    }

    public function testDeleteOneNonExistentUserReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 99999]);
        $res  = $this->callController($ctrl, 'deleteOne');

        $this->assertSame('error',            $res['status']);
        $this->assertSame('user_not_deleted', $res['code']);
    }

    // ── getTablePrivileges ────────────────────────────────────────────────────

    public function testGetTablePrivilegesEmptyList(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['user_id' => 2]);
        $res  = $this->callController($ctrl, 'getTablePrivileges');

        $this->assertSame('success',            $res['status']);
        $this->assertSame('table_privileges',   $res['code']);
        $this->assertIsArray($res['data']);
        $this->assertEmpty($res['data']);
    }

    public function testGetTablePrivilegesMissingUserIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'getTablePrivileges');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetTablePrivilegesNotEnoughPrivilege(): void
    {
        $this->setPrivilege(30);

        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['user_id' => 2]);
        $res  = $this->callController($ctrl, 'getTablePrivileges');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── saveTablePrivilege ────────────────────────────────────────────────────

    public function testSaveTablePrivilegeInsertNew(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'user_id'    => 2,
            'table_name' => 'items',
            'privilege'  => 30,
        ]);
        $res = $this->callController($ctrl, 'saveTablePrivilege');

        $this->assertSame('success',          $res['status']);
        $this->assertSame('privilege_saved',  $res['code']);
        $this->assertIsInt($res['id']);
    }

    public function testSaveTablePrivilegeUpdateExisting(): void
    {
        // Insert first
        static::$db->execInTransaction(
            "INSERT INTO bdus_user_table_privs (user_id, table_name, privilege)
             VALUES (2, 'tags', 20)"
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'user_id'    => 2,
            'table_name' => 'tags',
            'privilege'  => 10,
        ]);
        $res = $this->callController($ctrl, 'saveTablePrivilege');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('privilege_saved', $res['code']);
    }

    public function testSaveTablePrivilegeMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], ['user_id' => 2]);
        $res  = $this->callController($ctrl, 'saveTablePrivilege');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── deleteTablePrivilege ──────────────────────────────────────────────────

    public function testDeleteTablePrivilegeSuccess(): void
    {
        $newId = static::$db->query(
            "INSERT INTO bdus_user_table_privs (user_id, table_name, privilege)
             VALUES (2, 'items', 30)",
            [],
            'id'
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => (int) $newId]);
        $res  = $this->callController($ctrl, 'deleteTablePrivilege');

        $this->assertSame('success',            $res['status']);
        $this->assertSame('privilege_deleted',  $res['code']);
    }

    public function testDeleteTablePrivilegeNotFound(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 99999]);
        $res  = $this->callController($ctrl, 'deleteTablePrivilege');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteTablePrivilegeMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'deleteTablePrivilege');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteOneIsForAdminsOnly(): void
    {
        // Security: DELETE /api/user/{id} used to run for any signed-in user,
        // whatever their level, and could delete the super admin.
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (98, 'Victim', 'victim@example.com', 'hash', 30)"
        );

        foreach ([20, 25, 30, 39, 40] as $tier) {
            $this->setPrivilege($tier);
            $res = $this->callController($this->makeController('Bdus\\Controllers\\User', ['id' => 98]), 'deleteOne');
            $this->assertSame('not_enough_privilege', $res['code'], "tier $tier");
        }
        $this->setPrivilege(1);

        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_users WHERE id = 98', [], 'read')[0]['n']);
        static::$db->exec('DELETE FROM bdus_users WHERE id = 98');
    }

    public function testAnAdminCannotDeleteAMorePrivilegedUser(): void
    {
        $this->setPrivilege(10);

        // user 1 is the super admin (privilege 1)
        $res = $this->callController($this->makeController('Bdus\\Controllers\\User', ['id' => 1]), 'deleteOne');

        $this->assertSame('not_enough_privilege', $res['code']);
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_users WHERE id = 1', [], 'read')[0]['n']);
        $this->setPrivilege(1);
    }

    // ── revokeToken ───────────────────────────────────────────────────────────

    public function testRevokeTokenIsForAdminsOnly(): void
    {
        $before = (int) static::$db->query('SELECT token_version FROM bdus_users WHERE id = 2', [], 'read')[0]['token_version'];

        foreach ([20, 30, 39, 40] as $tier) {
            $this->setPrivilege($tier);
            $res = $this->callController($this->makeController('Bdus\\Controllers\\User', ['id' => 2]), 'revokeToken');
            $this->assertSame('not_enough_privilege', $res['code'], "tier $tier");
        }
        // an admin may not end the session of someone more privileged either
        $this->setPrivilege(10);
        static::$db->exec("UPDATE bdus_users SET privilege = 1 WHERE id = 2");
        $res = $this->callController($this->makeController('Bdus\\Controllers\\User', ['id' => 2]), 'revokeToken');
        $this->assertSame('not_enough_privilege', $res['code']);
        static::$db->exec("UPDATE bdus_users SET privilege = 30 WHERE id = 2");
        $this->setPrivilege(1);

        $after = (int) static::$db->query('SELECT token_version FROM bdus_users WHERE id = 2', [], 'read')[0]['token_version'];
        $this->assertSame($before, $after, 'no refused call may change the token version');
    }

    public function testRevokeTokenSuccess(): void
    {
        $before = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];

        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 2]);
        $res  = $this->callController($ctrl, 'revokeToken');

        $this->assertSame('success',        $res['status']);
        $this->assertSame('token_revoked',  $res['code']);

        $after = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];
        $this->assertSame($before + 1, $after, 'token_version must be incremented by 1');
    }

    public function testRevokeTokenCannotRevokeSelf(): void
    {
        // CurrentUser id is always 1 in tests (BdusTestCase default).
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 1]);
        $res  = $this->callController($ctrl, 'revokeToken');

        $this->assertSame('error',                     $res['status']);
        $this->assertSame('cannot_revoke_own_token',   $res['code']);
    }

    public function testRevokeTokenUserNotFound(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User', ['id' => 99999]);
        $res  = $this->callController($ctrl, 'revokeToken');

        $this->assertSame('error',          $res['status']);
        $this->assertSame('user_not_found', $res['code']);
    }

    public function testRevokeTokenMissingId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\User');
        $res  = $this->callController($ctrl, 'revokeToken');

        $this->assertSame('error',      $res['status']);
        $this->assertSame('missing_id', $res['code']);
    }

    // ── saveUserData: privilege change bumps token_version ────────────────────

    public function testSaveUserDataPrivilegeChangeBumpsTokenVersion(): void
    {
        $before = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];

        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'id'        => 2,
            'name'      => 'Regular User',
            'email'     => 'regular@example.com',
            'privilege' => 20, // changed from 30
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('user_data_saved', $res['code']);

        $after = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];
        $this->assertSame($before + 1, $after, 'privilege change must bump token_version');
    }

    public function testSaveUserDataNoPrivilegeChangeDoesNotBumpTokenVersion(): void
    {
        $before = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];

        // Editing only the name — no privilege key in payload.
        $ctrl = $this->makeController('Bdus\\Controllers\\User', [], [
            'id'    => 2,
            'name'  => 'Renamed User',
            'email' => 'regular@example.com',
        ]);
        $res = $this->callController($ctrl, 'saveUserData');

        $this->assertSame('success', $res['status']);

        $after = (int) static::$db->query(
            'SELECT token_version FROM bdus_users WHERE id = 2', [], 'read'
        )[0]['token_version'];
        $this->assertSame($before, $after, 'name-only edit must not bump token_version');
    }
}
