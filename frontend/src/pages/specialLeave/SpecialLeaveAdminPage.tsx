import { useState } from 'react'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import {
  useCreateSpecialLeaveGrantRule,
  useCreateSpecialLeaveType,
  useGrantSpecialLeave,
  useSpecialLeaveGrantRules,
  useSpecialLeaveGrantsForUser,
  useSpecialLeaveTypes,
  useUpdateSpecialLeaveType,
} from '../../hooks/useSpecialLeave'

interface StepInput {
  continuous_service_months: number
  grant_days: number
}

function SpecialLeaveTypesCard() {
  const { data: types, isLoading, error } = useSpecialLeaveTypes()
  const createType = useCreateSpecialLeaveType()
  const updateType = useUpdateSpecialLeaveType()

  const [name, setName] = useState('')
  const [requiresGrant, setRequiresGrant] = useState(true)

  const handleCreate = () => {
    createType.mutate({ name, requires_grant: requiresGrant }, { onSuccess: () => setName('') })
  }

  return (
    <Card title="特別休暇の種類">
      {error && <ErrorMessage error={error} fallback="特別休暇種別の取得に失敗しました。" />}
      {createType.error && <ErrorMessage error={createType.error} />}
      {updateType.error && <ErrorMessage error={updateType.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (types ?? []).length === 0 ? (
        <div className="mb-5">
          <EmptyState
            title="特別休暇の種類はまだありません。作成するまで特別休暇メニューは表示されません。"
            description="下のフォームから種類名を入力して追加してください。"
          />
        </div>
      ) : (
        <ul className="mb-5 divide-y divide-border">
          {(types ?? []).map((type) => (
            <li key={type.id} className="flex items-center justify-between gap-3 py-2">
              <div className="flex items-center gap-3">
                <strong className="text-sm font-semibold text-foreground">{type.name}</strong>
                <span className="text-sm text-muted-foreground">{type.is_active ? '有効' : '無効'}</span>
                <span className="text-sm text-muted-foreground">
                  {type.requires_grant ? '要付与' : '付与不要(残数チェックなし)'}
                </span>
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="secondary"
                  isLoading={updateType.isPending}
                  onClick={() =>
                    updateType.mutate({
                      id: type.id,
                      input: { name: type.name, is_active: type.is_active, requires_grant: !type.requires_grant },
                    })
                  }
                >
                  {type.requires_grant ? '付与不要にする' : '要付与に戻す'}
                </Button>
                <Button
                  variant="secondary"
                  isLoading={updateType.isPending}
                  onClick={() =>
                    updateType.mutate({
                      id: type.id,
                      input: { name: type.name, is_active: !type.is_active, requires_grant: type.requires_grant },
                    })
                  }
                >
                  {type.is_active ? '無効にする' : '有効にする'}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-end gap-3">
        <FormField label="種類名(例: 誕生日休暇、代休)" htmlFor="special-leave-type-name">
          <Input id="special-leave-type-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <Button isLoading={createType.isPending} disabled={!name} onClick={handleCreate}>
          追加する
        </Button>
      </div>
      {!name && <p className="mt-1 text-xs text-muted-foreground">種類名を入力してください。</p>}
      <label className="mt-3 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={requiresGrant} onCheckedChange={(checked) => setRequiresGrant(checked === true)} />
        事前の付与(残数)が必要
      </label>
      <p className="mt-1 text-xs text-muted-foreground">
        チェックを外すと、忌引・代休のように事前の付与が無くても申請できる種類になります。
      </p>
    </Card>
  )
}

function SpecialLeaveGrantRulesCard() {
  const { data: types } = useSpecialLeaveTypes()
  const { data: rules, isLoading, error } = useSpecialLeaveGrantRules()
  const createRule = useCreateSpecialLeaveGrantRule()

  const [specialLeaveTypeId, setSpecialLeaveTypeId] = useState<number | undefined>(undefined)
  const [ruleName, setRuleName] = useState('')
  const [minAttendanceRate, setMinAttendanceRate] = useState('')
  const [firstGrantAfterMonths, setFirstGrantAfterMonths] = useState('')
  const [grantCycleMonths, setGrantCycleMonths] = useState('')
  const [expiresAfterMonths, setExpiresAfterMonths] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [steps, setSteps] = useState<StepInput[]>([])
  const [stepMonths, setStepMonths] = useState('')
  const [stepDays, setStepDays] = useState('')

  const handleAddStep = () => {
    if (!stepMonths || !stepDays) return
    setSteps((prev) => [...prev, { continuous_service_months: Number(stepMonths), grant_days: Number(stepDays) }])
    setStepMonths('')
    setStepDays('')
  }

  const handleCreateRule = () => {
    if (!specialLeaveTypeId) return
    createRule.mutate(
      {
        special_leave_type_id: specialLeaveTypeId,
        name: ruleName,
        min_attendance_rate: minAttendanceRate ? Number(minAttendanceRate) : undefined,
        first_grant_after_months: firstGrantAfterMonths ? Number(firstGrantAfterMonths) : undefined,
        grant_cycle_months: grantCycleMonths ? Number(grantCycleMonths) : undefined,
        expires_after_months: expiresAfterMonths ? Number(expiresAfterMonths) : undefined,
        is_active: isActive,
        steps: steps.length > 0 ? steps : undefined,
      },
      {
        onSuccess: () => {
          setRuleName('')
          setMinAttendanceRate('')
          setFirstGrantAfterMonths('')
          setGrantCycleMonths('')
          setExpiresAfterMonths('')
          setIsActive(true)
          setSteps([])
        },
      },
    )
  }

  return (
    <Card title="自動付与ルール">
      {error && <ErrorMessage error={error} fallback="付与ルールの取得に失敗しました。" />}
      {createRule.error && <ErrorMessage error={createRule.error} />}

      {isLoading ? (
        <LoadingState />
      ) : (rules ?? []).length === 0 ? (
        <div className="mb-5">
          <EmptyState title="自動付与ルールはまだありません。" description="ルールを作成すると、対象社員へ自動的に特別休暇が付与されます。" />
        </div>
      ) : (
        <ul className="mb-5 divide-y divide-border">
          {(rules ?? []).map((rule) => (
            <li key={rule.id} className="py-3">
              <div className="flex items-center gap-3">
                <strong className="text-sm font-semibold text-foreground">
                  {rule.special_leave_type_name}: {rule.name}
                </strong>
                <span className="text-sm text-muted-foreground">{rule.is_active ? '有効' : '無効'}</span>
              </div>
              <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm">
                <dt className="font-medium text-muted-foreground">最低出勤率</dt>
                <dd className="text-foreground">{rule.min_attendance_rate}</dd>
                <dt className="font-medium text-muted-foreground">初回付与</dt>
                <dd className="text-foreground">{rule.first_grant_after_months}か月後</dd>
                <dt className="font-medium text-muted-foreground">付与サイクル</dt>
                <dd className="text-foreground">{rule.grant_cycle_months}か月ごと</dd>
                <dt className="font-medium text-muted-foreground">失効</dt>
                <dd className="text-foreground">
                  {rule.expires_after_months !== null ? `付与から${rule.expires_after_months}か月後` : '失効しない'}
                </dd>
              </dl>
              {rule.steps && rule.steps.length > 0 && (
                <ul className="mt-1 list-disc pl-4 text-sm text-muted-foreground">
                  {rule.steps.map((step, index) => (
                    <li key={index}>
                      継続勤務{step.continuous_service_months}か月→{step.grant_days}日
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-3 text-sm font-semibold text-foreground">新しい自動付与ルールを作成</h3>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="特別休暇の種類" htmlFor="special-leave-rule-type" required>
          <NativeSelect
            id="special-leave-rule-type"
            value={specialLeaveTypeId ?? ''}
            onChange={(e) => setSpecialLeaveTypeId(e.target.value ? Number(e.target.value) : undefined)}
          >
            <option value="">選択してください</option>
            {(types ?? []).map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="ルール名" htmlFor="special-leave-rule-name" required>
          <Input id="special-leave-rule-name" value={ruleName} onChange={(e) => setRuleName(e.target.value)} />
        </FormField>

        <FormField label="最低出勤率" htmlFor="special-leave-rule-min-attendance-rate">
          <Input
            id="special-leave-rule-min-attendance-rate"
            type="number"
            value={minAttendanceRate}
            onChange={(e) => setMinAttendanceRate(e.target.value)}
          />
        </FormField>

        <FormField label="初回付与までの月数" htmlFor="special-leave-rule-first-grant-after-months">
          <Input
            id="special-leave-rule-first-grant-after-months"
            type="number"
            value={firstGrantAfterMonths}
            onChange={(e) => setFirstGrantAfterMonths(e.target.value)}
          />
        </FormField>

        <FormField label="付与サイクル(月数)" htmlFor="special-leave-rule-grant-cycle-months">
          <Input
            id="special-leave-rule-grant-cycle-months"
            type="number"
            value={grantCycleMonths}
            onChange={(e) => setGrantCycleMonths(e.target.value)}
          />
        </FormField>

        <FormField label="失効までの月数(空欄なら失効しない)" htmlFor="special-leave-rule-expires-after-months">
          <Input
            id="special-leave-rule-expires-after-months"
            type="number"
            value={expiresAfterMonths}
            onChange={(e) => setExpiresAfterMonths(e.target.value)}
          />
        </FormField>
      </div>

      <label className="mt-4 mb-4 flex items-center gap-2 text-sm font-medium text-foreground">
        <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
        有効
      </label>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <FormField label="継続勤務(か月)" htmlFor="special-leave-rule-step-months">
          <Input
            id="special-leave-rule-step-months"
            type="number"
            value={stepMonths}
            onChange={(e) => setStepMonths(e.target.value)}
          />
        </FormField>
        <FormField label="付与日数" htmlFor="special-leave-rule-step-days">
          <Input
            id="special-leave-rule-step-days"
            type="number"
            value={stepDays}
            onChange={(e) => setStepDays(e.target.value)}
          />
        </FormField>
        <Button variant="secondary" onClick={handleAddStep}>
          追加
        </Button>
      </div>

      {steps.length > 0 && (
        <ul className="mb-4 list-disc pl-4 text-sm text-muted-foreground">
          {steps.map((step, index) => (
            <li key={index}>
              継続勤務{step.continuous_service_months}か月→{step.grant_days}日
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-col items-start gap-1">
        <Button isLoading={createRule.isPending} disabled={!specialLeaveTypeId || !ruleName} onClick={handleCreateRule}>
          ルールを作成
        </Button>
        {!specialLeaveTypeId ? (
          <p className="text-xs text-muted-foreground">特別休暇の種類を選択してください。</p>
        ) : !ruleName ? (
          <p className="text-xs text-muted-foreground">ルール名を入力してください。</p>
        ) : null}
      </div>
    </Card>
  )
}

function ManualGrantCard() {
  const { data: types } = useSpecialLeaveTypes()
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const [specialLeaveTypeId, setSpecialLeaveTypeId] = useState<number | undefined>(undefined)
  const [grantedOn, setGrantedOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [grantedDays, setGrantedDays] = useState('')
  const [grantReason, setGrantReason] = useState('')

  const grantSpecialLeave = useGrantSpecialLeave()
  const { data: userGrants, isLoading: isLoadingUserGrants } = useSpecialLeaveGrantsForUser(userId ?? '')

  const handleGrant = () => {
    if (!userId || !specialLeaveTypeId) return
    grantSpecialLeave.mutate({
      user_id: userId,
      special_leave_type_id: specialLeaveTypeId,
      granted_on: grantedOn,
      expires_on: expiresOn || undefined,
      granted_days: Number(grantedDays),
      grant_reason: grantReason || undefined,
    })
  }

  return (
    <Card title="手動付与">
      {grantSpecialLeave.error && <ErrorMessage error={grantSpecialLeave.error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="対象社員" htmlFor="special-leave-grant-target-user" required>
          <UserPicker id="special-leave-grant-target-user" value={userId} onChange={setUserId} />
        </FormField>

        <FormField label="特別休暇の種類" htmlFor="special-leave-grant-type" required>
          <NativeSelect
            id="special-leave-grant-type"
            value={specialLeaveTypeId ?? ''}
            onChange={(e) => setSpecialLeaveTypeId(e.target.value ? Number(e.target.value) : undefined)}
          >
            <option value="">選択してください</option>
            {(types ?? []).map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormField label="付与日" htmlFor="special-leave-grant-granted-on" required>
          <DatePicker id="special-leave-grant-granted-on" value={grantedOn || undefined} onChange={(date) => setGrantedOn(date ?? '')} />
        </FormField>

        <FormField label="失効日(空欄なら失効しない)" htmlFor="special-leave-grant-expires-on">
          <DatePicker id="special-leave-grant-expires-on" value={expiresOn || undefined} onChange={(date) => setExpiresOn(date ?? '')} />
        </FormField>

        <FormField label="付与日数" htmlFor="special-leave-grant-granted-days" required>
          <Input
            id="special-leave-grant-granted-days"
            type="number"
            value={grantedDays}
            onChange={(e) => setGrantedDays(e.target.value)}
          />
        </FormField>

        <FormField label="付与理由" htmlFor="special-leave-grant-reason">
          <Input id="special-leave-grant-reason" value={grantReason} onChange={(e) => setGrantReason(e.target.value)} />
        </FormField>
      </div>

      <div className="mt-4 flex flex-col items-start gap-1">
        <Button
          isLoading={grantSpecialLeave.isPending}
          disabled={!userId || !specialLeaveTypeId || !grantedOn || !grantedDays}
          onClick={handleGrant}
        >
          付与する
        </Button>
        {!userId ? (
          <p className="text-xs text-muted-foreground">対象社員を選択してください。</p>
        ) : !specialLeaveTypeId ? (
          <p className="text-xs text-muted-foreground">特別休暇の種類を選択してください。</p>
        ) : !grantedOn ? (
          <p className="text-xs text-muted-foreground">付与日を選択してください。</p>
        ) : !grantedDays ? (
          <p className="text-xs text-muted-foreground">付与日数を入力してください。</p>
        ) : null}
      </div>

      {userId !== undefined && (
        <div className="mt-6">
          <h3 className="mb-2 text-sm font-semibold text-foreground">対象社員の特別休暇付与状況</h3>
          {isLoadingUserGrants ? (
            <LoadingState />
          ) : (userGrants ?? []).length === 0 ? (
            <EmptyState title="特別休暇の付与はまだありません。" description="上のフォームから付与すると、ここに一覧が表示されます。" />
          ) : (
            <ul className="divide-y divide-border">
              {(userGrants ?? []).map((grant) => (
                <li key={grant.id} className="py-2 text-sm text-foreground">
                  {grant.special_leave_type_name}: {grant.granted_on} 〜 {grant.expires_on ?? 'なし'} / 残
                  {grant.remaining_days}日
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  )
}

/**
 * 特別休暇の種類・自動付与ルールの設定と手動付与(管理者・人事向け)。
 */
export function SpecialLeaveAdminPage() {
  return (
    <div className="flex flex-col gap-6">
      <SpecialLeaveTypesCard />
      <SpecialLeaveGrantRulesCard />
      <ManualGrantCard />
    </div>
  )
}
