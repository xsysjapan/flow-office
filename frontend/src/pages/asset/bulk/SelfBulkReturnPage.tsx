import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import type { AssetBulkOperationResult } from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { AssetScanInput } from '../../../components/AssetScanInput/AssetScanInput'
import { Badge } from '../../../components/Badge/Badge'
import { Button } from '../../../components/Button/Button'
import { Card } from '../../../components/Card/Card'
import { ErrorMessage } from '../../../components/ErrorMessage/ErrorMessage'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../components/ui/table'
import { useBulkAssetOperation, useResolveAssetForBulk } from '../../../hooks/useAsset'

/**
 * セルフ一括返却(spec「50. QR操作画面」UC-L04)。自分が現在借用中の備品のみ対象にできる。
 * 返却先(通常配置場所)が異なる場合があるため、対象一覧は`default_location_text`ごとに
 * グルーピングして表示する。確定操作自体は`operation: 'self_return'`で1回のAPI呼び出し。
 */
export function SelfBulkReturnPage() {
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
      if (!asset.current_loan || asset.current_loan.user_id !== user?.id) {
        setScanError(`「${asset.name}」(${asset.asset_no})は自分が借用中の備品ではありません。`)
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
      operation: 'self_return',
      asset_ids: targets.map((t) => t.id),
    })
    setResult(res)
    setTargets((prev) => prev.filter((t) => !res.results.some((r) => r.asset_id === t.id && r.success)))
  }

  const groups = groupByLocation(targets)

  return (
    <Card title="セルフ一括返却">
      <div className="flex flex-col gap-6">
        <p className="text-sm text-muted-foreground">
          自分が現在借用中の備品を一括返却します。返却先(通常配置場所)ごとにグループ表示します。
        </p>

        <AssetScanInput
          id="self-bulk-return-scan"
          label="返却対象に追加する備品"
          onSubmit={handleScan}
          isPending={resolveAsset.isPending}
        />
        {scanError && <ErrorMessage error={new Error(scanError)} />}

        {targets.length === 0 ? (
          <p className="text-sm text-muted-foreground">まだ対象がありません。管理番号またはQRトークンを入力してください。</p>
        ) : (
          <div className="flex flex-col gap-4">
            {groups.map(([location, assets]) => (
              <div key={location} className="flex flex-col gap-2">
                <p className="text-sm font-medium text-foreground">返却先: {location}</p>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>管理番号</TableHead>
                      <TableHead>名称</TableHead>
                      <TableHead aria-label="操作" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {assets.map((asset) => (
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
              </div>
            ))}
          </div>
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

function groupByLocation(assets: Asset[]): Array<[string, Asset[]]> {
  const map = new Map<string, Asset[]>()
  for (const asset of assets) {
    const key = asset.default_location_text ?? '(未設定)'
    const list = map.get(key) ?? []
    list.push(asset)
    map.set(key, list)
  }
  return Array.from(map.entries())
}
