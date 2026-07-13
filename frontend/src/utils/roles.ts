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

export const ROLE_LABEL: Record<RoleCode, string> = {
  [ROLE.EMPLOYEE]: '一般社員',
  [ROLE.BACKOFFICE_STAFF]: 'バックオフィス担当',
  [ROLE.ACCOUNTING_STAFF]: '経理担当',
  [ROLE.GENERAL_AFFAIRS_STAFF]: '総務担当',
  [ROLE.HR_STAFF]: '人事担当',
  [ROLE.ADMIN]: '管理者',
}

export function hasAnyRole(userRoles: string[] | undefined, roles: RoleCode[]): boolean {
  return roles.some((role) => (userRoles ?? []).includes(role))
}
