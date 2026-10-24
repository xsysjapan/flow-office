import { useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import type { RequestType } from '../../api/types'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { useRequestTypes } from '../../hooks/useRequestTypes'
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
  const [searchParams] = useSearchParams()
  const { data: requestTypes, isLoading: isLoadingTypes, error: typesError } = useRequestTypes()

  if (isLoadingTypes) return <LoadingState />
  if (typesError) return <ErrorMessage error={typesError} fallback="申請種別の取得に失敗しました。" />

  const types = requestTypes ?? []
  return <WorkflowRequestNewForm requestTypes={types} initial={buildInitial(searchParams, types)} />
}

function WorkflowRequestNewForm({ requestTypes, initial }: { requestTypes: RequestType[]; initial: RequestNewInitial }) {
  const navigate = useNavigate()

  const [requestTypeCode, setRequestTypeCode] = useState(() => initial.requestTypeCode)
  const [title, setTitle] = useState(() => initial.title)
  const [formValues, setFormValues] = useState<Record<string, string>>(() => initial.formValues)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)

  const createRequest = useCreateWorkflowRequest()
  const submitRequest = useSubmitWorkflowRequest()

  const selectedType = useMemo(
    () => requestTypes.find((type) => type.code === requestTypeCode),
    [requestTypes, requestTypeCode],
  )

  const isBusy = createRequest.isPending || submitRequest.isPending
  const error = createRequest.error ?? submitRequest.error

  const handleSave = async (submitAfterCreate: boolean) => {
    const created = await createRequest.mutateAsync({
      request_type_code: requestTypeCode,
      title,
      form_data: formValues,
      approver_user_id: approverUserId,
    })

    if (submitAfterCreate) {
      await submitRequest.mutateAsync({ id: created.id, approverUserId })
    }

    navigate(`/requests/${created.id}`)
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link to="/requests" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
          ← 一覧に戻る
        </Link>
      </div>

      <Card title="新規申請">
        {error && <ErrorMessage error={error} />}

        <FormField label="申請種別" htmlFor="request-type" required>
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

        <FormField label="タイトル" htmlFor="title" required>
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

        <FormField label="承認者" htmlFor="approver" required>
          <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
        </FormField>

        <div className="flex flex-wrap items-center gap-3">
          <Button variant="secondary" disabled={isBusy} onClick={() => navigate('/requests')}>
            キャンセル
          </Button>
          <Button
            variant="secondary"
            isLoading={isBusy}
            disabled={!requestTypeCode || !title}
            onClick={() => void handleSave(false)}
          >
            下書き保存
          </Button>
          <Button
            isLoading={isBusy}
            disabled={!requestTypeCode || !title || !approverUserId}
            onClick={() => void handleSave(true)}
          >
            提出する
          </Button>
        </div>
        {(!requestTypeCode || !title) && (
          <p className="mt-2 text-xs text-muted-foreground">申請種別とタイトルを入力すると保存できます。</p>
        )}
        {requestTypeCode && title && !approverUserId && (
          <p className="mt-2 text-xs text-muted-foreground">提出するには承認者を選択してください。</p>
        )}
      </Card>
    </div>
  )
}
