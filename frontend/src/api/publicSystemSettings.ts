import { apiFetch } from './client'
import type { PublicSystemSettings } from './types'

/**
 * フロントエンドの初期化時に必要なシステム設定一式(有給・特別休暇の承認要否、既定タイムゾーン、
 * 既定の働き方、勤怠提出・締め切日など)。管理者権限不要で認証済みユーザーなら誰でも参照できる。
 */
export function fetchPublicSystemSettings(): Promise<PublicSystemSettings> {
  return apiFetch('/system-settings')
}
