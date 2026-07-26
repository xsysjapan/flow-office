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

  /** 指定行の直後に複製を挿入する(表形式一括入力の行複製、docs/30-usecases-expense.md UC-X006)。 */
  const duplicateRow = useCallback((rowId: number) => {
    setRows((prev) => {
      const index = prev.findIndex((row) => row.rowId === rowId)
      if (index === -1) return prev
      const copy: EditableRow<T> = { ...prev[index], rowId: nextIdRef.current++ }
      return [...prev.slice(0, index + 1), copy, ...prev.slice(index + 1)]
    })
  }, [])

  /** 複数行をまとめて末尾に追加する(表形式の複数行貼り付け、docs/30-usecases-expense.md UC-X006)。 */
  const appendRows = useCallback((newRows: T[]) => {
    setRows((prev) => [...prev, ...newRows.map((row) => ({ ...row, rowId: nextIdRef.current++ }))])
  }, [])

  /** 行を1つ上/下に並べ替える(docs/30-usecases-expense.md UC-X006)。 */
  const moveRow = useCallback((rowId: number, direction: 'up' | 'down') => {
    setRows((prev) => {
      const index = prev.findIndex((row) => row.rowId === rowId)
      const targetIndex = direction === 'up' ? index - 1 : index + 1
      if (index === -1 || targetIndex < 0 || targetIndex >= prev.length) return prev
      const next = [...prev]
      ;[next[index], next[targetIndex]] = [next[targetIndex], next[index]]
      return next
    })
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

  return { rows, addRow, updateRow, removeRow, reset, toData, duplicateRow, appendRows, moveRow }
}
