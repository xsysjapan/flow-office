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

const SUBMIT_ERROR_HEADING = '入力内容を確認してください。'

/** `fields`のパターン(`*`は数字だけの1区切り)をキーに照合する。 */
function matchesPattern(pattern: string, key: string): boolean {
  if (!pattern.includes('*')) return pattern === key
  const p = pattern.split('.')
  const k = key.split('.')
  if (p.length !== k.length) return false
  return p.every((seg, i) => (seg === '*' ? /^\d+$/.test(k[i]) : seg === k[i]))
}

export interface FieldErrors<K extends string> {
  /** 完全一致のキーの最初のメッセージ。 */
  of: (key: K) => string | undefined
  /** `{prefix}.{index}.{key}`(`key`省略で`{prefix}.{index}`)の最初のメッセージ。 */
  ofRow: (prefix: string, index: number, key?: string) => string | undefined
  /** `{prefix}`と`{prefix}.{数字}`のメッセージ全部(重複は除く)。 */
  ofAny: (prefix: string) => string[]
  /** 項目に出さないキーだけを残したエラー(`ErrorMessage`に渡す)。 */
  rest: unknown
}

/**
 * 422の項目エラーを項目の下へ振り分ける(`ui-interaction-patterns` §2.19)。
 * `fields`は項目に出すキー。`{prefix}.*.{key}`・`{prefix}.*`を書ける(`*`は数字1区切り)。
 */
export function fieldErrors<K extends string>(error: unknown, fields: readonly K[]): FieldErrors<K> {
  const errors = error instanceof ApiError ? (error.errors ?? {}) : {}
  const first = (key: string) => errors[key]?.[0]

  let rest: unknown = error ?? null
  if (error instanceof ApiError && error.status === 422 && error.errors) {
    const remaining = Object.fromEntries(
      Object.entries(error.errors).filter(([key]) => !fields.some((f) => matchesPattern(f, key))),
    )
    rest = new ApiError(error.status, SUBMIT_ERROR_HEADING, remaining, error.code)
  }

  return {
    of: (key) => first(key),
    ofRow: (prefix, index, key) => first(key === undefined ? `${prefix}.${index}` : `${prefix}.${index}.${key}`),
    ofAny: (prefix) => {
      const messages = Object.entries(errors)
        .filter(([key]) => key === prefix || matchesPattern(`${prefix}.*`, key))
        .flatMap(([, list]) => list)
      return [...new Set(messages)]
    },
    rest,
  }
}
