import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import type { SearchAssetsParams } from '../../api/asset'
import { ApiError } from '../../api/client'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { ClickableTableRow } from '../../components/ClickableTableRow/ClickableTableRow'
import { EmptyState } from '../../components/EmptyState/EmptyState'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { Pagination } from '../../components/Pagination/Pagination'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Button } from '../../components/Button/Button'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import { useAssetSearch } from '../../hooks/useAsset'
import {
  assetManagementTypeLabel,
  assetStatusSummary,
} from '../../utils/statusLabels'

/** 検索フィールドとURLクエリパラメータの対応(spec「検索」相当の検索項目)。 */
const TEXT_FIELDS: Array<{ key: keyof SearchAssetsParams; label: string; placeholder: string }> = [
  { key: 'asset_no', label: '管理番号', placeholder: '例: EQ-00121' },
  { key: 'name', label: '名称', placeholder: '例: ThinkPad X1' },
  { key: 'category', label: 'カテゴリ', placeholder: '例: ノートPC' },
  { key: 'serial_number', label: 'シリアル番号', placeholder: 'シリアル番号' },
  { key: 'default_location_text', label: '通常配置場所', placeholder: '通常配置場所' },
  { key: 'current_location_text', label: '現在設置場所', placeholder: '現在設置場所' },
]

const MANAGEMENT_TYPE_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'すべて' },
  { value: 'lending', label: '貸出品' },
  { value: 'installation', label: '設置品' },
]

const LENDING_STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'すべて' },
  { value: 'available', label: '利用可能' },
  { value: 'loaned', label: '貸出中' },
  { value: 'repair', label: '修理中' },
  { value: 'lost', label: '紛失' },
  { value: 'disposed', label: '廃棄済み' },
]

const INSTALLATION_STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'すべて' },
  { value: 'stored', label: '保管中' },
  { value: 'installed', label: '設置中' },
  { value: 'repair', label: '修理中' },
  { value: 'lost', label: '紛失' },
  { value: 'disposed', label: '廃棄済み' },
]

const FILTER_KEYS = [
  'q',
  'asset_no',
  'name',
  'category',
  'serial_number',
  'management_type',
  'lending_status',
  'installation_status',
  'default_location_text',
  'current_location_text',
] as const

/**
 * 備品検索一覧(spec「検索」相当)。管理番号・名称・カテゴリ・シリアル番号・管理区分・
 * 状態・通常配置場所・現在設置場所で絞り込める。行クリックで詳細
 * (`AssetDetailPage`)へ遷移する。検索条件・ページはURLに載せる(SKILL.md §2.10)。
 */
export function AssetListPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = user?.effective_permissions?.includes('asset.manage') ?? false
  const [searchParams, setSearchParams] = useSearchParams()

  const params: SearchAssetsParams = {}
  for (const key of FILTER_KEYS) {
    const value = searchParams.get(key)
    if (value) (params as Record<string, string>)[key] = value
  }
  const pageParam = Number(searchParams.get('page'))
  const page = Number.isInteger(pageParam) && pageParam > 0 ? pageParam : 1
  params.page = page

  const { data, isLoading, error } = useAssetSearch(params)

  const isFiltered = FILTER_KEYS.some((key) => searchParams.get(key))

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        for (const [key, value] of Object.entries(patch)) {
          if (value === null || value === '') next.delete(key)
          else next.set(key, value)
        }
        next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  function handlePageChange(nextPage: number) {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.set('page', String(nextPage))
        return next
      },
      { replace: true },
    )
  }

  function clearFilters() {
    setSearchParams(new URLSearchParams(), { replace: true })
  }

  if (isLoading) return <LoadingState />
  if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
  if (error) return <ErrorMessage error={error} fallback="備品一覧の取得に失敗しました。" />

  const assets = data?.data ?? []

  return (
    <Card
      title="備品管理"
      actions={
        canManage && (
          <Button size="sm" onClick={() => navigate('/assets/new')}>
            新規登録
          </Button>
        )
      }
    >
      <div className="mb-4 flex flex-col gap-4">
        <div className="flex flex-wrap gap-2">
          <Link to="/assets/bulk/self-loan" className="text-sm text-primary hover:underline">
            セルフ一括貸出
          </Link>
          <span className="text-sm text-muted-foreground">/</span>
          <Link to="/assets/bulk/self-return" className="text-sm text-primary hover:underline">
            セルフ一括返却
          </Link>
          {canManage && (
            <>
              <span className="text-sm text-muted-foreground">/</span>
              <Link to="/assets/bulk/lend" className="text-sm text-primary hover:underline">
                一括貸与
              </Link>
              <span className="text-sm text-muted-foreground">/</span>
              <Link to="/assets/bulk/return" className="text-sm text-primary hover:underline">
                一括返却
              </Link>
              <span className="text-sm text-muted-foreground">/</span>
              <Link to="/assets/bulk/relocate" className="text-sm text-primary hover:underline">
                一括移設
              </Link>
            </>
          )}
        </div>
        <div className="w-full max-w-sm">
          <FormField label="キーワード検索" htmlFor="asset-search-q">
            <Input
              id="asset-search-q"
              placeholder="管理番号・名称・カテゴリ・シリアル番号"
              value={searchParams.get('q') ?? ''}
              onChange={(e) => updateParams({ q: e.target.value })}
            />
          </FormField>
        </div>

        <div className="flex flex-wrap items-end gap-4">
          {TEXT_FIELDS.map((field) => (
            <div key={field.key} className="w-44">
              <FormField label={field.label} htmlFor={`asset-search-${field.key}`}>
                <Input
                  id={`asset-search-${field.key}`}
                  placeholder={field.placeholder}
                  value={searchParams.get(field.key) ?? ''}
                  onChange={(e) => updateParams({ [field.key]: e.target.value })}
                />
              </FormField>
            </div>
          ))}
          <div className="w-36">
            <FormField label="管理区分" htmlFor="asset-search-management-type">
              <NativeSelect
                id="asset-search-management-type"
                value={searchParams.get('management_type') ?? ''}
                onChange={(e) => updateParams({ management_type: e.target.value })}
              >
                {MANAGEMENT_TYPE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          </div>
          <div className="w-36">
            <FormField label="貸出状態" htmlFor="asset-search-lending-status">
              <NativeSelect
                id="asset-search-lending-status"
                value={searchParams.get('lending_status') ?? ''}
                onChange={(e) => updateParams({ lending_status: e.target.value })}
              >
                {LENDING_STATUS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          </div>
          <div className="w-36">
            <FormField label="設置状態" htmlFor="asset-search-installation-status">
              <NativeSelect
                id="asset-search-installation-status"
                value={searchParams.get('installation_status') ?? ''}
                onChange={(e) => updateParams({ installation_status: e.target.value })}
              >
                {INSTALLATION_STATUS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </NativeSelect>
            </FormField>
          </div>
          {isFiltered && (
            <Button variant="secondary" onClick={clearFilters}>
              フィルターをクリア
            </Button>
          )}
        </div>
      </div>

      {assets.length === 0 ? (
        isFiltered ? (
          <EmptyState
            title="条件に一致する備品がありません。"
            description="検索条件を変えると表示される場合があります。"
            action={
              <Button variant="secondary" size="sm" onClick={clearFilters}>
                検索条件をクリア
              </Button>
            }
          />
        ) : (
          <EmptyState title="登録されている備品がまだありません。" />
        )
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>管理番号</TableHead>
              <TableHead>名称</TableHead>
              <TableHead>管理区分</TableHead>
              <TableHead>現在の状況</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {assets.map((asset) => (
              <ClickableTableRow
                key={asset.id}
                onRowClick={() => navigate(`/assets/${asset.id}`)}
                rowLabel={`${asset.name}の詳細を開く`}
              >
                <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                <TableCell>
                  <Link
                    to={`/assets/${asset.id}`}
                    className="text-foreground hover:text-primary hover:underline"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {asset.name}
                  </Link>
                </TableCell>
                <TableCell>
                  <Badge tone="neutral">{assetManagementTypeLabel(asset.management_type)}</Badge>
                </TableCell>
                <TableCell className="text-muted-foreground">{assetStatusSummary(asset)}</TableCell>
              </ClickableTableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {data && (
        <Pagination
          currentPage={data.meta.current_page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPageChange={handlePageChange}
        />
      )}
    </Card>
  )
}
