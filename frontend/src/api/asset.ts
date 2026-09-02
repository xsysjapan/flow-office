import { ApiError, apiFetch } from './client'
import type {
  Asset,
  AssetLendingStatus,
  AssetInstallationStatus,
  AssetLendingMethod,
  AssetLoan,
  AssetLoanEligibility,
  AssetLoanRequest,
  AssetLoanRequestStatus,
  AssetManagementType,
  Paginated,
  StoredEvent,
} from './types'

/**
 * 備品検索(spec「検索」相当。管理番号・名称・カテゴリ・シリアル番号・管理区分・状態・
 * 貸出先・通常配置場所・現在設置場所で絞り込める。`backend/.../Asset/AssetController::index`)。
 */
export interface SearchAssetsParams {
  q?: string
  asset_no?: string
  name?: string
  category?: string
  serial_number?: string
  management_type?: AssetManagementType
  lending_status?: AssetLendingStatus
  installation_status?: AssetInstallationStatus
  borrower_user_id?: string
  default_location_text?: string
  current_location_text?: string
  page?: number
  per_page?: number
}

export function searchAssets(params: SearchAssetsParams = {}): Promise<Paginated<Asset>> {
  const { page, ...rest } = params
  return apiFetch('/assets', { query: { ...rest, page } })
}

export function getAsset(id: string): Promise<Asset> {
  return apiFetch(`/assets/${id}`)
}

export function getAssetByQrToken(token: string): Promise<Asset> {
  return apiFetch(`/assets/by-qr/${token}`)
}

export function getAssetHistory(id: string): Promise<StoredEvent[]> {
  return apiFetch(`/assets/${id}/history`)
}

export function getUserAssetLoans(userId: string): Promise<AssetLoan[]> {
  return apiFetch(`/users/${userId}/asset-loans`)
}

export function getAssetLoanEligibility(assetId: string, borrowerUserId?: string): Promise<AssetLoanEligibility> {
  return apiFetch(`/assets/${assetId}/loan-eligibility`, {
    query: borrowerUserId ? { borrower_user_id: borrowerUserId } : undefined,
  })
}

/**
 * 貸与時の申請選択UI(spec 論点2-3)。対象資産・借用者に紐づく貸出申請一覧を取得する
 * (`asset.manage`権限保有者のみ呼び出せる。backend/.../Asset/AssetController::loanRequests)。
 */
export function getAssetLoanRequests(
  assetId: string,
  options: { status?: AssetLoanRequestStatus; borrowerUserId?: string } = {},
): Promise<AssetLoanRequest[]> {
  return apiFetch(`/assets/${assetId}/loan-requests`, {
    query: { status: options.status, borrower_user_id: options.borrowerUserId },
  })
}

/**
 * QRの中身がURL形式(`.../assets/qr/{token}`)の場合に末尾のtokenを抽出する(spec 論点7-2・
 * 論点12)。URL形式でなければそのまま返す(QRトークンの直接文字列・管理番号の場合)。
 */
function extractQrToken(input: string): string {
  const match = input.match(/\/assets\/qr\/([^/?#]+)\/?$/)
  return match ? decodeURIComponent(match[1]) : input
}

/**
 * QR一括操作画面(spec「50. QR操作画面」「一括QR操作API」)でのスキャン(または管理番号
 * 手入力)1件分の解決処理。QRの中身はURL形式(`.../assets/qr/{token}`)・QRトークン文字列
 * いずれの場合もまずトークンとして解決を試み、404の場合は管理番号の完全一致検索に
 * フォールバックする(spec 31番「QR以外の操作」: 管理番号入力でも同じ操作ができること)。
 * どちらでも見つからない場合はErrorを投げる。
 */
export async function resolveAssetByScanInput(input: string): Promise<Asset> {
  const trimmed = input.trim()
  if (!trimmed) throw new Error('管理番号またはQRトークンを入力してください。')

  const token = extractQrToken(trimmed)

  try {
    return await getAssetByQrToken(token)
  } catch (e) {
    if (!(e instanceof ApiError) || e.status !== 404) throw e
  }

  const found = await searchAssets({ asset_no: trimmed, per_page: 5 })
  const exact = found.data.find((asset) => asset.asset_no === trimmed)
  if (exact) return exact

  throw new Error(`管理番号またはQRトークン「${trimmed}」に一致する備品が見つかりませんでした。`)
}

/**
 * 以下、業務操作(spec「実装対象」)。`backend/.../Asset/AssetController.php`の各エンドポイントに
 * 1関数ずつ対応する。バリデーション自体はバックエンドが行うため、ここではペイロードの整形のみ行う。
 */
export interface RegisterAssetInput {
  /** null(または未指定)の場合、カテゴリに対応する自動採番ルールでバックエンドが確定する
   *  (docs/changesets/20260831-asset-management-refinement/spec.md 論点4)。 */
  asset_no?: string | null
  name: string
  category: string
  serial_number?: string | null
  management_type: AssetManagementType
  lending_method?: AssetLendingMethod | null
  default_location_text?: string | null
  notes?: string | null
}

export function registerAsset(input: RegisterAssetInput): Promise<Asset> {
  return apiFetch('/assets', { method: 'POST', body: input })
}

export interface UpdateAssetDetailsInput {
  name: string
  category: string
  serial_number?: string | null
  notes?: string | null
}

export function updateAssetDetails(id: string, input: UpdateAssetDetailsInput): Promise<Asset> {
  return apiFetch(`/assets/${id}`, { method: 'PATCH', body: input })
}

export function deleteAsset(id: string): Promise<void> {
  return apiFetch(`/assets/${id}`, { method: 'DELETE' })
}

export function changeAssetManagementType(id: string, managementType: AssetManagementType): Promise<Asset> {
  return apiFetch(`/assets/${id}/management-type`, { method: 'POST', body: { management_type: managementType } })
}

export function changeAssetLendingMethod(id: string, lendingMethod: AssetLendingMethod): Promise<Asset> {
  return apiFetch(`/assets/${id}/lending-method`, { method: 'POST', body: { lending_method: lendingMethod } })
}

export function reissueAssetQrCode(id: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/qr-code/reissue`, { method: 'POST' })
}

export function setAssetDefaultLocation(id: string, locationText: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/default-location`, { method: 'POST', body: { location_text: locationText } })
}

export interface LendAssetInput {
  borrower_user_id: string
  expected_return_at?: string | null
  loan_request_id?: string | null
}

export function lendAsset(id: string, input: LendAssetInput): Promise<Asset> {
  return apiFetch(`/assets/${id}/lend`, { method: 'POST', body: input })
}

export interface ReturnAssetInput {
  loan_id?: string | null
  return_note?: string | null
}

export function returnAsset(id: string, input: ReturnAssetInput = {}): Promise<Asset> {
  return apiFetch(`/assets/${id}/return`, { method: 'POST', body: input })
}

export function installAsset(id: string, locationText: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/install`, { method: 'POST', body: { location_text: locationText } })
}

export function relocateAsset(id: string, locationText: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/relocate`, { method: 'POST', body: { location_text: locationText } })
}

export function removeAssetFromInstallation(id: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/remove-from-installation`, { method: 'POST' })
}

export function startAssetRepair(id: string, note?: string | null): Promise<Asset> {
  return apiFetch(`/assets/${id}/repair/start`, { method: 'POST', body: { note: note ?? null } })
}

export function completeAssetRepair(id: string, note?: string | null): Promise<Asset> {
  return apiFetch(`/assets/${id}/repair/complete`, { method: 'POST', body: { note: note ?? null } })
}

export function reportAssetLost(id: string, note?: string | null): Promise<Asset> {
  return apiFetch(`/assets/${id}/lost`, { method: 'POST', body: { note: note ?? null } })
}

export function recoverAssetFromLost(id: string): Promise<Asset> {
  return apiFetch(`/assets/${id}/recover`, { method: 'POST' })
}

export function disposeAsset(id: string, note?: string | null): Promise<Asset> {
  return apiFetch(`/assets/${id}/dispose`, { method: 'POST', body: { note: note ?? null } })
}

/**
 * QR一括操作(spec「一括QR操作API」/論点8)。`POST /assets/bulk`を1回だけ呼び、対象
 * asset_id配列をまとめて送る。バックエンドは1備品=1Aggregateとしてループで処理するため、
 * 部分成功(一部だけ失敗)がありうる。
 */
export type AssetBulkOperationType = 'self_loan' | 'self_return' | 'backoffice_lend' | 'return' | 'relocate'

export interface AssetBulkOperationInput {
  operation: AssetBulkOperationType
  asset_ids: string[]
  borrower_user_id?: string
  expected_return_at?: string | null
  location_text?: string
  return_note?: string | null
}

export interface AssetBulkOperationResultItem {
  asset_id: string
  success: boolean
  result?: string
  error?: string
}

export interface AssetBulkOperationResult {
  operation: AssetBulkOperationType
  results: AssetBulkOperationResultItem[]
  succeeded_count: number
  failed_count: number
}

export function bulkAssetOperation(input: AssetBulkOperationInput): Promise<AssetBulkOperationResult> {
  return apiFetch('/assets/bulk', { method: 'POST', body: input })
}
