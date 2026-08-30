import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { Badge } from '../../components/Badge/Badge'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Separator } from '../../components/ui/separator'
import { useAsset, useAssetHistory } from '../../hooks/useAsset'
import {
  assetHistoryEventTypeLabel,
  assetInstallationStatusLabel,
  assetLendingMethodLabel,
  assetLendingStatusLabel,
  assetManagementTypeLabel,
} from '../../utils/statusLabels'

function formatDateTime(value: string | null): string {
  if (!value) return '-'
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

/**
 * 備品詳細(spec「UI設計方針」相当: [名称 / 管理番号 / 現在の状況 / 通常配置場所]のような
 * 表示)。貸出備品・設置備品で表示項目を出し分ける。フェーズ4第一弾のスコープは表示のみで、
 * 貸与・返却・設置・移設・修理・紛失・廃棄等の操作ボタンは次フェーズ(各種操作フォーム)で
 * 追加する。
 */
export function AssetDetailPage() {
  const { id } = useParams<{ id: string }>()
  const assetId = id ?? ''
  const { data: asset, isLoading, error } = useAsset(assetId)
  const { data: history, isLoading: isLoadingHistory } = useAssetHistory(assetId)

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="備品の取得に失敗しました。" />
  }
  if (!asset) return null

  const isLending = asset.management_type === 'lending'
  const statusMeta = isLending
    ? asset.lending_status
      ? assetLendingStatusLabel(asset.lending_status)
      : null
    : asset.installation_status
      ? assetInstallationStatusLabel(asset.installation_status)
      : null

  return (
    <div className="flex flex-col gap-4">
      <Link to="/assets" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
        ← 一覧へ戻る
      </Link>

      <Card
        title={`${asset.name} / ${asset.asset_no}`}
        actions={statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
      >
        <div className="flex flex-col gap-6">
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="font-medium text-muted-foreground">管理番号</dt>
            <dd className="text-foreground">{asset.asset_no}</dd>
            <dt className="font-medium text-muted-foreground">名称</dt>
            <dd className="text-foreground">{asset.name}</dd>
            <dt className="font-medium text-muted-foreground">カテゴリ</dt>
            <dd className="text-foreground">{asset.category}</dd>
            <dt className="font-medium text-muted-foreground">シリアル番号</dt>
            <dd className="text-foreground">{asset.serial_number ?? '-'}</dd>
            <dt className="font-medium text-muted-foreground">管理区分</dt>
            <dd className="text-foreground">{assetManagementTypeLabel(asset.management_type)}</dd>

            {isLending && (
              <>
                <dt className="font-medium text-muted-foreground">貸出方式</dt>
                <dd className="text-foreground">
                  {asset.lending_method ? assetLendingMethodLabel(asset.lending_method) : '-'}
                </dd>
                <dt className="font-medium text-muted-foreground">通常配置場所</dt>
                <dd className="text-foreground">{asset.default_location_text ?? '未設定'}</dd>
                {asset.lending_status === 'loaned' && asset.current_loan && (
                  <>
                    <dt className="font-medium text-muted-foreground">貸出先</dt>
                    <dd className="text-foreground">{asset.current_loan.borrower?.name ?? '不明'}</dd>
                    <dt className="font-medium text-muted-foreground">貸与日</dt>
                    <dd className="text-foreground">{formatDateTime(asset.current_loan.loaned_at)}</dd>
                    <dt className="font-medium text-muted-foreground">返却予定日</dt>
                    <dd className="text-foreground">{formatDateTime(asset.current_loan.expected_return_at)}</dd>
                  </>
                )}
              </>
            )}

            {!isLending && asset.current_placement && (
              <>
                <dt className="font-medium text-muted-foreground">現在設置場所</dt>
                <dd className="text-foreground">{asset.current_placement.location_text}</dd>
                <dt className="font-medium text-muted-foreground">設置日</dt>
                <dd className="text-foreground">{formatDateTime(asset.current_placement.started_at)}</dd>
              </>
            )}

            <dt className="font-medium text-muted-foreground">QRコード</dt>
            <dd className="font-mono text-xs text-foreground">{asset.qr_token}</dd>
            <dt className="font-medium text-muted-foreground">備考</dt>
            <dd className="text-foreground">{asset.notes ?? '-'}</dd>
          </dl>

          {/* Pattern exception: 貸与・返却・設置・移設・修理・紛失・廃棄等の業務操作ボタンは
              このフェーズでは表示しない。
              Reason: 今回のスコープ(検索一覧・詳細のみ)では各操作フォームを実装しておらず、
              押しても何も起きないボタンを置くと「押したのに反応しない」状態になる
              (SKILL.md §2.18違反)ため、次フェーズで操作フォームと合わせて追加する。 */}

          <Separator />

          <div className="flex flex-col gap-2">
            <SectionHeading>履歴</SectionHeading>
            {isLoadingHistory ? (
              <LoadingState />
            ) : (
              <ul className="flex flex-col gap-1" aria-label="履歴">
                {history?.length ? (
                  history
                    .slice()
                    .reverse()
                    .map((entry) => (
                      <li key={entry.id} className="flex gap-3 text-sm">
                        <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(entry.occurred_at)}</span>
                        <span className="text-foreground">{assetHistoryEventTypeLabel(entry.event_type)}</span>
                      </li>
                    ))
                ) : (
                  <p className="text-sm text-muted-foreground">履歴はありません。</p>
                )}
              </ul>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}
