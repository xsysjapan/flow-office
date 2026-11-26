export interface GroupFormValues {
  group_type_id: string
  name: string
  parent_group_id: string
  status: string
  initial_role_id: string
}

/** グループの作成・編集フォームに、読み込んだ値(新規なら初期値)から変更があるか。 */
export function isGroupFormDirty(base: GroupFormValues, current: GroupFormValues): boolean {
  return (
    base.group_type_id !== current.group_type_id ||
    base.name !== current.name ||
    base.parent_group_id !== current.parent_group_id ||
    base.status !== current.status ||
    base.initial_role_id !== current.initial_role_id
  )
}
