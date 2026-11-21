// URLクエリの値を判定する小さな関数群。引数はURLから読んだ生の文字列(無ければnull)。
// null は常に false とする。

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const YEAR_MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/
const YEAR_PATTERN = /^\d{4}$/
const POSITIVE_INT_PATTERN = /^[1-9]\d*$/
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** YYYY-MM-DD で、暦に存在する日付か(2026-02-30 は false、2028-02-29 は true) */
export function isValidDateParam(v: string | null): boolean {
  if (v === null || !DATE_PATTERN.test(v)) return false
  const y = Number(v.slice(0, 4))
  const m = Number(v.slice(5, 7))
  const d = Number(v.slice(8, 10))
  // タイムゾーンのずれを避けるため、ローカルの年月日で組み立てて比較する
  const date = new Date(y, m - 1, d)
  return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d
}

/** YYYY-MM(月は01〜12) */
export function isValidYearMonthParam(v: string | null): boolean {
  return v !== null && YEAR_MONTH_PATTERN.test(v)
}

/** YYYY(4桁) */
export function isValidYearParam(v: string | null): boolean {
  return v !== null && YEAR_PATTERN.test(v)
}

/** 1以上の整数の10進表記(0・負数・小数・先頭0は false) */
export function isValidPageParam(v: string | null): boolean {
  return v !== null && POSITIVE_INT_PATTERN.test(v)
}

/** 正の整数ID。isValidPageParam と同じ条件 */
export const isValidPositiveIntIdParam = isValidPageParam

/** UUID(8-4-4-4-12桁の16進、大文字小文字は区別しない、版は問わない) */
export function isValidUuidParam(v: string | null): boolean {
  return v !== null && UUID_PATTERN.test(v)
}

/** values のいずれかと完全一致するか */
export function isOneOf<T extends string>(v: string | null, values: readonly T[]): v is T {
  return v !== null && (values as readonly string[]).includes(v)
}
