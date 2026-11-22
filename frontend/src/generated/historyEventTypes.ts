// このファイルは backend の `php artisan events:export-history-types` で生成する。手で編集しない。
// 履歴画面に表示するイベント種別の一覧(backend/config/event-sourcing.php の event_class_map から抽出)。
// 表示名・詳細文の網羅は frontend/src/utils/statusLabels.ts の型で検査する。

export const paidLeaveHistoryEventTypes = [
  'paid_leave_account.grant_created',
  'paid_leave_account.grant_amount_changed',
  'paid_leave_account.grant_date_changed',
  'paid_leave_account.grant_expiry_changed',
  'paid_leave_account.grant_revoked',
  'paid_leave_account.grant_warning_raised',
  'paid_leave_account.usage_designated',
  'paid_leave_account.usage_confirmed',
  'paid_leave_account.usage_cancelled',
  'paid_leave_account.usage_allocated',
  'paid_leave_account.usage_allocation_released',
  'paid_leave_request.requested',
  'paid_leave_request.shared',
  'paid_leave_request.approved',
  'paid_leave_request.returned',
  'paid_leave_request.resubmitted',
  'paid_leave_request.cancelled',
] as const
export type PaidLeaveHistoryEventType = (typeof paidLeaveHistoryEventTypes)[number]

export const specialLeaveHistoryEventTypes = [
  'special_leave.requested',
  'special_leave.request_approved',
  'special_leave.request_returned',
  'special_leave.request_cancelled',
  'special_leave.request_resubmitted',
  'special_leave.request_shared',
  'special_leave_account.grant_registered',
  'special_leave_account.grant_amount_changed',
  'special_leave_account.grant_revoked',
  'special_leave_account.usage_designated',
  'special_leave_account.usage_confirmed',
  'special_leave_account.usage_cancelled',
] as const
export type SpecialLeaveHistoryEventType = (typeof specialLeaveHistoryEventTypes)[number]

export const compensatoryLeaveHistoryEventTypes = [
  'compensatory_leave_account.grant_synced',
  'compensatory_leave_account.grant_removed',
  'compensatory_leave_account.grant_confirmed',
  'compensatory_leave_account.grant_manually_granted',
  'compensatory_leave_account.grant_cancelled',
  'compensatory_leave_account.usage_designated',
  'compensatory_leave_account.usage_confirmed',
  'compensatory_leave_account.usage_cancelled',
  'compensatory_leave.requested',
  'compensatory_leave.request_shared',
  'compensatory_leave.request_approved',
  'compensatory_leave.request_returned',
  'compensatory_leave.request_cancelled',
  'compensatory_leave.request_resubmitted',
] as const
export type CompensatoryLeaveHistoryEventType = (typeof compensatoryLeaveHistoryEventTypes)[number]

export const assetHistoryEventTypes = [
  'asset.registered',
  'asset.details_updated',
  'asset.deleted',
  'asset.management_type_changed',
  'asset.lending_method_changed',
  'asset.qr_code_reissued',
  'asset.default_location_set',
  'asset.loaned',
  'asset.returned',
  'asset.installed',
  'asset.relocated',
  'asset.removed_from_installation',
  'asset.repair_started',
  'asset.repair_completed',
  'asset.reported_lost',
  'asset.recovered_from_lost',
  'asset.disposed',
] as const
export type AssetHistoryEventType = (typeof assetHistoryEventTypes)[number]
