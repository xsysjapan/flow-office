import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useEditableRows } from './useEditableRows'

interface Step {
  label: string
  days: number
}

describe('useEditableRows', () => {
  it('assigns a unique rowId to each initial row', () => {
    const { result } = renderHook(() => useEditableRows<Step>([{ label: 'a', days: 1 }, { label: 'b', days: 2 }]))

    expect(result.current.rows).toHaveLength(2)
    expect(result.current.rows[0].rowId).not.toBe(result.current.rows[1].rowId)
  })

  it('appends a new row with addRow', () => {
    const { result } = renderHook(() => useEditableRows<Step>([]))

    act(() => result.current.addRow({ label: 'c', days: 3 }))

    expect(result.current.rows).toHaveLength(1)
    expect(result.current.rows[0]).toMatchObject({ label: 'c', days: 3 })
  })

  it('updates only the matching row', () => {
    const { result } = renderHook(() => useEditableRows<Step>([{ label: 'a', days: 1 }, { label: 'b', days: 2 }]))
    const [first, second] = result.current.rows

    act(() => result.current.updateRow(first.rowId, { days: 99 }))

    expect(result.current.rows.find((r) => r.rowId === first.rowId)?.days).toBe(99)
    expect(result.current.rows.find((r) => r.rowId === second.rowId)?.days).toBe(2)
  })

  it('removes a row by rowId', () => {
    const { result } = renderHook(() => useEditableRows<Step>([{ label: 'a', days: 1 }, { label: 'b', days: 2 }]))
    const [first] = result.current.rows

    act(() => result.current.removeRow(first.rowId))

    expect(result.current.rows).toHaveLength(1)
    expect(result.current.rows[0]).toMatchObject({ label: 'b', days: 2 })
  })

  it('replaces all rows with reset', () => {
    const { result } = renderHook(() => useEditableRows<Step>([{ label: 'a', days: 1 }]))

    act(() => result.current.reset([{ label: 'x', days: 10 }, { label: 'y', days: 20 }]))

    expect(result.current.rows).toHaveLength(2)
    expect(result.current.rows.map((r) => r.label)).toEqual(['x', 'y'])
  })

  it('strips rowId when exporting via toData', () => {
    const { result } = renderHook(() => useEditableRows<Step>([{ label: 'a', days: 1 }]))

    expect(result.current.toData()).toEqual([{ label: 'a', days: 1 }])
  })
})
