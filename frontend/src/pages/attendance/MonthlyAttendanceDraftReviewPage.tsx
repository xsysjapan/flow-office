import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  useConfirmMonthlyAttendanceDraftField,
  useMonthlyAttendanceDraft,
  useMonthlyAttendanceDraftFields,
  useSubmitMonthlyAttendanceDraft,
  useValidateMonthlyAttendanceDraft,
} from '../../hooks/useMonthlyAttendanceDrafts'
import { fieldSourceTypeLabel, monthlyDraftStatusLabel, parseFieldProvenanceName } from '../../utils/statusLabels'

/**
 * UC-R001/UC-R002: 月次勤怠下書きをレビューする。AI推定値(field_provenances.source_type=
 * ai_inferred)は本人が内容を確認して確定させるまで申請できない(docs/03-architecture.md 3.7、
 * docs/26-usecases-monthly-import.md)。値そのものの修正は既存の日次勤怠編集画面で行う。
 */
export function MonthlyAttendanceDraftReviewPage() {
  const { id } = useParams<{ id: string }>()
  const draftId = Number(id)

  const { data: draft, isLoading: isLoadingDraft, error: draftError } = useMonthlyAttendanceDraft(draftId)
  const { data: fields, isLoading: isLoadingFields, error: fieldsError } = useMonthlyAttendanceDraftFields(draftId)
  const confirmField = useConfirmMonthlyAttendanceDraftField(draftId)
  const validateDraft = useValidateMonthlyAttendanceDraft(draftId)
  const submitDraft = useSubmitMonthlyAttendanceDraft(draftId)

  const [approverUserId, setApproverUserId] = useState<number>()

  if (isLoadingDraft || isLoadingFields) return <LoadingState />
  if (draftError) return <ErrorMessage error={draftError} fallback="月次勤怠下書きの取得に失敗しました。" />
  if (fieldsError) return <ErrorMessage error={fieldsError} fallback="項目一覧の取得に失敗しました。" />
  if (!draft) return null

  const statusMeta = monthlyDraftStatusLabel(draft.status)
  const list = fields ?? []
  const unconfirmedAiInferred = list.filter((f) => f.source_type === 'ai_inferred' && !f.confirmed_at)
  const canSubmit = draft.status === 'ready_to_submit'

  return (
    <Card title={`月次勤怠下書き: ${draft.target_month}`}>
      {confirmField.error && <ErrorMessage error={confirmField.error} />}
      {validateDraft.error && <ErrorMessage error={validateDraft.error} />}
      {submitDraft.error && <ErrorMessage error={submitDraft.error} />}
      {submitDraft.isSuccess && <Badge tone="success">申請しました</Badge>}

      <div className="mb-4 flex items-center gap-3">
        <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>
        <span className="text-sm text-muted-foreground">version: {draft.version}</span>
      </div>

      {unconfirmedAiInferred.length > 0 && (
        <p className="mb-4 text-sm text-warning">
          AIが推定した値が{unconfirmedAiInferred.length}件あります。内容を確認してから確定してください。値を修正したい場合は、
          対象日の日次勤怠編集画面(下表の日付リンク)から修正してください。
        </p>
      )}

      {list.length === 0 ? (
        <p className="mb-4 text-sm text-muted-foreground">確認が必要な項目はありません。</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>日付</TableHead>
              <TableHead>項目</TableHead>
              <TableHead>出所</TableHead>
              <TableHead>確認状況</TableHead>
              <TableHead>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.map((field) => {
              const { date, fieldLabel } = parseFieldProvenanceName(field.field_name)
              const sourceMeta = fieldSourceTypeLabel(field.source_type)
              const needsConfirmation = field.source_type === 'ai_inferred' && !field.confirmed_at

              return (
                <TableRow key={field.id}>
                  <TableCell>
                    <Link to={`/attendance/days/${date}`} className="text-foreground hover:text-primary hover:underline">
                      {date}
                    </Link>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{fieldLabel}</TableCell>
                  <TableCell>
                    <Badge tone={sourceMeta.tone}>{sourceMeta.label}</Badge>
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {field.confirmed_at ? new Date(field.confirmed_at).toLocaleString('ja-JP') : '未確認'}
                  </TableCell>
                  <TableCell>
                    {needsConfirmation && (
                      <Button
                        size="sm"
                        variant="secondary"
                        isLoading={confirmField.isPending}
                        onClick={() => confirmField.mutate(field.id)}
                      >
                        内容を確認した
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}

      <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-border pt-4">
        <Button variant="secondary" isLoading={validateDraft.isPending} onClick={() => validateDraft.mutate()}>
          検証する
        </Button>

        <FormField label="承認者" htmlFor="monthly-draft-approver">
          <UserPicker id="monthly-draft-approver" value={approverUserId} onChange={setApproverUserId} />
        </FormField>

        <Button
          isLoading={submitDraft.isPending}
          disabled={!canSubmit || !approverUserId}
          onClick={() => approverUserId && submitDraft.mutate(approverUserId)}
        >
          申請する
        </Button>
      </div>
    </Card>
  )
}
