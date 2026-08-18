import { useEffect, useRef, useState } from 'react'
import { TriangleAlert } from 'lucide-react'
import { useGroupMembers } from '../../hooks/useGroups'
import { GroupPicker } from '../GroupPicker/GroupPicker'
import { LoadingState } from '../LoadingState/LoadingState'
import { MultiUserPicker } from '../MultiUserPicker/MultiUserPicker'
import { Button } from '../Button/Button'

export type GrantTargetMode = 'individual' | 'group'

export interface GrantTargetPickerProps {
  idPrefix: string
  /** モードに関わらず、確定した付与対象のユーザーIDリスト(重複排除済み)と、
   *  各IDの表示ラベル(氏名・メールアドレス。結果表示用)を渡す。 */
  onResolvedChange: (userIds: string[], mode: GrantTargetMode, labels: Record<string, string>) => void
  /** 値が変わるたびに、選択状態を「個人を指定」モード・`resetIndividualIds`(省略時は空)へ
   *  リセットする。一括付与後、全成功なら空へ、一部失敗なら失敗した対象だけを残して
   *  再送しやすくする用途。 */
  resetSignal?: unknown
  resetIndividualIds?: string[]
}

/**
 * 有給/特別休暇/代休の手動付与フォームで共通利用する対象選択UI。
 * 「個人を指定」(複数選択)と「グループを指定」(所属メンバー全員)を切り替えられる。
 * どちらのモードでも、確定した対象ユーザーIDのリストを親フォームへ渡す
 * (親フォームはどちらのモードだったかを意識せずに送信できる)。
 */
export function GrantTargetPicker({
  idPrefix,
  onResolvedChange,
  resetSignal,
  resetIndividualIds,
}: GrantTargetPickerProps) {
  const [mode, setMode] = useState<GrantTargetMode>('individual')
  const [individualIds, setIndividualIds] = useState<string[]>([])
  const [individualLabels, setIndividualLabels] = useState<Record<string, string>>({})
  const [groupId, setGroupId] = useState<string | undefined>(undefined)
  const previousResetSignal = useRef(resetSignal)

  useEffect(() => {
    if (resetSignal === previousResetSignal.current) return
    previousResetSignal.current = resetSignal
    setMode('individual')
    setIndividualIds(resetIndividualIds ?? [])
    setGroupId(undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resetSignal])

  const { data: groupMembers, isLoading: isLoadingMembers } = useGroupMembers(mode === 'group' ? groupId : undefined)

  const resolvedIds =
    mode === 'individual' ? individualIds : Array.from(new Set((groupMembers ?? []).map((member) => member.user_id)))

  const resolvedLabels =
    mode === 'individual'
      ? individualLabels
      : Object.fromEntries((groupMembers ?? []).map((member) => [member.user_id, `${member.name}(${member.email})`]))

  useEffect(() => {
    onResolvedChange(resolvedIds, mode, resolvedLabels)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, individualIds, individualLabels, groupMembers])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2" role="group" aria-label="付与対象の指定方法">
        <Button
          type="button"
          size="sm"
          variant={mode === 'individual' ? 'primary' : 'secondary'}
          aria-pressed={mode === 'individual'}
          onClick={() => setMode('individual')}
        >
          個人を指定
        </Button>
        <Button
          type="button"
          size="sm"
          variant={mode === 'group' ? 'primary' : 'secondary'}
          aria-pressed={mode === 'group'}
          onClick={() => setMode('group')}
        >
          グループを指定
        </Button>
      </div>

      {mode === 'individual' ? (
        <div className="flex flex-col gap-1">
          <MultiUserPicker
            id={`${idPrefix}-users`}
            value={individualIds}
            onChange={setIndividualIds}
            onLabelsChange={setIndividualLabels}
          />
          <p className="text-xs text-muted-foreground">{individualIds.length}名を選択中</p>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          <GroupPicker id={`${idPrefix}-group`} value={groupId} onChange={setGroupId} />
          {groupId !== undefined &&
            (isLoadingMembers ? (
              <LoadingState />
            ) : (groupMembers ?? []).length === 0 ? (
              <p className="flex items-center gap-1.5 text-xs text-warning">
                <TriangleAlert className="size-3.5 shrink-0" />
                このグループには所属メンバーがいません。付与対象を選び直してください。
              </p>
            ) : (
              <div className="rounded-md border border-border">
                <p className="border-b border-border bg-muted px-3 py-2 text-xs font-semibold text-foreground">
                  このグループの{groupMembers?.length ?? 0}名に付与されます
                </p>
                <ul className="max-h-48 divide-y divide-border overflow-y-auto">
                  {(groupMembers ?? []).map((member) => (
                    <li key={member.user_id} className="px-3 py-1.5 text-sm text-foreground">
                      {member.name}
                      <span className="ml-2 text-xs text-muted-foreground">{member.email}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
        </div>
      )}
    </div>
  )
}
