import { apiFetch } from './client'
import type {
  Asset,
  AssetLendingStatus,
  AssetInstallationStatus,
  AssetLendingMethod,
  AssetLoan,
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

/**
 * 以下、業務操作(spec「実装対象」)。`backend/.../Asset/AssetController.php`の各エンドポイントに
 * 1関数ずつ対応する。バリデーション自体はバックエンドが行うため、ここではペイロードの整形のみ行う。
 */
export interface RegisterAssetInput {
  asset_no: string
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
