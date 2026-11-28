<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * The files table is a configured table: an administrator adds custom fields to it like to any
 * table, and they travel through the list, the update (validated, versioned) and the search. Its
 * structure stays fixed: the table and its system fields cannot be removed, renamed or retyped, and a
 * request can never write the columns that belong to the binary.
 */
class FileCustomFieldsTest extends BdusTestCase
{
    private const TB = 'bdus_files';

    protected function setUp(): void
    {
        $this->setPrivilege(1);
        static::insertFile(801, 'png', 'a');
        static::insertFile(802, 'jpg', 'b');
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id >= 801');
        static::$db->exec('DELETE FROM bdus_files WHERE id >= 801');
        static::$db->exec("DELETE FROM bdus_versions WHERE tb = 'bdus_files'");
        $this->setPrivilege(1);
    }

    private function cfgCall(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController(\Bdus\Controllers\Config::class, $get, $post), $method);
    }

    private function fileCall(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController(\Bdus\Controllers\File::class, $get, $post), $method);
    }

    /** Adds the custom field `licence` (once per class: the schema lives as long as the class). */
    private function withLicence(array $extra = []): void
    {
        if (in_array('licence', array_column((new \DB\Inspect(static::$db))->tableColumns(self::TB), 'fld'), true)) {
            return;
        }
        $res = $this->cfgCall('add_new_fld', ['tb' => self::TB], array_merge(
            ['name' => 'licence', 'label' => 'Licence', 'type' => 'text', 'db_type' => 'TEXT'],
            $extra
        ));
        $this->assertSame('ok_cfg_data_updated', $res['code'], json_encode($res));
    }

    private function row(int $id): array
    {
        return static::$db->query('SELECT * FROM bdus_files WHERE id = ?', [$id], 'read')[0];
    }

    // ── adding and using a custom field ───────────────────────────────────────

    public function testACustomFieldIsAddedToTheTableAndTheConfiguration(): void
    {
        $this->withLicence();

        $this->assertContains('licence', array_column((new \DB\Inspect(static::$db))->tableColumns(self::TB), 'fld'));
        $this->assertSame('Licence', static::$cfg->get('tables.bdus_files.fields.licence.label'));
    }

    public function testTheListCarriesTheCustomFieldWithTheOthers(): void
    {
        $this->withLicence();
        static::$db->exec("UPDATE bdus_files SET licence = 'CC BY' WHERE id = 801");

        $files = array_column($this->fileCall('getFiles')['files'], null, 'id');

        $this->assertSame('CC BY', $files[801]['licence']);
        $this->assertNull($files[802]['licence']);
        foreach (['id', 'uuid', 'ext', 'filename', 'description', 'keywords', 'attribution', 'public', 'links', 'is_image', 'can_update', 'can_delete'] as $k) {
            $this->assertArrayHasKey($k, $files[801], $k);
        }
    }

    public function testBooleansAreReturnedAsBooleans(): void
    {
        static::$db->exec('UPDATE bdus_files SET public = 1 WHERE id = 801');
        $f = array_column($this->fileCall('getFiles')['files'], null, 'id')[801];
        $this->assertTrue($f['public']);
        $this->assertArrayNotHasKey('printable', $f);
    }

    public function testUpdatingACustomFieldIsValidatedWrittenAndVersioned(): void
    {
        $this->withLicence();

        $res = $this->fileCall('updateFile', ['fileId' => 801], ['licence' => 'CC0', 'description' => 'd']);

        $this->assertSame('ok_file_updated', $res['code'], json_encode($res));
        $this->assertSame('CC0', $this->row(801)['licence']);
        $this->assertSame('d', $this->row(801)['description']);
        $this->assertSame(1, (int) static::$db->query(
            "SELECT COUNT(*) AS n FROM bdus_versions WHERE tb = 'bdus_files' AND rowid = 801", [], 'read')[0]['n'],
            'a change to a file is now part of its history');
    }

    public function testACustomFieldIsSearchable(): void
    {
        $this->withLicence();
        static::$db->exec("UPDATE bdus_files SET licence = 'Public Domain Mark' WHERE id = 802");

        $found = array_column($this->fileCall('getFiles', ['search' => 'Domain Mark'])['files'], 'id');

        $this->assertSame([802], array_values(array_intersect($found, [801, 802])));
    }

    public function testAFieldWithACheckIsEnforced(): void
    {
        if (!in_array('credit', array_column((new \DB\Inspect(static::$db))->tableColumns(self::TB), 'fld'), true)) {
            $res = $this->cfgCall('add_new_fld', ['tb' => self::TB], [
                'name' => 'credit', 'label' => 'Credit', 'type' => 'text', 'db_type' => 'TEXT', 'check' => ['not_empty'],
            ]);
            $this->assertSame('ok_cfg_data_updated', $res['code'], json_encode($res));
        }

        $res = $this->fileCall('updateFile', ['fileId' => 801], ['credit' => '']);

        $this->assertSame('validation_failed', $res['code'], json_encode($res));
        $this->assertArrayHasKey('errors', $res);
        $this->assertNull($this->row(801)['credit'], 'nothing was written');
    }

    // ── the list: field description, order and the public filter ──────────────

    public function testTheListDescribesTheFieldsForTheInterface(): void
    {
        $this->withLicence();

        $fields = array_column($this->fileCall('getFiles')['fields'], null, 'name');

        $this->assertSame(['name', 'label', 'type', 'editable', 'system'], array_keys($fields['licence']));
        $this->assertTrue($fields['licence']['editable']);
        $this->assertFalse($fields['licence']['system'], 'a custom field');
        $this->assertTrue($fields['description']['system']);
        foreach (['id', 'uuid', 'ext'] as $k) {
            $this->assertFalse($fields[$k]['editable'], "$k belongs to the binary");
        }
        $this->assertArrayNotHasKey('creator', $fields);
        $this->assertArrayHasKey('fields', $this->fileCall('getFiles', ['search' => 'matches nothing at all zzz']), 'also when the page is empty');
    }

    public function testTheListCanBeSortedByAConfiguredColumn(): void
    {
        static::$db->exec("UPDATE bdus_files SET filename = 'zeta' WHERE id = 801");
        static::$db->exec("UPDATE bdus_files SET filename = 'alpha' WHERE id = 802");
        $ids = fn(array $get) => array_values(array_intersect(array_column($this->fileCall('getFiles', $get)['files'], 'id'), [801, 802]));

        $this->assertSame([802, 801], $ids(['sort_field' => 'filename', 'sort_dir' => 'asc']));
        $this->assertSame([801, 802], $ids(['sort_field' => 'filename', 'sort_dir' => 'desc']));
        $this->assertSame([802, 801], $ids([]), 'default: newest first');
    }

    public function testTheSortColumnMustBeConfiguredAndNeverReachesSqlAsIs(): void
    {
        foreach (['nope', 'id; DROP TABLE bdus_files', 'filename DESC, (SELECT 1)', 'creator '] as $bad) {
            $res = $this->fileCall('getFiles', ['sort_field' => $bad]);
            $this->assertSame('success', $res['status'], $bad);
            $this->assertSame([802, 801], array_values(array_intersect(array_column($res['files'], 'id'), [801, 802])), "$bad falls back to the default order");
        }
        $this->assertSame(2, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id >= 801', [], 'read')[0]['n']);
    }

    public function testThePreviousNextIdsFollowTheSameOrder(): void
    {
        static::$db->exec("UPDATE bdus_files SET filename = 'zeta' WHERE id = 801");
        static::$db->exec("UPDATE bdus_files SET filename = 'alpha' WHERE id = 802");

        $ids = array_column($this->fileCall('getFiles', ['ids_only' => 1, 'sort_field' => 'filename', 'sort_dir' => 'asc'])['files'], 'id');

        $this->assertSame([802, 801], array_values(array_intersect($ids, [801, 802])));
    }

    public function testTheListCanBeFilteredOnPublic(): void
    {
        static::$db->exec('UPDATE bdus_files SET public = 1 WHERE id = 801');
        static::$db->exec('UPDATE bdus_files SET public = 0 WHERE id = 802');
        $ids = fn(array $get) => array_values(array_intersect(array_column($this->fileCall('getFiles', $get)['files'], 'id'), [801, 802]));

        $this->assertSame([801], $ids(['public' => '1']));
        $this->assertSame([802], $ids(['public' => '0']));
        $this->assertSame([802, 801], $ids([]), 'no filter: both');
    }

    // ── what a request can never write ────────────────────────────────────────

    public function testTheColumnsOfTheBinaryAreNeverWrittenFromARequest(): void
    {
        $before = $this->row(801);

        $res = $this->fileCall('updateFile', ['fileId' => 801], [
            'uuid' => '11111111-1111-4111-8111-111111111111', 'ext' => 'html', 'creator' => 999, 'id' => 5000, 'description' => 'ok',
        ]);

        $this->assertSame('ok_file_updated', $res['code'], json_encode($res));
        $after = $this->row(801);
        foreach (['uuid', 'ext', 'creator', 'id'] as $k) {
            $this->assertSame($before[$k], $after[$k], "$k must not change");
        }
        $this->assertSame('ok', $after['description']);
    }

    public function testTheGenericRecordPathAlsoNeverWritesThem(): void
    {
        $before = $this->row(801);
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb' => 'bdus_files', 'id' => 801, 'core' => ['uuid' => '22222222-2222-4222-8222-222222222222', 'ext' => 'exe', 'keywords' => 'k'],
        ]);

        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame($before['uuid'], $this->row(801)['uuid']);
        $this->assertSame($before['ext'], $this->row(801)['ext']);
        $this->assertSame('k', $this->row(801)['keywords']);
    }

    public function testAFileRowCannotBeCreatedOnItsOwn(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['tb' => 'bdus_files', 'core' => ['filename' => 'x', 'ext' => 'png']]);
        $res  = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('files_are_uploaded', $res['code'], json_encode($res));
        $this->assertSame(0, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_files WHERE filename = 'x'", [], 'read')[0]['n']);
    }

    public function testAFileCannotBeErasedAsARecord(): void
    {
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'bdus_files', 'id' => 801]), 'erase');

        $this->assertSame('files_are_deleted_as_files', $res['code']);
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id = 801', [], 'read')[0]['n']);
    }

    public function testAnEmptyFilenameIsStillRefusedWithItsOwnCode(): void
    {
        $this->assertSame('filename_required', $this->fileCall('updateFile', ['fileId' => 801], ['filename' => '  '])['code']);
    }

    // ── what the configuration may not do ─────────────────────────────────────

    public function testTheTableCannotBeRenamedDeactivatedOrDeleted(): void
    {
        $this->assertSame('not_found', $this->cfgCall('rename_tb', ['tb' => self::TB], ['new_name' => 'files2'])['code']);
        $this->assertSame('not_found', $this->cfgCall('delete_tb', ['tb' => self::TB])['code']);
        $this->assertSame('not_found', $this->cfgCall('setTableActive', ['tb' => self::TB], ['active' => false])['code']);
        $this->assertContains(self::TB, static::$cfg->get('tables.*.name'));
    }

    public function testASystemFieldCannotBeDeletedOrRenamed(): void
    {
        foreach (['uuid', 'ext', 'filename', 'description', 'public', 'creator', 'id'] as $fld) {
            $this->assertSame('system_field_not_deletable',
                $this->cfgCall('delete_column', ['tb' => self::TB, 'fld' => $fld])['code'], "delete $fld");
        }
        foreach (['uuid', 'filename', 'description'] as $fld) {
            $this->assertSame('system_field_not_renamable',
                $this->cfgCall('rename_column', ['tb' => self::TB, 'fld' => $fld], ['new_name' => 'other'])['code'], "rename $fld");
        }
        $cols = array_column((new \DB\Inspect(static::$db))->tableColumns(self::TB), 'fld');
        foreach (['uuid', 'ext', 'filename', 'description', 'public'] as $fld) {
            $this->assertContains($fld, $cols, "$fld is still a column");
        }
    }

    public function testASystemFieldKeepsItsNameAndTypeWhenItsPropertiesAreSaved(): void
    {
        $res = $this->cfgCall('save_fld_properties', ['tb' => self::TB, 'fld' => 'uuid'], [
            'name' => 'renamed', 'type' => 'long_text', 'db_type' => 'INTEGER', 'label' => 'Identifier',
        ]);

        $this->assertSame('ok_cfg_data_updated', $res['code'], json_encode($res));
        $this->assertSame('uuid', static::$cfg->get('tables.bdus_files.fields.uuid.name'));
        $this->assertSame('text', static::$cfg->get('tables.bdus_files.fields.uuid.type'));
        $this->assertSame('TEXT', static::$cfg->get('tables.bdus_files.fields.uuid.db_type'));
        $this->assertSame('Identifier', static::$cfg->get('tables.bdus_files.fields.uuid.label'), 'the label may change');
    }

    public function testACustomFieldCannotTakeAKeyOfTheList(): void
    {
        foreach (['links', 'is_image', 'can_update', 'can_delete'] as $name) {
            $this->assertSame('invalid_db_name',
                $this->cfgCall('add_new_fld', ['tb' => self::TB], ['name' => $name, 'type' => 'text', 'db_type' => 'TEXT'])['code'], $name);
        }
    }

    public function testTheConfigurationScreensShowTheFilesTableAsASystemOne(): void
    {
        $list = array_column($this->cfgCall('getTableList')['tables'], null, 'name');
        $this->assertTrue($list[self::TB]['system']);
        $this->assertFalse($list['items']['system']);
        $this->assertArrayNotHasKey('bdus_users', $list, 'the other system tables stay out');

        $table = $this->cfgCall('getTableConfig', ['tb' => self::TB])['table'];
        $this->assertTrue($table['system']);
        $flds = array_column($table['fields'], null, 'name');
        $this->assertTrue($flds['uuid']['system']);
        $this->withLicence();
        $table = $this->cfgCall('getTableConfig', ['tb' => self::TB])['table'];
        $this->assertFalse(array_column($table['fields'], null, 'name')['licence']['system'], 'a custom field is yours');

        $this->assertSame('not_found', $this->cfgCall('getTableConfig', ['tb' => 'bdus_users'])['code']);
    }

    public function testACustomFieldCanBeRemovedAgain(): void
    {
        $this->withLicence();

        $res = $this->cfgCall('delete_column', ['tb' => self::TB, 'fld' => 'licence']);

        $this->assertSame('ok_cfg_column_delete', $res['code'], json_encode($res));
        $this->assertNotContains('licence', array_column((new \DB\Inspect(static::$db))->tableColumns(self::TB), 'fld'));
    }
}
