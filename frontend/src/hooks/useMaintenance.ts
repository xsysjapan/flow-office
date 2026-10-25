import { useQuery } from '@tanstack/react-query'
import { fetchMaintenanceStatus } from '../api/maintenance'

/** メンテナンス表示中に状態を確認する間隔(ms)。 */
export const MAINTENANCE_STATUS_POLL_INTERVAL_MS = 15000

/** メンテナンス中かどうか。enabled の間(メンテナンス表示中)だけ15秒ごとに読む。 */
export function useMaintenanceStatus(enabled: boolean) {
  return useQuery({
    queryKey: ['maintenance-status'],
    queryFn: fetchMaintenanceStatus,
    enabled,
    refetchInterval: MAINTENANCE_STATUS_POLL_INTERVAL_MS,
    // 503以外の失敗でも次のポーリングで再試行する
    retry: false,
  })
}
