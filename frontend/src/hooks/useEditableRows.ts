import { useCallback, useRef, useState } from 'react'

export type EditableRow<T> = T & { rowId: number }

/**
 * 動的に行を追加・編集・削除するフォーム(申請種別のform_schemaエディタ、
 * カレンダー日別編集など)向けの共通state。rowIdはReactのkeyとuseState更新の
 * 両方に使うため、コンポーネント側で個別に採番ロジックを持たせない。
 * 返す関数はuseCallbackで安定させ、呼び出し側のuseEffect依存配列に安全に含められるようにする。
 */
export function useEditableRows<T extends object>(initialRows: T[] = []) {
  const nextIdRef = useRef(0)
  const [rows, setRows] = useState<EditableRow<T>[]>(() =>
    initialRows.map((row) => ({ ...row, rowId: nextIdRef.current++ })),
  )

  const addRow = useCallback((row: T) => {
    setRows((prev) => [...prev, { ...row, rowId: nextIdRef.current++ }])
  }, [])

  const updateRow = useCallback((rowId: number, patch: Partial<T>) => {
    setRows((prev) => prev.map((row) => (row.rowId === rowId ? { ...row, ...patch } : row)))
  }, [])

  const removeRow = useCallback((rowId: number) => {
    setRows((prev) => prev.filter((row) => row.rowId !== rowId))
  }, [])

  const reset = useCallback((newRows: T[]) => {
    setRows(newRows.map((row) => ({ ...row, rowId: nextIdRef.current++ })))
  }, [])

  const toData = useCallback(
    (): T[] =>
      rows.map((row) => {
        const { rowId, ...rest } = row
        void rowId
        return rest as T
      }),
    [rows],
  )

  return { rows, addRow, updateRow, removeRow, reset, toData }
}
