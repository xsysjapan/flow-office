import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { RequestType } from '../../api/types'
import { BackLink } from '../../components/BackLink/BackLink'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { useBackTarget, useKeepBackState, useSearchParamsKeepingBackState } from '../../navigation/backState'
import { notify } from '../../lib/notify'
import { fieldErrors } from '../../utils/fieldErrors'
import { useRequestTypes } from '../../hooks/useRequestTypes'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { isWorkflowRequestNewDirty } from './workflowRequestNewDirty'
import { useCreateWorkflowRequest, useSubmitWorkflowRequest } from '../../hooks/useWorkflowRequests'

type RequestNewInitial = {
  requestTypeCode: string
  title: string
  formValues: Record<string, string>
}

/**
 * URLクエリから初期値を作る。`type`が有効な申請種別に無ければ無視し、
 * form項目はその種別のform_schemaに存在するkeyのみ取り込む。
 */
function buildInitial(searchParams: URLSearchParams, requestTypes: RequestType[]): RequestNewInitial {
  const selected = requestTypes.find((type) => type.code === searchParams.get('type'))
  const formValues: Record<string, string> = {}
  if (selected) {
    for (const field of selected.form_schema) {
      const value = searchParams.get(field.key)
      if (value !== null) formValues[field.key] = value
    }
  }
  return {
    requestTypeCode: selected?.code ?? '',
    title: searchParams.get('title') ?? '',
    formValues,
  }
}

/**
 * UC-W002: 社員が申請する(下書き保存または申請)。
 * URLクエリ(`type`・`title`・form_schemaの各key)で初期値を受け取れる。
 */
export function WorkflowRequestNewPage() {
  const [searchParams] = useSearchParamsKeepingBackState()
  const { data: requestTypes, isLoading: isLoadingTypes, error: typesError } = useRequestTypes()

  if (isLoadingTypes) return <LoadingState />
  if (typesError) return <ErrorMessage error={typesError} fallback="申請種別の取得に失敗しました。" />

  const types = requestTypes ?? []
  return <WorkflowRequestNewForm requestTypes={types} initial={buildInitial(searchParams, types)} />
}

function WorkflowRequestNewForm({ requestTypes, initial }: { requestTypes: RequestType[]; initial: RequestNewInitial }) {
  const navigate = useNavigate()
  const keepState = useKeepBackState()
  const backTarget = useBackTarget()

  const [requestTypeCode, setRequestTypeCode] = useState(() => initial.requestTypeCode)
  const [title, setTitle] = useState(() => initial.title)
  const [formValues, setFormValues] = useState<Record<string, string>>(() => initial.formValues)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)

  // 比べる元は最初の描画の初期値(URLクエリの初期値を含む)。
  const [base] = useState<RequestNewInitial & { approverUserId: string | undefined }>(() => ({
    ...initial,
    approverUserId: undefined,
  }))
  const { navigateAfterSave } = useUnsavedChangesGuard(
    isWorkflowRequestNewDirty(base, { requestTypeCode, title, formValues, approverUserId }),
  )

  const createRequest = useCreateWorkflowRequest()
  const submitRequest = useSubmitWorkflowRequest()

  const selectedType = useMemo(
    () => requestTypes.find((type) => type.code === requestTypeCode),
    [requestTypes, requestTypeCode],
  )

  const isBusy = createRequest.isPending || submitRequest.isPending
  const { of, rest } = fieldErrors(createRequest.error ?? submitRequest.error, [
    'request_type_code',
    'title',
    'approver_user_id',
  ])

  const handleSave = async (submitAfterCreate: boolean) => {
    const created = await createRequest.mutateAsync({
      request_type_code: requestTypeCode,
      title,
      form_data: formValues,
      approver_user_id: approverUserId,
    })

    if (submitAfterCreate) {
      await submitRequest.mutateAsync({ id: created.id, approverUserId })
      notify.success('申請を提出しました')
    } else {
      notify.success('申請を下書き保存しました')
    }

    navigateAfterSave(`/requests/${created.id}`, { state: keepState })
  }

  return (
    <div className="flex flex-col gap-6">
      <BackLink />

      <Card title="新規申請">
        <FormLayout>
          <FormField label="申請種別" htmlFor="request-type" required error={of('request_type_code')}>
            <NativeSelect
              id="request-type"
              value={requestTypeCode}
              onChange={(e) => {
                setRequestTypeCode(e.target.value)
                setFormValues({})
              }}
            >
              <option value="">選択してください</option>
              {requestTypes.map((type) => (
                <option key={type.code} value={type.code}>
                  {type.name}
                </option>
              ))}
            </NativeSelect>
          </FormField>

          <FormField label="タイトル" htmlFor="title" required error={of('title')}>
            <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </FormField>

          {selectedType?.form_schema.map((field) => (
            <FormField key={field.key} label={field.label} htmlFor={`field-${field.key}`} required={field.required}>
              {field.type === 'date' ? (
                <DatePicker
                  id={`field-${field.key}`}
                  value={formValues[field.key] || undefined}
                  onChange={(date) => setFormValues((prev) => ({ ...prev, [field.key]: date ?? '' }))}
                />
              ) : (
                <Input
                  id={`field-${field.key}`}
                  type={field.type === 'number' ? 'number' : 'text'}
                  value={formValues[field.key] ?? ''}
                  onChange={(e) => setFormValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
                />
              )}
            </FormField>
          ))}

          <FormField label="承認者" htmlFor="approver" required error={of('approver_user_id')}>
            <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
          </FormField>

          {(!requestTypeCode || !title) && (
            <p className="mb-2 text-xs text-muted-foreground">申請種別とタイトルを入力すると保存できます。</p>
          )}
          {requestTypeCode && title && !approverUserId && (
            <p className="mb-2 text-xs text-muted-foreground">提出するには承認者を選択してください。</p>
          )}
          {rest != null && <ErrorMessage error={rest} />}
          <FormActions>
            <Button variant="secondary" disabled={isBusy} onClick={() =>
                backTarget ? navigate(backTarget.to, { state: backTarget.state, replace: true }) : navigate('/requests')
              }>
              キャンセル
            </Button>
            <Button
              variant="secondary"
              isLoading={isBusy}
              disabled={!requestTypeCode || !title}
              onClick={() => void handleSave(false).catch(() => undefined)}
            >
              下書き保存
            </Button>
            <Button
              isLoading={isBusy}
              disabled={!requestTypeCode || !title || !approverUserId}
              onClick={() => void handleSave(true).catch(() => undefined)}
            >
              提出する
            </Button>
          </FormActions>
        </FormLayout>
      </Card>
    </div>
  )
}
