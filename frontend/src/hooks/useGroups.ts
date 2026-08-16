import { useQuery } from '@tanstack/react-query'
import { fetchGroupMembers, fetchGroups } from '../api/groups'

const GROUPS_KEY = ['groups', 'list']

/** グループ手動付与のGroupPicker向け。 */
export function useGroups() {
  return useQuery({ queryKey: GROUPS_KEY, queryFn: fetchGroups })
}

/** 選択中グループの所属メンバー一覧(手動付与対象のプレビュー表示用)。groupIdが未確定の間は取得しない。 */
export function useGroupMembers(groupId: string | undefined) {
  return useQuery({
    queryKey: ['groups', 'members', groupId ?? ''],
    queryFn: () => fetchGroupMembers(groupId as string),
    enabled: Boolean(groupId),
  })
}
