/**
 * 勤怠の日の編集・作成・計算の補正フォームの「変更の有無」(離脱警告 `ui-interaction-patterns` §2.9)。
 * 比べる元(読み込んだ値。新規なら初期値)と今の入力を比べる純粋関数。
 * 打刻の追加・訂正・削除(即時に保存される操作)は含めない。
 */

export interface DayBreakRowValue {
  start: string
  end: string
}

export interface DayLeaveSegmentRowValue {
  start: string
  end: string
  note: string
}

/** 休暇欄の入力(休暇の指定。保存時に取消・申請として別に送られる)。 */
export interface DayLeaveValues {
  kind: string
  unit: string
  hours: string
  specialLeaveTypeId: number | undefined
  approverUserId: string | undefined
  leaveReason: string
}

export interface DayFormValues {
  actualStartAt: string
  actualEndAt: string
  offset: string
  workType: string
  workLocationType: string
  note: string
  reason: string
  breaks: DayBreakRowValue[]
  leaveSegments: DayLeaveSegmentRowValue[]
  leave: DayLeaveValues
}

function sameRows<T extends object>(a: T[], b: T[], keys: Array<keyof T>): boolean {
  return a.length === b.length && a.every((row, index) => keys.every((key) => row[key] === b[index][key]))
}

/** 勤怠の日の編集・作成フォーム。 */
export function isDayFormDirty(base: DayFormValues, current: DayFormValues): boolean {
  return (
    base.actualStartAt !== current.actualStartAt ||
    base.actualEndAt !== current.actualEndAt ||
    base.offset !== current.offset ||
    base.workType !== current.workType ||
    base.workLocationType !== current.workLocationType ||
    base.note !== current.note ||
    base.reason !== current.reason ||
    !sameRows(base.breaks, current.breaks, ['start', 'end']) ||
    !sameRows(base.leaveSegments, current.leaveSegments, ['start', 'end', 'note']) ||
    base.leave.kind !== current.leave.kind ||
    base.leave.unit !== current.leave.unit ||
    base.leave.hours !== current.leave.hours ||
    base.leave.specialLeaveTypeId !== current.leave.specialLeaveTypeId ||
    base.leave.approverUserId !== current.leave.approverUserId ||
    base.leave.leaveReason !== current.leave.leaveReason
  )
}

export interface CalculationAdjustValues {
  fields: Record<string, string>
  reason: string
}

/** 計算の補正フォーム。 */
export function isCalculationAdjustDirty(base: CalculationAdjustValues, current: CalculationAdjustValues): boolean {
  return (
    base.reason !== current.reason ||
    Object.keys({ ...base.fields, ...current.fields }).some((key) => base.fields[key] !== current.fields[key])
  )
}
