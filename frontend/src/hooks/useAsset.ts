import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  getAsset,
  getAssetByQrToken,
  getAssetHistory,
  getUserAssetLoans,
  searchAssets,
  type SearchAssetsParams,
} from '../api/asset'

const ASSETS_KEY = ['assets']

/** 検索条件をそのままクエリキーに使う(ページ・フィルターの組み合わせごとにキャッシュする)。 */
export function useAssetSearch(params: SearchAssetsParams) {
  return useQuery({
    queryKey: [...ASSETS_KEY, 'search', params],
    queryFn: () => searchAssets(params),
    placeholderData: keepPreviousData,
  })
}

export function useAsset(id: string | undefined) {
  return useQuery({
    queryKey: [...ASSETS_KEY, id],
    queryFn: () => getAsset(id as string),
    enabled: id !== undefined,
  })
}

export function useAssetByQrToken(token: string | undefined) {
  return useQuery({
    queryKey: [...ASSETS_KEY, 'by-qr', token],
    queryFn: () => getAssetByQrToken(token as string),
    enabled: token !== undefined,
  })
}

export function useAssetHistory(id: string | undefined) {
  return useQuery({
    queryKey: [...ASSETS_KEY, id, 'history'],
    queryFn: () => getAssetHistory(id as string),
    enabled: id !== undefined,
  })
}

export function useUserAssetLoans(userId: string | undefined) {
  return useQuery({
    queryKey: ['users', userId, 'asset-loans'],
    queryFn: () => getUserAssetLoans(userId as string),
    enabled: userId !== undefined,
  })
}
