import { apiFetch } from './client'
import type {
  Asset,
  AssetLendingStatus,
  AssetInstallationStatus,
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
