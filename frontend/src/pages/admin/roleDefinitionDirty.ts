export interface RoleDefinitionValues {
  name: string
  description: string
  status: string
  permissionIds: number[]
  featureIds: number[]
}

const sameIds = (a: number[], b: number[]) =>
  a.length === b.length && [...a].sort().join(',') === [...b].sort().join(',')

/** ロール定義フォームに、読み込んだ値(保存後は送った値)から変更があるか。 */
export function isRoleDefinitionDirty(base: RoleDefinitionValues, current: RoleDefinitionValues): boolean {
  return (
    base.name !== current.name ||
    base.description !== current.description ||
    base.status !== current.status ||
    !sameIds(base.permissionIds, current.permissionIds) ||
    !sameIds(base.featureIds, current.featureIds)
  )
}
