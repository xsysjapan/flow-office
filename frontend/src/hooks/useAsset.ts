import { keepPreviousData, useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import {
  bulkAssetOperation,
  changeAssetLendingMethod,
  changeAssetManagementType,
  completeAssetRepair,
  deleteAsset,
  disposeAsset,
  getAsset,
  getAssetByQrToken,
  getAssetHistory,
  getAssetLoanEligibility,
  getUserAssetLoans,
  installAsset,
  lendAsset,
  recoverAssetFromLost,
  registerAsset,
  reissueAssetQrCode,
  relocateAsset,
  removeAssetFromInstallation,
  reportAssetLost,
  resolveAssetByScanInput,
  returnAsset,
  searchAssets,
  setAssetDefaultLocation,
  startAssetRepair,
  updateAssetDetails,
  type AssetBulkOperationInput,
  type LendAssetInput,
  type RegisterAssetInput,
  type ReturnAssetInput,
  type SearchAssetsParams,
  type UpdateAssetDetailsInput,
} from '../api/asset'
import type { AssetLendingMethod, AssetManagementType } from '../api/types'

const ASSETS_KEY = ['assets']

/**
 * 業務操作(貸与・返却・設置・修理等)の成功後に無効化すべきクエリ(spec「実装対象」)。
 * 一覧・詳細・履歴のいずれからも古いデータが見え続けないようにする。
 */
function invalidateAssetQueries(queryClient: ReturnType<typeof useQueryClient>, assetId: string) {
  queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, 'search'] })
  queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, assetId] })
  queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, assetId, 'history'] })
}

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

/** 新規登録。成功時は一覧の再取得だけでよい(対象詳細はまだキャッシュに存在しない)。 */
export function useRegisterAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: RegisterAssetInput) => registerAsset(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, 'search'] })
    },
  })
}

export function useUpdateAssetDetails() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: UpdateAssetDetailsInput }) => updateAssetDetails(id, input),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useDeleteAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteAsset(id),
    onSuccess: (_data, id) => invalidateAssetQueries(queryClient, id),
  })
}

export function useChangeAssetManagementType() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, managementType }: { id: string; managementType: AssetManagementType }) =>
      changeAssetManagementType(id, managementType),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useChangeAssetLendingMethod() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, lendingMethod }: { id: string; lendingMethod: AssetLendingMethod }) =>
      changeAssetLendingMethod(id, lendingMethod),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useReissueAssetQrCode() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => reissueAssetQrCode(id),
    onSuccess: (_data, id) => invalidateAssetQueries(queryClient, id),
  })
}

export function useSetAssetDefaultLocation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, locationText }: { id: string; locationText: string }) => setAssetDefaultLocation(id, locationText),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useLendAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: LendAssetInput }) => lendAsset(id, input),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useReturnAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input?: ReturnAssetInput }) => returnAsset(id, input),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useInstallAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, locationText }: { id: string; locationText: string }) => installAsset(id, locationText),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useRelocateAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, locationText }: { id: string; locationText: string }) => relocateAsset(id, locationText),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useRemoveAssetFromInstallation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => removeAssetFromInstallation(id),
    onSuccess: (_data, id) => invalidateAssetQueries(queryClient, id),
  })
}

export function useStartAssetRepair() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string | null }) => startAssetRepair(id, note),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useCompleteAssetRepair() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string | null }) => completeAssetRepair(id, note),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useReportAssetLost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string | null }) => reportAssetLost(id, note),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

export function useRecoverAssetFromLost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => recoverAssetFromLost(id),
    onSuccess: (_data, id) => invalidateAssetQueries(queryClient, id),
  })
}

export function useDisposeAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, note }: { id: string; note?: string | null }) => disposeAsset(id, note),
    onSuccess: (_data, { id }) => invalidateAssetQueries(queryClient, id),
  })
}

/**
 * QR一括操作画面(spec「50. QR操作画面」)向け。スキャン(または管理番号手入力)の都度、
 * 対象を1件解決する。サーバーには何も保存しない読み取り専用の呼び出しのため、イベント
 * 駆動(Enter押下)の`useMutation`として提供する(`useQuery`のような自動再フェッチは不要)。
 */
export function useResolveAssetForBulk() {
  return useMutation({
    mutationFn: (input: string) => resolveAssetByScanInput(input),
  })
}

/** バックオフィス一括貸与でのスキャン時点の貸出可否検証(spec 論点8)。 */
export function useAssetLoanEligibility() {
  return useMutation({
    mutationFn: ({ assetId, borrowerUserId }: { assetId: string; borrowerUserId?: string }) =>
      getAssetLoanEligibility(assetId, borrowerUserId),
  })
}

/** QR一括操作の確定(`POST /assets/bulk`を1回だけ呼ぶ)。成功後は一覧・関連詳細を無効化する。 */
export function useBulkAssetOperation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: AssetBulkOperationInput) => bulkAssetOperation(input),
    onSuccess: (_data, input) => {
      queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, 'search'] })
      for (const assetId of input.asset_ids) {
        queryClient.invalidateQueries({ queryKey: [...ASSETS_KEY, assetId] })
      }
    },
  })
}
