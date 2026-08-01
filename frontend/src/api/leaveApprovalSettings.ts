import { apiFetch } from './client'
import type { LeaveApprovalSettings } from './types'

/**
 * 有給・特別休暇の申請に承認が必須かどうか。管理者権限不要で認証済みユーザーなら誰でも
 * 参照できる(申請フォームで承認者入力を必須にするかどうかの判定に使うため)。
 */
export function fetchLeaveApprovalSettings(): Promise<LeaveApprovalSettings> {
  return apiFetch('/leave-approval-settings')
}
