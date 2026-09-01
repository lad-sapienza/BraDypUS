/**
 * UAC privilege thresholds — mirrors lib/Auth/Authorization.php's PRIVILEGE_MAP
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
  ENTER:       38, // everyone except 'waiting' (40)
}

export function hasPrivilege(userPrivilegeValue, threshold) {
  return (userPrivilegeValue ?? 99) <= threshold
}
