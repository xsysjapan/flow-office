import { useEffect, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { MAINTENANCE_EVENT } from '../../api/client'
import { MaintenanceNotice } from '../MaintenanceNotice/MaintenanceNotice'

/** 運用コマンドの画面。作り直しの進み具合を見続けられるように、ここでは全画面のメンテナンス表示を出さない。 */
export const COMMANDS_PAGE_PATH = '/admin/commands'

export interface MaintenanceGateProps {
  children: ReactNode
}

/**
 * どこかのAPIがメンテナンス(503, code=maintenance)を返したら、全画面を「メンテナンス中」表示に切り替える。
 * apiFetch が window へ送るイベントを購読する。
 */
export function MaintenanceGate({ children }: MaintenanceGateProps) {
  const [message, setMessage] = useState<string | null>(null)
  const { pathname } = useLocation()

  useEffect(() => {
    const handler = (event: Event) => setMessage((event as CustomEvent<string>).detail)
    window.addEventListener(MAINTENANCE_EVENT, handler)
    return () => window.removeEventListener(MAINTENANCE_EVENT, handler)
  }, [])

  if (message !== null && pathname.replace(/\/$/, '') !== COMMANDS_PAGE_PATH) {
    return <MaintenanceNotice message={message} />
  }

  return <>{children}</>
}
