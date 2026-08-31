import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import type { AssetBulkOperationResult } from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { AssetPicker } from '../../../components/AssetPicker/AssetPicker'
import { Badge } from '../../../components/Badge/Badge'
import { Button } from '../../../components/Button/Button'
import { Card } from '../../../components/Card/Card'
import { ErrorMessage } from '../../../components/ErrorMessage/ErrorMessage'
import { PermissionDenied } from '../../../components/PermissionDenied/PermissionDenied'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../components/ui/table'
import { useBulkAssetOperation, useResolveAssetForBulk } from '../../../hooks/useAsset'

/**
 * バックオフィス一括返却(asset.manage必須)。誰が借りているかに関わらず、現在アクティブな
 * 貸出がある備品を対象にできる。
 */
export function BackofficeBulkReturnPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = user?.effective_permissions?.includes('asset.manage') ?? false

  const resolveAsset = useResolveAssetForBulk()
  const bulkOperation = useBulkAssetOperation()

  const [targets, setTargets] = useState<Asset[]>([])
  const [scanError, setScanError] = useState<string | null>(null)
  const [result, setResult] = useState<AssetBulkOperationResult | null>(null)

  if (!canManage) {
    return <PermissionDenied message="バックオフィス一括返却を行う権限がありません。必要な場合は管理者に依頼してください。" />
  }

  async function handleScan(input: string) {
    setScanError(null)
    setResult(null)
    try {
      const asset = await resolveAsset.mutateAsync(input)

      if (targets.some((t) => t.id === asset.id)) {
        setScanError(`「${asset.name}」(${asset.asset_no})はすでに対象に追加されています。`)
        return
      }
      if (!asset.current_loan_id) {
        setScanError(`「${asset.name}」(${asset.asset_no})は現在貸出中ではありません。`)
        return
      }

      setTargets((prev) => [...prev, asset])
    } catch (e) {
      setScanError(e instanceof Error ? e.message : '備品を解決できませんでした。')
    }
  }

  function removeTarget(id: string) {
    setTargets((prev) => prev.filter((t) => t.id !== id))
  }

  async function handleConfirm() {
    const res = await bulkOperation.mutateAsync({
      operation: 'return',
      asset_ids: targets.map((t) => t.id),
    })
    setResult(res)
    setTargets((prev) => prev.filter((t) => !res.results.some((r) => r.asset_id === t.id && r.success)))
  }

  return (
    <Card title="バックオフィス一括返却">
      <div className="flex flex-col gap-6">
        <p className="text-sm text-muted-foreground">現在貸出中の備品を、借用者に関わらず一括で返却処理します。</p>

        <AssetPicker
          id="backoffice-bulk-return-scan"
          label="返却対象に追加する備品"
          onSubmit={handleScan}
          isPending={resolveAsset.isPending}
        />
        {scanError && <ErrorMessage error={new Error(scanError)} />}

        {targets.length === 0 ? (
          <p className="text-sm text-muted-foreground">まだ対象がありません。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>管理番号</TableHead>
                <TableHead>名称</TableHead>
                <TableHead>借用者</TableHead>
                <TableHead aria-label="操作" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {targets.map((asset) => (
                <TableRow key={asset.id}>
                  <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                  <TableCell>{asset.name}</TableCell>
                  <TableCell className="text-muted-foreground">{asset.current_loan?.borrower?.name ?? '-'}</TableCell>
                  <TableCell className="text-right">
                    <Button variant="secondary" size="sm" onClick={() => removeTarget(asset.id)}>
                      取り消す
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}

        {bulkOperation.error && <ErrorMessage error={bulkOperation.error} fallback="一括返却に失敗しました。" />}

        {result && (
          <div className="flex flex-col gap-2 rounded-md border border-border p-4">
            <p className="text-sm font-medium text-foreground">
              成功 {result.succeeded_count}件 / 失敗 {result.failed_count}件
            </p>
            <ul className="flex flex-col gap-1 text-sm">
              {result.results.map((r) => (
                <li key={r.asset_id} className="flex items-center gap-2">
                  <Badge tone={r.success ? 'success' : 'danger'}>{r.success ? '成功' : '失敗'}</Badge>
                  <span className="text-muted-foreground">{r.success ? '返却しました。' : r.error}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => navigate('/assets')}>
            戻る
          </Button>
          <Button onClick={handleConfirm} disabled={targets.length === 0} isLoading={bulkOperation.isPending}>
            {targets.length}件を確定
          </Button>
        </div>
      </div>
    </Card>
  )
}
