import { apiFetch } from './client'
import type { GroupMember, GroupOption } from './types'

/** グループ手動付与の対象選択(GroupPicker)向けの軽量なグループ一覧。 */
export function fetchGroups(): Promise<GroupOption[]> {
  return apiFetch('/groups')
}

/** 指定グループの所属メンバー一覧(手動付与対象のプレビュー表示用)。 */
export function fetchGroupMembers(groupId: string): Promise<GroupMember[]> {
  return apiFetch(`/groups/${groupId}/members`)
}
