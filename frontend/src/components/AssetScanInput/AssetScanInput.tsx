import { useState, type FormEvent } from 'react'
import { Button } from '../Button/Button'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'

export interface AssetScanInputProps {
  id: string
  /** 何を対象に追加しようとしているかの説明(例:「貸出対象に追加する備品」)。 */
  label: string
  /**
   * このリポジトリにはカメラ入力を使ったQRスキャン用の既存ライブラリ・コンポーネントが
   * 存在しない(実装時に確認済み)。カメラスキャン統合は別タスクとし、今回は「QRトークン
   * 文字列または管理番号を手入力してEnter(または追加ボタン)で対象に追加する」テキスト
   * 入力ベースのUIとする(spec 31番「QR以外の操作」: 管理番号入力でも同じ操作ができること)。
   */
  placeholder?: string
  onSubmit: (value: string) => Promise<void> | void
  isPending?: boolean
  disabled?: boolean
  disabledReason?: string
}

/**
 * QR一括操作画面共通のスキャン(代替: 手入力)入力欄。1件ずつ解決APIへ渡し、成功すれば
 * 呼び出し側が対象リストへ追加する。入力欄自体は成功後にクリアし、連続入力できるようにする。
 */
export function AssetScanInput({
  id,
  label,
  placeholder = 'QRトークンまたは管理番号を入力してEnter',
  onSubmit,
  isPending = false,
  disabled = false,
  disabledReason,
}: AssetScanInputProps) {
  const [value, setValue] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    if (!value.trim() || isPending || disabled) return
    await onSubmit(value)
    setValue('')
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-2">
      <FormField label={label} htmlFor={id}>
        <div className="flex gap-2">
          <Input
            id={id}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={placeholder}
            disabled={disabled}
            autoComplete="off"
          />
          <Button type="submit" variant="secondary" disabled={disabled || isPending || !value.trim()}>
            追加
          </Button>
        </div>
      </FormField>
      {disabled && disabledReason && <p className="text-sm text-muted-foreground">{disabledReason}</p>}
    </form>
  )
}
