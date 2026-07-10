import { useState } from 'react'
import { useUsers } from '../../hooks/useUsers'
import './UserPicker.css'

export interface UserPickerProps {
  id: string
  value: number | undefined
  onChange: (userId: number | undefined) => void
  placeholder?: string
}

/**
 * 氏名/メールアドレスで検索して社員を1人選ぶ入力。承認者指定・担当者割り当て・
 * 有給付与対象者選択など、社員IDを1件確定させたい場面で共通利用する。
 */
export function UserPicker({ id, value, onChange, placeholder = '氏名またはメールアドレスで検索' }: UserPickerProps) {
  const [query, setQuery] = useState('')
  const { data } = useUsers(query)
  const suggestions = query && !value ? data?.data ?? [] : []

  return (
    <div className="fo-user-picker">
      <input
        id={id}
        placeholder={placeholder}
        value={query}
        onChange={(e) => {
          setQuery(e.target.value)
          onChange(undefined)
        }}
      />
      {suggestions.length > 0 && (
        <ul className="fo-user-picker__suggestions">
          {suggestions.map((user) => (
            <li key={user.id}>
              <button
                type="button"
                onClick={() => {
                  onChange(user.id)
                  setQuery(user.name)
                }}
              >
                {user.name}({user.email})
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
