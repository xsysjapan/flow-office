import { ApiError } from '../api/client'

export interface BulkGrantResult {
  userId: string
  success: boolean
  message?: string
}

/**
 * 有給/特別休暇/代休の手動付与フォームで共通利用する一括実行ヘルパー。
 * 対象ユーザーごとに独立して成否が決まりうる(例: 代休の実績日が休日出勤でなく422になる等)ため、
 * `Promise.all`ではなく`Promise.allSettled`で1件ずつの成否を保持する。
 */
export async function runBulkGrant(
  userIds: string[],
  grant: (userId: string) => Promise<unknown>,
): Promise<BulkGrantResult[]> {
  const settled = await Promise.allSettled(userIds.map((userId) => grant(userId)))

  return settled.map((result, index) => {
    const userId = userIds[index]
    if (result.status === 'fulfilled') {
      return { userId, success: true }
    }
    const reason = result.reason
    const message =
      reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : '付与に失敗しました。'
    return { userId, success: false, message }
  })
}
