import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { Checkbox } from '../components/ui/checkbox'
import { Input } from '../components/ui/input'
import { NativeSelect } from '../components/ui/native-select'
import { Textarea } from '../components/ui/textarea'
import { useEditableRows } from '../hooks/useEditableRows'
import { useCreateRequestType, useRequestTypes, useUpdateRequestType } from '../hooks/useRequestTypes'
import type { RequestFormFieldSchema } from '../api/types'

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
  const [requiresBackOfficeTask, setRequiresBackOfficeTask] = useState(false)
  const [backOfficeTaskType, setBackOfficeTaskType] = useState('')
  const [isActive, setIsActive] = useState(true)
  const { rows, addRow, updateRow, removeRow, reset, toData } = useEditableRows<RequestFormFieldSchema>([])

  useEffect(() => {
    if (!existing) return
    setCode(existing.code)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setRequiresBackOfficeTask(existing.requires_backoffice_task)
    setBackOfficeTaskType(existing.backoffice_task_type ?? '')
    setIsActive(existing.is_active)
    reset(existing.form_schema)
  }, [existing, reset])

  if (!isCreate && isLoading) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="申請種別の取得に失敗しました。" />

  const isBusy = createType.isPending || updateType.isPending
  const error = createType.error ?? updateType.error

  const handleSave = async () => {
    const input = {
      code,
      name,
      description: description || undefined,
      form_schema: toData(),
      requires_backoffice_task: requiresBackOfficeTask,
      backoffice_task_type: requiresBackOfficeTask ? backOfficeTaskType || undefined : undefined,
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
          <FormField label="バックオフィスタスク種別" htmlFor="backoffice-task-type">
            <Input
              id="backoffice-task-type"
              value={backOfficeTaskType}
              onChange={(e) => setBackOfficeTaskType(e.target.value)}
            />
          </FormField>
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
