import { ApiError } from '../api/client'

/**
 * 422のキー付きエラー(`errors.{項目キー}`)から、項目のキーに対応する最初のメッセージを取り出す。
 * `FormField`の`error`へ渡す(`ui-interaction-patterns` §2.18・§2.19)。
 */
export function fieldErrorOf(error: unknown, key: string): string | undefined {
  if (!(error instanceof ApiError)) return undefined
  return error.errors?.[key]?.[0]
}

/**
 * 項目の下に出したエラーを除いた、送信全体のエラー。ボタン行の直上の`ErrorMessage`に渡す。
 * キー付きのエラーが全て項目に対応している(`knownKeys`に含まれる)場合は、項目の下の表示に任せて`null`を返す。
 * キーの無いエラー(422以外・キーが項目に対応しないもの)だけが残る。
 */
export function nonFieldError(error: unknown, knownKeys: readonly string[]): Error | null {
  if (!error) return null
  if (!(error instanceof ApiError)) return error instanceof Error ? error : new Error(String(error))
  const entries = Object.entries(error.errors ?? {})
  if (entries.length === 0) return error
  const unknown = Object.fromEntries(entries.filter(([key]) => !knownKeys.includes(key)))
  if (Object.keys(unknown).length === 0) return null
  return new ApiError(error.status, error.message, unknown, error.code)
}
