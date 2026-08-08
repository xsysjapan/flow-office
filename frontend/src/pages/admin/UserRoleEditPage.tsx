import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { AttendanceSubmissionReminderExclusionPanel } from '../../components/AttendanceSubmissionReminderExclusionPanel/AttendanceSubmissionReminderExclusionPanel'
import { AuthenticationKeysPanel } from '../../components/AuthenticationKeysPanel/AuthenticationKeysPanel'
import { Checkbox } from '../../components/ui/checkbox'
import { NativeSelect } from '../../components/ui/native-select'
import { Input } from '../../components/ui/input'
import type { UserProfileInput } from '../../api/users'
import { useRoles } from '../../hooks/useRoles'
import {
  useAssignUserWorkStyleForMonth,
  useRemoveUserWorkStyleMonthlyAssignment,
  useUserWorkStyleMonthlyAssignments,
} from '../../hooks/useUserWorkStyleMonthlyAssignments'
import {
  useUpdateUserHireDate,
  useUpdateUserRoles,
  useUpdateUserTerminationDate,
  useUpdateUserUsageStartDate,
  useUpdateUser,
  useUser,
} from '../../hooks/useUsers'
import { useWorkStyles } from '../../hooks/useWorkStyles'
import { formatDate } from '../../utils/weekDates'

type WorkStyleMode = 'default' | 'specify'

/**
 * UC-M001: ユーザーに付与する権限(ロール)を編集する。
 * UC-P002: 有給の自動付与に使う入社日を設定する。
 * 勤怠提出フォロー等の各種フォロー通知の起算日となる利用開始日を設定する。
 * 指示書 13章: 会社のデフォルトを使用するか、別の働き方を指定するかを選択する。
 */
export function UserRoleEditPage() {
  const { id } = useParams<{ id: string }>()
  const userId = id ?? ''
  const { data: user, isLoading: isLoadingUser, error: userError } = useUser(userId)
  const { data: roles, isLoading: isLoadingRoles, error: rolesError } = useRoles()
  const { data: workStyles } = useWorkStyles()
  const { data: workStyleHistory } = useUserWorkStyleMonthlyAssignments(userId)

  const updateRoles = useUpdateUserRoles()
  const updateHireDate = useUpdateUserHireDate()
  const updateTerminationDate = useUpdateUserTerminationDate()
  const updateUsageStartDate = useUpdateUserUsageStartDate()
  const updateUser = useUpdateUser()
  const assignWorkStyleForMonth = useAssignUserWorkStyleForMonth()
  const removeWorkStyleAssignment = useRemoveUserWorkStyleMonthlyAssignment()

  const [selectedCodes, setSelectedCodes] = useState<string[]>([])
  const [hireDate, setHireDate] = useState('')
  const [terminationDate, setTerminationDate] = useState('')
  const [usageStartDate, setUsageStartDate] = useState('')
  const [isInitialized, setIsInitialized] = useState(false)
  const [profile,setProfile]=useState({name:'',email:'',employee_number:'',department:'',job_title:'',employment_status:'active',account_status:'active'})

  const currentYearMonth = formatDate(new Date()).slice(0, 7)
  const currentAssignment = workStyleHistory?.find((assignment) => assignment.year_month === currentYearMonth)
  const defaultWorkStyle = workStyles?.find((style) => style.is_default)

  const [workStyleMode, setWorkStyleMode] = useState<WorkStyleMode>('default')
  const [selectedWorkStyleId, setSelectedWorkStyleId] = useState('')

  useEffect(() => {
    if (user && !isInitialized) {
      setSelectedCodes(user.roles ?? [])
      setHireDate(user.hire_date ?? '')
      setTerminationDate(user.termination_date ?? '')
      setUsageStartDate(user.usage_start_date ?? '')
      setProfile({name:user.name,email:user.email??'',employee_number:user.employee_number??'',department:user.department??'',job_title:user.job_title??'',employment_status:user.employment_status,account_status:user.account_status??'active'})
      setIsInitialized(true)
    }
  }, [user, isInitialized])

  useEffect(() => {
    if (currentAssignment) {
      setWorkStyleMode('specify')
      setSelectedWorkStyleId(currentAssignment.work_style_id)
    } else {
      setWorkStyleMode('default')
      setSelectedWorkStyleId('')
    }
  }, [currentAssignment])

  if (isLoadingUser || isLoadingRoles) return <LoadingState />
  if (userError) return <ErrorMessage error={userError} fallback="ユーザーの取得に失敗しました。" />
  if (rolesError) return <ErrorMessage error={rolesError} fallback="権限一覧の取得に失敗しました。" />
  if (!user) return null

  const handleSaveWorkStyle = () => {
    if (workStyleMode === 'default') {
      if (currentAssignment) {
        removeWorkStyleAssignment.mutate({ id: currentAssignment.id, userId })
      }
      return
    }
    if (!selectedWorkStyleId) return
    assignWorkStyleForMonth.mutate({
      user_id: userId,
      year_month: currentYearMonth,
      work_style_id: selectedWorkStyleId,
    })
  }

  const toggleRole = (code: string) => {
    setSelectedCodes((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]))
  }

  const isExternalHrField=(field:string)=>user.field_authorities?.some(authority=>authority.field_key===(field==='name'?'display_name':field)&&authority.authority_type==='EXTERNAL_HR')??false

  return (
    <Card title={`${user.name}の権限設定`}>
      {updateRoles.error && <ErrorMessage error={updateRoles.error} />}
      {updateUser.error && <ErrorMessage error={updateUser.error} />}
      {updateRoles.isSuccess && <Badge tone="success">保存しました</Badge>}

      <div className="mb-6 rounded border p-3">
        <h2 className="mb-3 font-medium">基本情報</h2>
        <div className="grid gap-3 md:grid-cols-2">
          <FormField label={`氏名${isExternalHrField('name')?'（外部HR管理）':''}`} htmlFor="user-profile-name"><Input id="user-profile-name" disabled={isExternalHrField('name')} value={profile.name} onChange={e=>setProfile({...profile,name:e.target.value})}/></FormField>
          <FormField label={`メール${isExternalHrField('email')?'（外部HR管理）':''}`} htmlFor="user-profile-email"><Input id="user-profile-email" type="email" disabled={isExternalHrField('email')} value={profile.email} onChange={e=>setProfile({...profile,email:e.target.value})}/></FormField>
          <FormField label={`社員番号${isExternalHrField('employee_number')?'（外部HR管理）':''}`} htmlFor="user-profile-number"><Input id="user-profile-number" disabled={isExternalHrField('employee_number')} value={profile.employee_number} onChange={e=>setProfile({...profile,employee_number:e.target.value})}/></FormField>
          <FormField label={`部署${isExternalHrField('department')?'（外部HR管理）':''}`} htmlFor="user-profile-department"><Input id="user-profile-department" disabled={isExternalHrField('department')} value={profile.department} onChange={e=>setProfile({...profile,department:e.target.value})}/></FormField>
          <FormField label={`役職${isExternalHrField('job_title')?'（外部HR管理）':''}`} htmlFor="user-profile-job"><Input id="user-profile-job" disabled={isExternalHrField('job_title')} value={profile.job_title} onChange={e=>setProfile({...profile,job_title:e.target.value})}/></FormField>
          <FormField label={`アカウント状態${isExternalHrField('account_status')?'（外部HR管理）':''}`} htmlFor="user-profile-status"><NativeSelect id="user-profile-status" disabled={isExternalHrField('account_status')} value={profile.account_status} onChange={e=>setProfile({...profile,account_status:e.target.value})}>{['pending','active','suspended','leave','retired','disabled'].map(status=><option key={status}>{status}</option>)}</NativeSelect></FormField>
        </div>
        <Button variant="secondary" isLoading={updateUser.isPending} disabled={!profile.name||!profile.email} onClick={()=>{const input=Object.fromEntries(Object.entries({...profile,employee_number:profile.employee_number||null,department:profile.department||null,job_title:profile.job_title||null}).filter(([key])=>!isExternalHrField(key))) as Partial<UserProfileInput>;updateUser.mutate({id:userId,input})}}>基本情報を保存する</Button>
      </div>

      <dl className="mb-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="font-medium text-muted-foreground">メールアドレス</dt>
        <dd className="text-foreground">{user.email}</dd>
        <dt className="font-medium text-muted-foreground">部署</dt>
        <dd className="text-foreground">{user.department ?? '-'}</dd>
        <dt className="font-medium text-muted-foreground">役職</dt>
        <dd className="text-foreground">{user.job_title ?? '-'}</dd>
      </dl>
      <div className="mb-6 grid gap-4 md:grid-cols-2"><div className="rounded border p-3"><h2 className="mb-2 font-medium">外部ID・管理元</h2>{user.external_identities?.map(identity=><div key={identity.id} className="text-sm">{identity.provider}: {identity.external_subject_id} / 最終同期 {identity.last_synced_at?new Date(identity.last_synced_at).toLocaleString():'-'}</div>)}<div className="mt-2 flex flex-wrap gap-1">{user.field_authorities?.map(authority=><Badge key={authority.field_key} tone={authority.authority_type==='EXTERNAL_HR'?'info':'neutral'}>{authority.field_key}: {authority.authority_type}</Badge>)}</div></div><div className="rounded border p-3"><h2 className="mb-2 font-medium">所属・予約</h2>{user.memberships?.map(m=><div key={m.id} className="text-sm">{m.group.name}{m.is_primary?'（主所属）':''}</div>)}{user.membership_change_sets?.map(set=><div key={set.id} className="text-sm text-muted-foreground">{new Date(set.effective_at).toLocaleString()}: {set.status}</div>)}</div><div className="rounded border p-3"><h2 className="mb-2 font-medium">有効Feature・Permission</h2><div className="flex flex-wrap gap-1">{user.effective_features?.map(value=><Badge key={value} tone="info">{value}</Badge>)}{user.effective_permissions?.map(value=><Badge key={value} tone="neutral">{value}</Badge>)}</div></div><div className="rounded border p-3"><h2 className="mb-2 font-medium">RoleAssignment・個別停止</h2>{user.role_assignments?.map(value=><div key={value.id} className="text-sm">{value.role?.name}: {value.scope_type} / {value.status}</div>)}{user.feature_suspensions?.map(value=><div key={value.id} className="text-sm text-destructive">{value.feature?.name}: {value.reason}</div>)}</div></div>
      <div className="mb-6"><Link className="text-sm text-primary hover:underline" to={`/admin/audit-log?user_id=${userId}`}>このユーザーの変更履歴を監査ログで確認</Link></div>

      <ul className="mb-4 divide-y divide-border">
        {roles?.map((role) => (
          <li key={role.code} className="py-2">
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox checked={selectedCodes.includes(role.code)} onCheckedChange={() => toggleRole(role.code)} />
              {role.name}
            </label>
          </li>
        ))}
      </ul>

      <div className="flex gap-3">
        <Button
          isLoading={updateRoles.isPending}
          onClick={() => updateRoles.mutate({ id: userId, roleCodes: selectedCodes })}
        >
          保存する
        </Button>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {updateHireDate.error && <ErrorMessage error={updateHireDate.error} />}
        {updateTerminationDate.error && <ErrorMessage error={updateTerminationDate.error} />}
        {updateHireDate.isSuccess && <Badge tone="success">保存しました</Badge>}
        {updateTerminationDate.isSuccess && <Badge tone="success">保存しました</Badge>}

        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="入社日(有給の自動付与に使用)" htmlFor="user-role-edit-hire-date">
            <DatePicker id="user-role-edit-hire-date" value={hireDate || undefined} onChange={(date) => setHireDate(date ?? '')} />
          </FormField>
          <FormField label="退社日(未設定なら在籍中)" htmlFor="user-role-edit-termination-date">
            <DatePicker
              id="user-role-edit-termination-date"
              min={hireDate || undefined}
              value={terminationDate || undefined}
              onChange={(date) => setTerminationDate(date ?? '')}
            />
          </FormField>
        </div>

        <div className="flex gap-2">
          <Button
            variant="secondary"
            isLoading={updateHireDate.isPending}
            disabled={!hireDate}
            onClick={() => updateHireDate.mutate({ id: userId, hireDate })}
          >
            入社日を保存する
          </Button>
          <Button
            variant="secondary"
            isLoading={updateTerminationDate.isPending}
            onClick={() => updateTerminationDate.mutate({ id: userId, terminationDate: terminationDate || null })}
          >
            退社日を保存する
          </Button>
        </div>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {updateUsageStartDate.error && <ErrorMessage error={updateUsageStartDate.error} />}
        {updateUsageStartDate.isSuccess && <Badge tone="success">保存しました</Badge>}

        <FormField
          label="利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)"
          htmlFor="user-role-edit-usage-start-date"
        >
          <DatePicker
            id="user-role-edit-usage-start-date"
            value={usageStartDate || undefined}
            onChange={(date) => setUsageStartDate(date ?? '')}
          />
        </FormField>

        <div className="flex gap-2">
          <Button
            variant="secondary"
            isLoading={updateUsageStartDate.isPending}
            disabled={!usageStartDate}
            onClick={() => updateUsageStartDate.mutate({ id: userId, usageStartDate })}
          >
            利用開始日を保存する
          </Button>
        </div>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {assignWorkStyleForMonth.error && <ErrorMessage error={assignWorkStyleForMonth.error} />}
        {removeWorkStyleAssignment.error && <ErrorMessage error={removeWorkStyleAssignment.error} />}
        {(assignWorkStyleForMonth.isSuccess || removeWorkStyleAssignment.isSuccess) && (
          <Badge tone="success">保存しました</Badge>
        )}

        <h3 className="mb-3 text-sm font-semibold text-foreground">働き方({currentYearMonth})</h3>

        <div className="mb-4 flex flex-col gap-2">
          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              type="radio"
              name="work-style-mode"
              className="mt-1"
              checked={workStyleMode === 'default'}
              onChange={() => setWorkStyleMode('default')}
            />
            <span>
              会社のデフォルトを使用
              {defaultWorkStyle && (
                <span className="block text-xs text-muted-foreground">
                  {defaultWorkStyle.name}
                  {defaultWorkStyle.default_start_time && defaultWorkStyle.default_end_time
                    ? `(${defaultWorkStyle.default_start_time}〜${defaultWorkStyle.default_end_time})`
                    : ''}
                </span>
              )}
            </span>
          </label>

          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              type="radio"
              name="work-style-mode"
              className="mt-1"
              checked={workStyleMode === 'specify'}
              onChange={() => setWorkStyleMode('specify')}
            />
            <span>別の働き方を指定</span>
          </label>

          {workStyleMode === 'specify' && (
            <NativeSelect
              aria-label="指定する働き方"
              value={selectedWorkStyleId}
              onChange={(e) => setSelectedWorkStyleId(e.target.value)}
            >
              <option value="">選択してください</option>
              {workStyles?.map((style) => (
                <option key={style.id} value={style.id}>
                  {style.name}
                </option>
              ))}
            </NativeSelect>
          )}
        </div>

        <Button
          variant="secondary"
          isLoading={assignWorkStyleForMonth.isPending || removeWorkStyleAssignment.isPending}
          disabled={workStyleMode === 'specify' && !selectedWorkStyleId}
          onClick={handleSaveWorkStyle}
        >
          働き方を保存する
        </Button>

        {(workStyleHistory ?? []).length > 0 && (
          <div className="mt-4">
            <h4 className="mb-2 text-xs font-semibold text-muted-foreground">適用履歴</h4>
            <ul className="divide-y divide-border text-sm">
              {workStyleHistory?.map((assignment) => (
                <li key={assignment.id} className="py-1 text-foreground">
                  {assignment.year_month}: {assignment.work_style?.name ?? assignment.work_style_id}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>

      <div className="mt-6 border-t border-border pt-4">
        <AttendanceSubmissionReminderExclusionPanel userId={userId} />
      </div>

      <div className="mt-6 border-t border-border pt-4">
        <AuthenticationKeysPanel userId={userId} />
      </div>
    </Card>
  )
}
