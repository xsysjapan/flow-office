import { createContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { fetchPublicSystemSettings } from '../api/publicSystemSettings'
import { useAuth } from '../auth/useAuth'
import type { PublicSystemSettings } from '../api/types'

/**
 * フェッチ未完了・失敗時のフォールバック値。承認必須(true)側に倒しておくことで、
 * 設定取得前後の挙動を既存の(承認必須の)動作と一致させる。それ以外の項目はbackendの
 * SystemSettingモデルの既定値と合わせたフェイルオープンな値にしておく。
 */
const DEFAULT_SYSTEM_SETTINGS: PublicSystemSettings = {
  paid_leave_requires_approval: true,
  special_leave_requires_approval: true,
  shift_swap_requires_approval: true,
  attendance_requires_approval: true,
  expense_claim_requires_approval: true,
  default_timezone: 'Asia/Tokyo',
  default_work_style_id: null,
  default_work_style: null,
  attendance_submission_deadline_day: 5,
  attendance_month_close_deadline_day: 10,
}

export interface AppSettingsContextValue {
  systemSettings: PublicSystemSettings
  isLoading: boolean
}

/**
 * デフォルト値自体が安全なフォールバックのため、AuthContextと異なりnullをデフォルトに
 * しない(Provider外で使ってもフェイルオープンな既定値が返る)。
 */
export const AppSettingsContext = createContext<AppSettingsContextValue>({
  systemSettings: DEFAULT_SYSTEM_SETTINGS,
  isLoading: true,
})

export function AppSettingsProvider({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  const [systemSettings, setSystemSettings] = useState<PublicSystemSettings>(DEFAULT_SYSTEM_SETTINGS)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    // `/system-settings`(フロントエンドの初期化に必要な設定一式)は認証必須のため、
    // ログイン確定前に叩いても401になるだけ。
    if (status === 'loading') return

    if (status !== 'authenticated') {
      setIsLoading(false)
      return
    }

    setIsLoading(true)
    fetchPublicSystemSettings()
      .then((settings) => {
        setSystemSettings(settings)
      })
      .catch(() => {
        // フェイルオープン: 取得できなければ既定値(承認必須など)のままにする。
      })
      .finally(() => {
        setIsLoading(false)
      })
  }, [status])

  const value = useMemo(() => ({ systemSettings, isLoading }), [systemSettings, isLoading])

  return <AppSettingsContext.Provider value={value}>{children}</AppSettingsContext.Provider>
}
