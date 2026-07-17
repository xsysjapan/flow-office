import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Textarea } from '../../components/ui/textarea'
import { useEditableRows } from '../../hooks/useEditableRows'
import { useCreateRequestType, useRequestTypes, useUpdateRequestType } from '../../hooks/useRequestTypes'
import type { RequestFormFieldSchema } from '../../api/types'

/**
 * UC-M002 / UC-W001: 管理者が申請種別を作成・編集する。
 */
export function RequestTypeEditPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const isCreate = !id || id === 'new'

  const { data: requestTypes, isLoading, error: listError } = useRequestTypes(true)
  const existing = useMemo(
    () => (isCreate ? undefined : requestTypes?.find((type) => type.id === Number(id))),
    [requestTypes, id, isCreate],
  )

  const createType = useCreateRequestType()
  const updateType = useUpdateRequestType()

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [requiresAttachment, setRequiresAttachment] = useState(false)
  const [attachmentMaxSizeKb, setAttachmentMaxSizeKb] = useState('')
  const [attachmentAllowedExtensions, setAttachmentAllowedExtensions] = useState('')
  const [eligibleRoleCodes, setEligibleRoleCodes] = useState('')
  const [requiresBackOfficeTask, setRequiresBackOfficeTask] = useState(false)
  const [backOfficeTaskType, setBackOfficeTaskType] = useState('')
  const [backOfficeDepartment, setBackOfficeDepartment] = useState('')
  const [exportAmountField, setExportAmountField] = useState('')
  const [allowedStatusTransitionsJson, setAllowedStatusTransitionsJson] = useState('')
  const [allowedStatusTransitionsError, setAllowedStatusTransitionsError] = useState('')
  const [isActive, setIsActive] = useState(true)
  const { rows, addRow, updateRow, removeRow, reset, toData } = useEditableRows<RequestFormFieldSchema>([])

  useEffect(() => {
    if (!existing) return
    setCode(existing.code)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setRequiresAttachment(existing.requires_attachment)
    setAttachmentMaxSizeKb(existing.attachment_max_size_kb ? String(existing.attachment_max_size_kb) : '')
    setAttachmentAllowedExtensions((existing.attachment_allowed_extensions ?? []).join(', '))
    setEligibleRoleCodes((existing.eligible_role_codes ?? []).join(', '))
    setRequiresBackOfficeTask(existing.requires_backoffice_task)
    setBackOfficeTaskType(existing.backoffice_task_type ?? '')
    setBackOfficeDepartment(existing.backoffice_department ?? '')
    setExportAmountField(existing.export_amount_field ?? '')
    setAllowedStatusTransitionsJson(
      existing.allowed_status_transitions ? JSON.stringify(existing.allowed_status_transitions, null, 2) : '',
    )
    setIsActive(existing.is_active)
    reset(existing.form_schema)
  }, [existing, reset])

  const parseCommaSeparated = (value: string): string[] | undefined => {
    const items = value.split(',').map((item) => item.trim()).filter(Boolean)
    return items.length > 0 ? items : undefined
  }

  if (!isCreate && isLoading) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="申請種別の取得に失敗しました。" />

  const isBusy = createType.isPending || updateType.isPending
  const error = createType.error ?? updateType.error

  const handleSave = async () => {
    let allowedStatusTransitions: Record<string, string[]> | undefined
    if (allowedStatusTransitionsJson.trim()) {
      try {
        allowedStatusTransitions = JSON.parse(allowedStatusTransitionsJson) as Record<string, string[]>
        setAllowedStatusTransitionsError('')
      } catch {
        setAllowedStatusTransitionsError('JSON形式が正しくありません。')
        return
      }
    }

    const input = {
      code,
      name,
      description: description || undefined,
      form_schema: toData(),
      requires_attachment: requiresAttachment,
      attachment_max_size_kb: requiresAttachment && attachmentMaxSizeKb ? Number(attachmentMaxSizeKb) : undefined,
      attachment_allowed_extensions: requiresAttachment ? parseCommaSeparated(attachmentAllowedExtensions) : undefined,
      eligible_role_codes: parseCommaSeparated(eligibleRoleCodes),
      requires_backoffice_task: requiresBackOfficeTask,
      backoffice_task_type: requiresBackOfficeTask ? backOfficeTaskType || undefined : undefined,
      backoffice_department: requiresBackOfficeTask ? backOfficeDepartment || undefined : undefined,
      export_amount_field: requiresBackOfficeTask ? exportAmountField || undefined : undefined,
      allowed_status_transitions: requiresBackOfficeTask ? allowedStatusTransitions : undefined,
      is_active: isActive,
    }

    if (isCreate) {
      await createType.mutateAsync(input)
    } else if (existing) {
      await updateType.mutateAsync({ id: existing.id, input })
    }

    navigate('/admin/request-types')
  }

  return (
    <Card title={isCreate ? '申請種別の新規作成' : '申請種別の編集'}>
      {error && <ErrorMessage error={error} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="コード" htmlFor="code" required>
          <Input id="code" value={code} disabled={!isCreate} onChange={(e) => setCode(e.target.value)} />
        </FormField>

        <FormField label="名称" htmlFor="name" required>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
      </div>

      <FormField label="説明" htmlFor="description">
        <Textarea id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
      </FormField>

      <FormField label="申請可能な対象者(ロールコード、カンマ区切り。空欄なら全員)" htmlFor="eligible-role-codes">
        <Input
          id="eligible-role-codes"
          placeholder="admin, hr_staff"
          value={eligibleRoleCodes}
          onChange={(e) => setEligibleRoleCodes(e.target.value)}
        />
      </FormField>

      <div className="mb-4 flex flex-col gap-2">
        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox
            id="requires-attachment"
            checked={requiresAttachment}
            onCheckedChange={(checked) => setRequiresAttachment(checked === true)}
          />
          添付ファイルを必須にする
        </label>

        {requiresAttachment && (
          <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
            <FormField label="添付ファイルの上限サイズ(KB)" htmlFor="attachment-max-size-kb">
              <Input
                id="attachment-max-size-kb"
                type="number"
                min={1}
                value={attachmentMaxSizeKb}
                onChange={(e) => setAttachmentMaxSizeKb(e.target.value)}
              />
            </FormField>

            <FormField label="許可する拡張子(カンマ区切り)" htmlFor="attachment-allowed-extensions">
              <Input
                id="attachment-allowed-extensions"
                placeholder="pdf, jpg, png"
                value={attachmentAllowedExtensions}
                onChange={(e) => setAttachmentAllowedExtensions(e.target.value)}
              />
            </FormField>
          </div>
        )}
      </div>

      <div className="mb-4 flex flex-col gap-2">
        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox
            id="requires-backoffice-task"
            checked={requiresBackOfficeTask}
            onCheckedChange={(checked) => setRequiresBackOfficeTask(checked === true)}
          />
          バックオフィス処理を発生させる
        </label>

        {requiresBackOfficeTask && (
          <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-4 sm:grid-cols-2">
            <FormField label="バックオフィスタスク種別" htmlFor="backoffice-task-type">
              <Input
                id="backoffice-task-type"
                value={backOfficeTaskType}
                onChange={(e) => setBackOfficeTaskType(e.target.value)}
              />
            </FormField>

            <FormField label="初期処理部署" htmlFor="backoffice-department">
              <Input
                id="backoffice-department"
                value={backOfficeDepartment}
                onChange={(e) => setBackOfficeDepartment(e.target.value)}
              />
            </FormField>

            <FormField label="会計/振込CSVの金額項目(form_dataのキー。空欄ならCSV対象外)" htmlFor="export-amount-field">
              <Input
                id="export-amount-field"
                value={exportAmountField}
                onChange={(e) => setExportAmountField(e.target.value)}
              />
            </FormField>

            <FormField label="ステータス遷移(JSON、任意)" htmlFor="allowed-status-transitions">
              <Textarea
                id="allowed-status-transitions"
                placeholder={'{"not_started": ["in_review"], "in_review": ["payment_scheduled"]}'}
                value={allowedStatusTransitionsJson}
                onChange={(e) => setAllowedStatusTransitionsJson(e.target.value)}
              />
              <p className="mt-1 text-xs text-muted-foreground">
                未設定なら遷移制限なし。設定するとUC-B003のステータス変更がこの定義に従う。
              </p>
              {allowedStatusTransitionsError && <p className="mt-1 text-xs text-destructive">{allowedStatusTransitionsError}</p>}
            </FormField>
          </div>
        )}

        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Checkbox id="is-active" checked={isActive} onCheckedChange={(checked) => setIsActive(checked === true)} />
          有効
        </label>
      </div>

      <h3 className="mb-3 text-sm font-semibold text-foreground">入力項目</h3>
      <ul className="mb-3 flex flex-col gap-2">
        {rows.map((row) => (
          <li key={row.rowId} className="flex flex-wrap items-center gap-2">
            <Input
              className="w-auto"
              placeholder="キー"
              value={row.key}
              onChange={(e) => updateRow(row.rowId, { key: e.target.value })}
            />
            <Input
              className="w-auto"
              placeholder="ラベル"
              value={row.label}
              onChange={(e) => updateRow(row.rowId, { label: e.target.value })}
            />
            <NativeSelect
              className="w-auto"
              value={row.type}
              onChange={(e) => updateRow(row.rowId, { type: e.target.value as RequestFormFieldSchema['type'] })}
            >
              <option value="text">テキスト</option>
              <option value="number">数値</option>
              <option value="date">日付</option>
            </NativeSelect>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                checked={row.required ?? false}
                onCheckedChange={(checked) => updateRow(row.rowId, { required: checked === true })}
              />
              必須
            </label>
            <Button variant="danger" onClick={() => removeRow(row.rowId)}>
              削除
            </Button>
          </li>
        ))}
      </ul>
      <Button variant="secondary" onClick={() => addRow({ key: '', label: '', type: 'text' })}>
        項目を追加
      </Button>

      <div className="mt-5">
        <Button isLoading={isBusy} disabled={!code || !name} onClick={() => void handleSave()}>
          保存する
        </Button>
      </div>
    </Card>
  )
}
