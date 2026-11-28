import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { BulkActionDialog, type BulkActionResult } from '../../components/BulkActionDialog/BulkActionDialog'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { FormField } from '../../components/FormField/FormField'
import { GrantTargetPicker } from '../../components/GrantTargetPicker/GrantTargetPicker'
import { LeaveUsageList } from '../../components/LeaveUsageList/LeaveUsageList'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { ChangeGrantDaysButton } from '../../components/ChangeGrantDaysButton/ChangeGrantDaysButton'
import { RevokeGrantButton } from '../../components/RevokeGrantButton/RevokeGrantButton'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Input } from '../../components/ui/input'
import {
  useAdminCancelCompensatoryLeaveRequest,
  useCompensatoryLeaveGrantsForUser,
  useCompensatoryLeaveUsagesForUser,
  useBulkGrantCompensatoryLeave,
  useRevokeCompensatoryLeaveGrant,
} from '../../hooks/useCompensatoryLeave'
import { notify } from '../../lib/notify'
import { isValidUuidParam } from '../../utils/searchParamValidators'

function ManualGrantCard() {
  const [targetIds, setTargetIds] = useState<string[]>([])
  const [targetLabels, setTargetLabels] = useState<Record<string, string>>({})
  const [workDate, setWorkDate] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantReason, setGrantReason] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  // 再送を二重付与にしない識別子。確認ダイアログを開くたびに作り、同じダイアログでの再実行(通信エラー後など)では同じ値を送る。
  const [requestId, setRequestId] = useState(() => crypto.randomUUID())

  const bulkGrant = useBulkGrantCompensatoryLeave()
  const revokeGrant = useRevokeCompensatoryLeaveGrant()

  const singleTargetUserId = targetIds.length === 1 ? targetIds[0] : undefined
  const { data: userGrants, isLoading: isLoadingUserGrants } = useCompensatoryLeaveGrantsForUser(singleTargetUserId ?? '')

  const openGrantDialog = () => {
    setRequestId(crypto.randomUUID())
    setDialogOpen(true)
  }

  /** 全部か無しか: 一括のAPIを1回呼ぶ。1人でも付与できないと422で何も付与されず、ダイアログが失敗の一覧を出して選択を残す。 */
  async function executeGrant(): Promise<BulkActionResult> {
    const response = await bulkGrant.mutateAsync({
      request_id: requestId,
      user_ids: targetIds,
      work_date: workDate,
      expires_on: expiresOn || undefined,
      grant_reason: grantReason || undefined,
    })
    return { succeededIds: response.data.map((item) => item.user_id), failures: [] }
  }

  const clearForm = () => {
    setWorkDate('')
    setExpiresOn('')
    setGrantReason('')
  }

  return (
    <Card title="手動付与">
      <FormField label="付与対象" htmlFor="compensatory-leave-grant-target-users" required>
        <GrantTargetPicker
          idPrefix="compensatory-leave-grant-target"
          allowGroup={false}
          onResolvedChange={(ids, _mode, labels) => {
            setTargetIds(ids)
            setTargetLabels(labels)
          }}
        />
      </FormField>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="休日出勤の実績日" htmlFor="compensatory-leave-grant-work-date" required>
          <DatePicker
            id="compensatory-leave-grant-work-date"
            value={workDate || undefined}
            onChange={(date) => setWorkDate(date ?? '')}
          />
        </FormField>

        <FormField label="失効日(空欄なら失効しない)" htmlFor="compensatory-leave-grant-expires-on">
          <DatePicker
            id="compensatory-leave-grant-expires-on"
            value={expiresOn || undefined}
            onChange={(date) => setExpiresOn(date ?? '')}
          />
        </FormField>

        <FormField label="付与理由" htmlFor="compensatory-leave-grant-reason">
          <Input id="compensatory-leave-grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
        </FormField>
      </div>

      <p className="mt-2 text-xs text-muted-foreground">
        付与日数はサーバー側で実績日から自動的に算出されます。指定した日が休日出勤の実績でない人が1人でもいると、誰にも付与されません。
      </p>

      <div className="mt-4 flex flex-col items-start gap-1">
        <Button disabled={targetIds.length === 0 || !workDate} onClick={openGrantDialog}>
          {targetIds.length}名に付与する
        </Button>
        {targetIds.length === 0 ? (
          <p className="text-xs text-muted-foreground">付与対象を選択してください。</p>
        ) : !workDate ? (
          <p className="text-xs text-muted-foreground">休日出勤の実績日を選択してください。</p>
        ) : null}
      </div>

      <BulkActionDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        title={`${targetIds.length}名に代休を付与しますか?`}
        summary={`${targetIds.length}名に、休日出勤の実績日${workDate}分の代休を付与します。`}
        description="付与日数は実績日から自動的に算出されます。1人でも付与できないと、誰にも付与しません。"
        confirmLabel="付与する"
        resultTitle="付与の結果"
        resultMode="allOrNothing"
        itemLabels={targetLabels}
        onExecute={executeGrant}
        onSucceeded={clearForm}
        successMessage={(count) => `${count}名に代休を付与しました`}
      />

      {singleTargetUserId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の代休付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <EmptyState title="代休の付与はまだありません。" description="上のフォームから付与すると、ここに一覧が表示されます。" />
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm text-foreground">
                  <span>
                    {grant.work_date}({grant.source === 'manual' ? '手動' : '自動'}) / 残{grant.remaining_days}日
                    {grant.status === 'cancelled' && <Badge tone="neutral">取消済み</Badge>}
                  </span>
                  {grant.status !== 'cancelled' && (
                    <div className="flex items-center gap-2">
                    <ChangeGrantDaysButton
                      id={`change-days-${grant.id}`}
                      grantedOn={grant.work_date}
                      currentDays={grant.granted_days}
                      usedDays={grant.used_days}
                      onChange={async () => undefined}
                      disabled
                      disabledReason="代休の日数は休日出勤の勤怠から決まるため、ここでは変更できません。勤怠を修正してください。"
                    />
                    <RevokeGrantButton
                      id={`revoke-reason-${grant.id}`}
                      grantedOn={grant.work_date}
                      title="代休付与を取り消しますか?"
                      description={`${grant.work_date}分の代休付与(${grant.granted_days}日)を取り消します。この操作は元に戻せません。`}
                      isPending={revokeGrant.isPending}
                      error={revokeGrant.error}
                      onRevoke={(reason) => revokeGrant.mutateAsync({ grantId: grant.id, reason })}
                      disabled={grant.status !== 'confirmed' || grant.used_days > 0}
                      disabledReason={
                        grant.status !== 'confirmed'
                          ? '月次の提出で確定する前の代休は取り消せません。'
                          : '既に消化された分は取り消せません。'
                      }
                    />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

function CompensatoryLeaveUsageCard() {
  const [searchParams, setSearchParams] = useSearchParams()
  const userIdParam = searchParams.get('userId')
  const userId = isValidUuidParam(userIdParam) ? (userIdParam ?? undefined) : undefined
  const { data, isLoading, error } = useCompensatoryLeaveUsagesForUser(userId ?? '')
  const adminCancel = useAdminCancelCompensatoryLeaveRequest(userId ?? '')

  useEffect(() => {
    if (userIdParam === null || isValidUuidParam(userIdParam)) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('userId')
        return next
      },
      { replace: true },
    )
  }, [userIdParam, setSearchParams])

  const handleUserChange = (value: string | undefined) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) {
          next.set('userId', value)
        } else {
          next.delete('userId')
        }
        return next
      },
      { replace: true },
    )
  }

  const isEmpty = userId !== undefined && !isLoading && !error && (data?.length ?? 0) === 0

  return (
    <Card title="使用状況">
      <div className="max-w-sm">
        <FormField label="対象社員" htmlFor="compensatory-leave-usage-user">
          <UserPicker id="compensatory-leave-usage-user" value={userId} onChange={handleUserChange} />
        </FormField>
      </div>

      {userId === undefined ? (
        <EmptyState title="対象社員を選択してください。" description="社員を選ぶと、その社員の代休使用状況を確認できます。" />
      ) : isEmpty ? (
        <EmptyState
          title="代休の使用状況はまだありません。"
          description="対象社員が代休を消化すると、ここに消化記録が表示されます。"
          action={
            <Button variant="secondary" onClick={() => handleUserChange(undefined)}>
              社員選択をクリア
            </Button>
          }
        />
      ) : (
        <LeaveUsageList
          usages={data?.map((usage) => ({
            id: usage.id,
            usedOn: usage.used_on,
            usedDays: usage.used_days,
            usedMinutes: usage.used_minutes,
            usageType: usage.usage_type,
            requestStatus: usage.request_status,
            requestId: usage.compensatory_leave_request_id,
          }))}
          isLoading={isLoading}
          error={error}
          errorFallback="代休の使用状況の取得に失敗しました。"
          onCancelRequest={async (requestId) => {
            await adminCancel.mutateAsync(requestId)
            notify.success('代休申請を取り消しました')
          }}
          isCancelling={adminCancel.isPending}
          cancelError={adminCancel.error}
        />
      )}
    </Card>
  )
}

/**
 * 代休の手動付与・履歴確認(管理者・人事向け)。有給・特別休暇と異なり自動付与ルール・
 * 種類マスタは無く、休日出勤の実績日を指定した手動付与のみを扱う。付与状況一覧(対象社員
 * 選択時に表示される、現在の残数確認用)に加え、対象社員を選んで付与・申請・承認・消化等の
 * イベント履歴を時系列で確認できる履歴カードを提供する(有給・特別休暇の履歴カードと同じ形)。
 */
export function CompensatoryLeaveAdminPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader description="休日出勤の実績日を指定して代休を手動で付与し、使用状況を確認できます。" />
      <ManualGrantCard />
      <CompensatoryLeaveUsageCard />
    </div>
  )
}
