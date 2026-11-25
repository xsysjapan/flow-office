import { useSyncExternalStore } from 'react'

/**
 * 旧い形式のイベントの補正待ち(503, code=correction_pending)の状態。
 * 全画面のメンテナンス表示(maintenance)とは別に、画面上部の案内として出すために保持する。
 * `message` は APIが返した本文。補正待ちでなければ null。
 */
export interface CorrectionPendingState {
  message: string | null
}

let state: CorrectionPendingState = { message: null }
const listeners = new Set<() => void>()

function emit(): void {
  listeners.forEach((listener) => listener())
}

export function getCorrectionPending(): CorrectionPendingState {
  return state
}

export function setCorrectionPending(message: string): void {
  if (state.message === message) return
  state = { message }
  emit()
}

export function clearCorrectionPending(): void {
  if (state.message === null) return
  state = { message: null }
  emit()
}

export function subscribeCorrectionPending(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function useCorrectionPending(): CorrectionPendingState {
  return useSyncExternalStore(subscribeCorrectionPending, getCorrectionPending, getCorrectionPending)
}

/**
 * 補正待ちの間もbackendが通すリクエスト。これらの成功は「補正待ちが終わった」ことの証拠にならないので、
 * 状態を解除する判断に使わない(使うと案内が点滅する)。
 */
export function isAllowedDuringCorrectionPending(path: string, method: string = 'GET'): boolean {
  const normalized = path.replace(/^\//, '').replace(/\/$/, '')
  const verb = method.toUpperCase()
  if (normalized === 'auth' || normalized.startsWith('auth/')) return true
  if (verb === 'GET') {
    return ['maintenance-status', 'access/me', 'onboarding/status', 'admin/commands', 'admin/command-runs'].includes(normalized)
  }
  return verb === 'POST' && /^admin\/commands\/[^/]+\/runs$/.test(normalized)
}
