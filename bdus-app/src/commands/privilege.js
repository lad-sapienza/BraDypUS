/**
 * Privilege thresholds — mirrors lib/Auth/Authorization.php's PRIVILEGE_MAP
 * (bdus-api). Lower numeric value = higher privilege. A user passes a
 * threshold when their privilege_value is <= the threshold.
 *
 * Kept here (not derived from the JWT) because the frontend needs to know,
 * ahead of any API call, which nav items and commands a user can actually
 * use — so nothing is offered that the backend will then reject.
 */
export const PRIVILEGE = {
  SUPER_ADMIN: 1,
  ADMIN:       10,
  WRITER:      20,
  SELF_WRITER: 25,
  READER:      30,
  ENTER:       39, // everyone except 'waiting' (40)
}

export function hasPrivilege(userPrivilegeValue, threshold) {
  return (userPrivilegeValue ?? 99) <= threshold
}

/**
 * Nav-item gate: the level threshold OR, for data screens (item.groupAction),
 * a group rule. A user at level Enter (39) gets their data permissions from
 * groups, so the tables the API lists for them (`can: {create, read, update,
 * delete}` per table) decide. `groupAction` is an action name or a list of
 * names (any of them). Mirrors Authorization::can(): level first, then rules.
 */
export function navAllowed(item, privilegeValue, tables = []) {
  if (hasPrivilege(privilegeValue, item.minPrivilege)) return true
  if (!item.groupAction || !hasPrivilege(privilegeValue, PRIVILEGE.ENTER)) return false
  const actions = Array.isArray(item.groupAction) ? item.groupAction : [item.groupAction]
  return tables.some(tb => actions.some(a => tb?.can?.[a] === true))
}
