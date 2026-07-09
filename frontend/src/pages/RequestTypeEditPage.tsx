import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Button } from '../components/Button/Button'
import { Card } from '../components/Card/Card'
import { ErrorMessage } from '../components/ErrorMessage/ErrorMessage'
import { FormField } from '../components/FormField/FormField'
import { LoadingState } from '../components/LoadingState/LoadingState'
import { useCreateRequestType, useRequestTypes, useUpdateRequestType } from '../hooks/useRequestTypes'
import type { RequestFormFieldSchema } from '../api/types'
import './RequestTypeEditPage.css'

let nextRowId = 0

interface SchemaRow extends RequestFormFieldSchema {
  rowId: number
}

function toRows(schema: RequestFormFieldSchema[]): SchemaRow[] {
  return schema.map((field) => ({ ...field, rowId: nextRowId++ }))
}

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
  const [rows, setRows] = useState<SchemaRow[]>([])

  useEffect(() => {
    if (!existing) return
    setCode(existing.code)
    setName(existing.name)
    setDescription(existing.description ?? '')
    setRequiresBackOfficeTask(existing.requires_backoffice_task)
    setBackOfficeTaskType(existing.backoffice_task_type ?? '')
    setIsActive(existing.is_active)
    setRows(toRows(existing.form_schema))
  }, [existing])

  if (!isCreate && isLoading) return <LoadingState />
  if (!isCreate && listError) return <ErrorMessage error={listError} fallback="申請種別の取得に失敗しました。" />

  const isBusy = createType.isPending || updateType.isPending
  const error = createType.error ?? updateType.error

  const updateRow = (rowId: number, patch: Partial<RequestFormFieldSchema>) => {
    setRows((prev) => prev.map((row) => (row.rowId === rowId ? { ...row, ...patch } : row)))
  }

  const handleSave = async () => {
    const input = {
      code,
      name,
      description: description || undefined,
      form_schema: rows.map(({ rowId, ...field }) => {
        void rowId
        return field
      }),
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

      <FormField label="コード" htmlFor="code" required>
        <input id="code" value={code} disabled={!isCreate} onChange={(e) => setCode(e.target.value)} />
      </FormField>

      <FormField label="名称" htmlFor="name" required>
        <input id="name" value={name} onChange={(e) => setName(e.target.value)} />
      </FormField>

      <FormField label="説明" htmlFor="description">
        <textarea id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
      </FormField>

      <FormField label="バックオフィス処理を発生させる" htmlFor="requires-backoffice-task">
        <input
          id="requires-backoffice-task"
          type="checkbox"
          checked={requiresBackOfficeTask}
          onChange={(e) => setRequiresBackOfficeTask(e.target.checked)}
        />
      </FormField>

      {requiresBackOfficeTask && (
        <FormField label="バックオフィスタスク種別" htmlFor="backoffice-task-type">
          <input
            id="backoffice-task-type"
            value={backOfficeTaskType}
            onChange={(e) => setBackOfficeTaskType(e.target.value)}
          />
        </FormField>
      )}

      <FormField label="有効" htmlFor="is-active">
        <input id="is-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
      </FormField>

      <h3>入力項目</h3>
      <ul className="request-type-edit__schema-rows">
        {rows.map((row) => (
          <li key={row.rowId} className="request-type-edit__schema-row">
            <input
              placeholder="キー"
              value={row.key}
              onChange={(e) => updateRow(row.rowId, { key: e.target.value })}
            />
            <input
              placeholder="ラベル"
              value={row.label}
              onChange={(e) => updateRow(row.rowId, { label: e.target.value })}
            />
            <select
              value={row.type}
              onChange={(e) => updateRow(row.rowId, { type: e.target.value as RequestFormFieldSchema['type'] })}
            >
              <option value="text">テキスト</option>
              <option value="number">数値</option>
              <option value="date">日付</option>
            </select>
            <label>
              <input
                type="checkbox"
                checked={row.required ?? false}
                onChange={(e) => updateRow(row.rowId, { required: e.target.checked })}
              />
              必須
            </label>
            <Button
              variant="danger"
              onClick={() => setRows((prev) => prev.filter((r) => r.rowId !== row.rowId))}
            >
              削除
            </Button>
          </li>
        ))}
      </ul>
      <Button
        variant="secondary"
        onClick={() => setRows((prev) => [...prev, { rowId: nextRowId++, key: '', label: '', type: 'text' }])}
      >
        項目を追加
      </Button>

      <div className="request-type-edit__actions">
        <Button isLoading={isBusy} disabled={!code || !name} onClick={() => void handleSave()}>
          保存する
        </Button>
      </div>
    </Card>
  )
}
