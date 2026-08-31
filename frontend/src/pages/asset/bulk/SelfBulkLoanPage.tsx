import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { AssetPicker } from '../../../components/AssetPicker/AssetPicker'
import { Badge } from '../../../components/Badge/Badge'
import { Button } from '../../../components/Button/Button'
import { Card } from '../../../components/Card/Card'
import { ErrorMessage } from '../../../components/ErrorMessage/ErrorMessage'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../components/ui/table'
import { useBulkAssetOperation, useResolveAssetForBulk } from '../../../hooks/useAsset'
import type { Asset } from '../../../api/types'
import type { AssetBulkOperationResult } from '../../../api/asset'

/**
 * セルフ一括貸出(spec「50. QR操作画面」「一括QR操作API」)。対象はself_service方式かつ
 * 貸出可能な備品のみ。管理番号検索・QRカメラ読み取りは`AssetPicker`が担う(spec 論点12)。
 * 1件ずつ`resolveAssetByScanInput`→適格性検証を行い、有効なものだけ
 * 対象リストに追加する。確定は`POST /assets/bulk`を1回だけ呼ぶ(部分成功あり)。
 */
export function SelfBulkLoanPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const resolveAsset = useResolveAssetForBulk()
  const bulkOperation = useBulkAssetOperation()

  const [targets, setTargets] = useState<Asset[]>([])
  const [scanError, setScanError] = useState<string | null>(null)
  const [result, setResult] = useState<AssetBulkOperationResult | null>(null)

  async function handleScan(input: string) {
    setScanError(null)
    setResult(null)
    try {
      const asset = await resolveAsset.mutateAsync(input)

      if (targets.some((t) => t.id === asset.id)) {
        setScanError(`「${asset.name}」(${asset.asset_no})はすでに対象に追加されています。`)
        return
      }
      if (asset.management_type !== 'lending' || asset.lending_method !== 'self_service') {
        setScanError(`「${asset.name}」(${asset.asset_no})はセルフ貸出の対象外です。`)
        return
      }
      if (asset.lending_status !== 'available') {
        setScanError(`「${asset.name}」(${asset.asset_no})は現在貸出可能な状態ではありません。`)
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
      operation: 'self_loan',
      asset_ids: targets.map((t) => t.id),
    })
    setResult(res)
    setTargets((prev) => prev.filter((t) => !res.results.some((r) => r.asset_id === t.id && r.success)))
  }

  return (
    <Card title="セルフ一括貸出">
      <div className="flex flex-col gap-6">
        <p className="text-sm text-muted-foreground">
          {user?.name ?? '自分'}自身への一括貸出です。セルフサービス方式の備品のみ対象にできます。
        </p>

        <AssetPicker
          id="self-bulk-loan-scan"
          label="貸出対象に追加する備品"
          onSubmit={handleScan}
          isPending={resolveAsset.isPending}
        />
        {scanError && <ErrorMessage error={new Error(scanError)} />}

        {targets.length === 0 ? (
          <p className="text-sm text-muted-foreground">まだ対象がありません。管理番号またはQRトークンを入力してください。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>管理番号</TableHead>
                <TableHead>名称</TableHead>
                <TableHead aria-label="操作" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {targets.map((asset) => (
                <TableRow key={asset.id}>
                  <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                  <TableCell>{asset.name}</TableCell>
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

        {bulkOperation.error && <ErrorMessage error={bulkOperation.error} fallback="一括貸出に失敗しました。" />}

        {result && (
          <div className="flex flex-col gap-2 rounded-md border border-border p-4">
            <p className="text-sm font-medium text-foreground">
              成功 {result.succeeded_count}件 / 失敗 {result.failed_count}件
            </p>
            <ul className="flex flex-col gap-1 text-sm">
              {result.results.map((r) => (
                <li key={r.asset_id} className="flex items-center gap-2">
                  <Badge tone={r.success ? 'success' : 'danger'}>{r.success ? '成功' : '失敗'}</Badge>
                  <span className="text-muted-foreground">{r.success ? '貸出しました。' : r.error}</span>
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
