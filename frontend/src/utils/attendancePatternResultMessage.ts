import type { GenerateAttendancePatternResult } from '../api/attendance'

/** 週次・月次一括入力の確定結果を、呼び出し元画面に表示する完了メッセージ文にする。 */
export function attendancePatternResultMessage(result: GenerateAttendancePatternResult): string {
  let message = `${result.created_count}件作成・${result.updated_count}件更新しました。`
  if (result.skipped_count > 0) message += `既存実績のため${result.skipped_count}件をスキップしました。`
  if (result.rejected_count > 0) message += `締め済み等のため${result.rejected_count}件は反映できませんでした。`
  return message
}
