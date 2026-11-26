import { createContext, useContext } from 'react'

/** `FormField`が入力部品へ配る値。`FormField`の外では`null`(何も付けない)。 */
export interface FormFieldContextValue {
  /** `FormField`の`htmlFor`。これと一致するidの入力にだけ属性を付ける。 */
  id: string
  required: boolean
  /** 説明文・エラーのid(`{id}-description`・`{id}-error`)をスペースで連結したもの。無ければundefined。 */
  describedBy: string | undefined
  invalid: boolean
  /** 必須の注記(sr-only)のid。必須でなければundefined。 */
  requiredNoteId: string | undefined
}

export const FormFieldContext = createContext<FormFieldContextValue | null>(null)

/** `native`: input・textarea・select・role=combobox/radiogroup/checkbox(aria-requiredを持てる)。`button`: Popoverのトリガー等(aria-requiredを持てないので必須の注記をdescribedbyの先頭に入れる)。 */
export type FormFieldControlKind = 'native' | 'button'

export interface FormFieldControlExplicit {
  'aria-describedby'?: string
  'aria-required'?: boolean | 'true' | 'false'
  'aria-invalid'?: boolean | 'true' | 'false' | 'grammar' | 'spelling'
}

export interface FormFieldControlProps {
  'aria-describedby'?: string
  'aria-required'?: boolean | 'true' | 'false'
  'aria-invalid'?: boolean | 'true' | 'false' | 'grammar' | 'spelling'
}

/**
 * 入力部品が、操作対象の要素に付ける`aria-*`を得る。`id`が`FormField`の`htmlFor`と一致するときだけ
 * 値を返す(context無し・不一致は空のオブジェクト)。呼び出し側が明示した値(`explicit`)は、
 * `aria-describedby`は後ろに連結し、`aria-required`・`aria-invalid`は明示を優先する。
 * 戻り値は、要素の`{...props}`の後ろに展開する。
 */
export function useFormFieldControl(
  id: string | undefined,
  kind: FormFieldControlKind,
  explicit: FormFieldControlExplicit = {},
): FormFieldControlProps {
  const context = useContext(FormFieldContext)
  if (!context || id === undefined || context.id !== id) return {}

  const describedBy = [kind === 'button' ? context.requiredNoteId : undefined, context.describedBy, explicit['aria-describedby']]
    .filter((value): value is string => Boolean(value))
    .join(' ')

  const result: FormFieldControlProps = {}
  if (describedBy) result['aria-describedby'] = describedBy
  if (kind === 'native') {
    const required = explicit['aria-required'] ?? (context.required ? 'true' : undefined)
    if (required !== undefined) result['aria-required'] = required
  }
  const invalid = explicit['aria-invalid'] ?? (context.invalid ? 'true' : undefined)
  if (invalid !== undefined) result['aria-invalid'] = invalid
  return result
}
