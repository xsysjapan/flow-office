import { useContext } from 'react'
import { AppSettingsContext, type AppSettingsContextValue } from './AppSettingsContext'

/**
 * AppSettingsContextのデフォルト値はnullではなく安全なフォールバック(承認必須)なので、
 * useAuthと違いProvider外での使用をエラーにする必要はない。
 */
export function useAppSettings(): AppSettingsContextValue {
  return useContext(AppSettingsContext)
}
