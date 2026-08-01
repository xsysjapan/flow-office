import { createContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { fetchLeaveApprovalSettings } from '../api/leaveApprovalSettings'
import { useAuth } from '../auth/useAuth'
import type { LeaveApprovalSettings } from '../api/types'

/**
 * フェッチ未完了・失敗時のフォールバック値。承認必須(true)側に倒しておくことで、
 * 設定取得前後の挙動を既存の(承認必須の)動作と一致させる。
 */
const DEFAULT_LEAVE_APPROVAL_SETTINGS: LeaveApprovalSettings = {
  paid_leave_requires_approval: true,
  special_leave_requires_approval: true,
}

export interface AppSettingsContextValue {
  leaveApprovalSettings: LeaveApprovalSettings
  isLoading: boolean
}

/**
 * デフォルト値自体が安全なフォールバックのため、AuthContextと異なりnullをデフォルトに
 * しない(Provider外で使ってもフェイルオープンな既定値が返る)。
 */
export const AppSettingsContext = createContext<AppSettingsContextValue>({
  leaveApprovalSettings: DEFAULT_LEAVE_APPROVAL_SETTINGS,
  isLoading: true,
})

export function AppSettingsProvider({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  const [leaveApprovalSettings, setLeaveApprovalSettings] = useState<LeaveApprovalSettings>(
    DEFAULT_LEAVE_APPROVAL_SETTINGS,
  )
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    // `/system-settings`(LeaveApprovalSettingsController)は認証必須のため、ログイン確定前に
    // 叩いても401になるだけ。
    if (status === 'loading') return

    if (status !== 'authenticated') {
      setIsLoading(false)
      return
    }

    setIsLoading(true)
    fetchLeaveApprovalSettings()
      .then((settings) => {
        setLeaveApprovalSettings(settings)
      })
      .catch(() => {
        // フェイルオープン: 取得できなければ既定値(承認必須)のままにする。
      })
      .finally(() => {
        setIsLoading(false)
      })
  }, [status])

  const value = useMemo(() => ({ leaveApprovalSettings, isLoading }), [leaveApprovalSettings, isLoading])

  return <AppSettingsContext.Provider value={value}>{children}</AppSettingsContext.Provider>
}
