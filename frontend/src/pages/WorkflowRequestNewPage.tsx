import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { UserPicker } from '../components/UserPicker/UserPicker'
import { useRequestTypes } from '../hooks/useRequestTypes'
import { useCreateWorkflowRequest, useSubmitWorkflowRequest } from '../hooks/useWorkflowRequests'

/**
 * UC-W002: 社員が申請する(下書き保存または申請)。
 */
export function WorkflowRequestNewPage() {
  const navigate = useNavigate()
  const { data: requestTypes, isLoading: isLoadingTypes, error: typesError } = useRequestTypes()

  const [requestTypeCode, setRequestTypeCode] = useState('')
  const [title, setTitle] = useState('')
  const [formValues, setFormValues] = useState<Record<string, string>>({})
  const [approverUserId, setApproverUserId] = useState<number | undefined>(undefined)

  const createRequest = useCreateWorkflowRequest()
  const submitRequest = useSubmitWorkflowRequest()

  const selectedType = useMemo(
    () => requestTypes?.find((type) => type.code === requestTypeCode),
    [requestTypes, requestTypeCode],
  )

  if (isLoadingTypes) return <LoadingState />
  if (typesError) return <ErrorMessage error={typesError} fallback="申請種別の取得に失敗しました。" />

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
          {requestTypes?.map((type) => (
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
          <Input
            id={`field-${field.key}`}
            type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
            value={formValues[field.key] ?? ''}
            onChange={(e) => setFormValues((prev) => ({ ...prev, [field.key]: e.target.value }))}
          />
        </FormField>
      ))}

      <FormField label="承認者" htmlFor="approver" required>
        <UserPicker id="approver" value={approverUserId} onChange={setApproverUserId} />
      </FormField>

      <div className="flex gap-3">
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
    </Card>
  )
}
