import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchMaintenanceStatus,
  fetchReadModelRebuild,
  fetchReadModelRebuilds,
  requestReadModelRebuild,
  type ReadModelRebuildRun,
} from '../api/readModelRebuilds'

/** 実行中・待機中の記録がある間、一覧を取り直す間隔(ms)。 */
export const READ_MODEL_REBUILD_POLL_INTERVAL_MS = 5000
/** メンテナンス表示中に状態を確認する間隔(ms)。 */
export const MAINTENANCE_STATUS_POLL_INTERVAL_MS = 15000

const LIST_KEY = ['read-model-rebuilds', 'list']

export function isActiveReadModelRebuild(run: ReadModelRebuildRun): boolean {
  return run.status === 'queued' || run.status === 'running'
}

export function useReadModelRebuilds() {
  return useQuery({
    queryKey: LIST_KEY,
    queryFn: fetchReadModelRebuilds,
    refetchInterval: (query) =>
      query.state.data?.data.some(isActiveReadModelRebuild) ? READ_MODEL_REBUILD_POLL_INTERVAL_MS : false,
  })
}

export function useReadModelRebuild(id: string | null) {
  return useQuery({
    queryKey: ['read-model-rebuilds', 'detail', id],
    queryFn: () => fetchReadModelRebuild(id as string),
    enabled: id !== null,
  })
}

export function useRequestReadModelRebuild() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (dryRun: boolean) => requestReadModelRebuild(dryRun),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: LIST_KEY }),
  })
}

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
