/** backend/app/Models/Role.php の定数と対応するロールコード。 */
export const ROLE = {
  EMPLOYEE: 'employee',
  BACKOFFICE_STAFF: 'backoffice_staff',
  ACCOUNTING_STAFF: 'accounting_staff',
  GENERAL_AFFAIRS_STAFF: 'general_affairs_staff',
  HR_STAFF: 'hr_staff',
  ADMIN: 'admin',
} as const

export type RoleCode = (typeof ROLE)[keyof typeof ROLE]

export function hasAnyRole(userRoles: string[] | undefined, roles: RoleCode[]): boolean {
  return roles.some((role) => (userRoles ?? []).includes(role))
}
