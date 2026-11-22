import type { BackOfficeTaskStatus } from '../api/types'

/** バックオフィスタスクの全ステータス(詳細画面のステータス選択肢・一覧の絞り込みと同じ表示順) */
export const BACKOFFICE_TASK_STATUSES: BackOfficeTaskStatus[] = [
  'not_started',
  'in_review',
  'needs_fix',
  'processing',
  'ordered',
  'payment_scheduled',
  'shipped',
  'completed',
  'cancelled',
]
