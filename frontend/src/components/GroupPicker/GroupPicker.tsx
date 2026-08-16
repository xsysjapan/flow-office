import { useGroups } from '../../hooks/useGroups'
import { NativeSelect } from '../ui/native-select'

export interface GroupPickerProps {
  id: string
  value: string | undefined
  onChange: (groupId: string | undefined) => void
}

/** グループを1つ選ぶ入力(グループ手動付与の対象選択で使用)。グループ一覧は小規模想定のため単純なselect。 */
export function GroupPicker({ id, value, onChange }: GroupPickerProps) {
  const { data: groups, isLoading } = useGroups()

  return (
    <NativeSelect
      id={id}
      value={value ?? ''}
      disabled={isLoading}
      onChange={(e) => onChange(e.target.value || undefined)}
    >
      <option value="">選択してください</option>
      {(groups ?? []).map((group) => (
        <option key={group.id} value={group.id}>
          {group.name}
        </option>
      ))}
    </NativeSelect>
  )
}
