---
title: Removing the data levels (plan)
---

# Removing the data levels: plan <Badge type="warning" text="due 2027-05-25" />

Since **5.28.0** access is given only through [groups](./groups-permissions): level **Enter**
(39) plus a group; new users, API keys and every seed use them. The levels **Writer (20)**,
**Self writer (25)** and **Reader (30)** are *deprecated but kept*, with their tests, so apps
that have not moved keep working. This page is the plan for the release that removes them
(a major one: it drops a documented behaviour). Decided 2026-11-25; to be picked up on or
after **2027-05-25**.

What stays: **Super admin (1)**, **Admin (10)**, **Enter (39)** and **Pending (40)**. They
gate configuration, users, backups and the lock bypass, which are not data rules.

## 0. Go / no-go (nothing is removed before this holds)

1. On **every real application** (PAThs, CAJ, the demo, anything on a private VM) run
   `php bin/groups-equivalence.php --app <slug>` and read `GET /api/users` → `data_levels`:
   **0 users and 0 keys** at 20/25/30. Move the rest with *Move everyone equivalent…*
   (Users page) or `POST /api/levels/move-to-groups`; anyone refused (`not_equivalent`) needs
   a group of their own that gives what they really use, decided with the person.
2. Ask the maintainers of external clients (scripts, MCP clients) whether any API key is still
   at 25/30 (`GET /api/api-keys`).
3. At least one release has shipped with both systems (5.28.0 and every release since).
4. A backup of each application, then the release notes say what the upgrade does.

## 1. Data: one migration, with a way out

A new migration `M0xx` (next free number at that date), run by the normal minor upgrade:

- every user still at 20/25/30 → level 39 + the system group, **using `Auth\LevelMigration`**
  (it refuses unless the group is equivalent; a refused user is **not** left at a removed
  level: the migration must stop with a clear message listing them, before touching anyone);
- every ungrouped key at 20/25/30 → 39 + group, the same way; a key that already has its own
  group is listed, never silently changed;
- then drop `bdus_users.legacy_privilege`, `legacy_group_id` and `bdus_api_keys.legacy_privilege`
  (added by **M058**), and take them out of `Structure/users.json` / `api_keys.json` and out of
  `Manage::USERS_LATE_COLUMNS`. Dropping the columns removes the undo: say so in the notes.

## 2. Backend: delete, do not hide

| Where | What goes |
|---|---|
| `lib/Auth/Authorization.php` | `UPDATE`, `CREATE`, `READ` constants, `DATA_LEVELS`; the level branches of `can()`, `canOnSomeTable()`, `canReadAll()`, `tableAbilities()`; the `privilege()` labels for 20/25/30 |
| `lib/Auth/Access.php` | `tierGrantsAll()`, `tierGrantsOwn()` and every call (lines ~146–340, ~442–445): rules decide everything; `readsEverything()` loses its "tier read" shortcut |
| `lib/Bdus/Router.php` | the `'read'`/`'edit'` rows of `ROUTE_PRIVILEGE` (about 83 routes) become `'group:read'`-style requirements decided by rules, or `admin`; `apiKeyMayReach()` loses the tier branches; keep `RouterGroupKeyTest` meaningful |
| `controllers/Api.php`, `lib/Auth/ApiKeyAuth.php` | `checkAccess()` accepts 10 and 39 only; the default key level becomes 39 + a required group; drop the `READ` default |
| `controllers/User.php` | `data_levels`, `countDataLevelKeys()`, the `legacy_privilege` field, the level options of the user form; `MyHistory.php` level-based branch |
| `lib/Auth/LevelMigration.php`, `controllers/LevelMigration.php`, `lib/Auth/Verify/LevelGroupComparison.php`, `bin/groups-equivalence.php` | keep **until the migration above has shipped**, then delete with their routes, `openapi.yaml` entries, `Router` rows and tests; the migration needs them, so they go in the release *after* it, or in the same one if the migration inlines the check |
| `M050_AddDefaultGroups` | stays: the system groups are the way to give the usual roles |

## 3. Frontend and locale

`commands/privilege.js` (`WRITER`, `SELF`, `READER` thresholds), `navItems.js` (`minPrivilege`
levels), `AppNavMenu.vue`, `useCommands.js`, `router/index.js` (the level OR group guard
becomes group only), `stores/auth.js`, `HomeView.vue`, `RecordVersionsDrawer.vue`,
`GroupsPanel.vue`; `UsersView.vue` (deprecation banner, *deprecated* and *moved* tags, move
buttons, `LevelMoveModal.vue`), `ApiKeysPanel.vue`, `components/users/BuiltinTiers.vue`,
`composables/useLevelMove.js`; locale keys `grp_level_*`, `grp_levels_banner`, `grp_lvl_*`,
`lvl_*`, `priv_edit`, `priv_read` (en and it), plus the codes only the move used.

## 4. Tests, seeds, docs

- **Delete or rewrite as group tests:** hurl phases 09, 25 (keys at 25/30), 45, 47 (G47
  Author at 25), 51 (the level half of the declared-behaviour comparison), 52, 59 (whole
  phase), 58 if it only compares with levels; PHPUnit `ApiKeyEditTest`, `ApiKeyGroupTest`,
  `ChartCtrlTest`, `ImportCtrlTest`, `McpRsTest`, `McpWriteToolsTest`, `RecordCtrl*Test`,
  `UserCtrlTest`, `LevelMigrationTest`, `LevelGroupComparisonTest`,
  `AuthorizationReadOnlyTablesTest`, `EnterGroup*Test`, the Login test for M058 columns;
  `bdus-app/tests/navAllowed.test.mjs`. Re-run `grep -rE "privilege.{0,12}(20|25|30)"` first:
  the list above is the one at 2026-11-25.
- **Seeds** already use groups (5.28.0): check `19_seed_demo.hurl` and `seed-demo.sh` stay clean.
- **Docs:** `guide/setup/users.md` (levels table, deprecation box), `guide/setup/groups.md`
  (the *Default groups* warning and *Moving users and keys…*), `guide/usage/backup.md`,
  `guide/api` (key levels), `dev/groups-permissions.md`, `dev/migrations.md`, `openapi.yaml`
  enums, `llms.txt`, `evaluate.md`, `skills/bradypus-bootstrap/SKILL.md`; CHANGELOG with an
  **upgrade note** and the major version bump.

## 5. Order of work (one intervention at a time)

1. Go / no-go checks on real apps (section 0).
2. Migration + tests on all three engines (section 1), with a dry run against copies of the
   real databases.
3. Backend deletions (section 2), full PHPUnit + hurl on sqlite, pgsql, mysql.
4. Frontend and locale (section 3), `npm test`, browser check of Users, API keys, menu.
5. Tests, docs, release notes (section 4); then the major release.

Keep each step a separate commit so a regression can be bisected.
