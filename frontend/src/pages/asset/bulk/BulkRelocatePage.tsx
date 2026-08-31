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
import { FormField } from '../../../components/FormField/FormField'
import { PermissionDenied } from '../../../components/PermissionDenied/PermissionDenied'
import { Input } from '../../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../components/ui/table'
import { useBulkAssetOperation, useResolveAssetForBulk } from '../../../hooks/useAsset'

/**
 * 設置備品の一括移設(asset.manage必須)。移設先(location_text)を先に入力してから対象を
 * 追加する(spec「実装対象」)。対象は管理区分が設置品(installation)の備品のみ。
 */
export function BulkRelocatePage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = user?.effective_permissions?.includes('asset.manage') ?? false

  const resolveAsset = useResolveAssetForBulk()
  const bulkOperation = useBulkAssetOperation()

  const [locationText, setLocationText] = useState('')
  const [targets, setTargets] = useState<Asset[]>([])
  const [scanError, setScanError] = useState<string | null>(null)
  const [result, setResult] = useState<AssetBulkOperationResult | null>(null)

  if (!canManage) {
    return <PermissionDenied message="備品の一括移設を行う権限がありません。必要な場合は管理者に依頼してください。" />
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
      if (asset.management_type !== 'installation') {
        setScanError(`「${asset.name}」(${asset.asset_no})は設置備品ではないため移設できません。`)
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
    if (!locationText.trim()) return
    const res = await bulkOperation.mutateAsync({
      operation: 'relocate',
      asset_ids: targets.map((t) => t.id),
      location_text: locationText.trim(),
    })
    setResult(res)
    setTargets((prev) => prev.filter((t) => !res.results.some((r) => r.asset_id === t.id && r.success)))
  }

  return (
    <Card title="備品の一括移設">
      <div className="flex flex-col gap-6">
        <div className="w-full max-w-sm">
          <FormField label="移設先" htmlFor="bulk-relocate-location" required>
            <Input
              id="bulk-relocate-location"
              value={locationText}
              onChange={(e) => {
                setLocationText(e.target.value)
                setResult(null)
              }}
              placeholder="例: 3階会議室B"
            />
          </FormField>
        </div>

        <AssetPicker
          id="bulk-relocate-scan"
          label="移設対象に追加する備品"
          onSubmit={handleScan}
          isPending={resolveAsset.isPending}
          disabled={!locationText.trim()}
          disabledReason="先に移設先を入力してください。"
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
                <TableHead>現在の設置場所</TableHead>
                <TableHead aria-label="操作" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {targets.map((asset) => (
                <TableRow key={asset.id}>
                  <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                  <TableCell>{asset.name}</TableCell>
                  <TableCell className="text-muted-foreground">{asset.current_placement?.location_text ?? '-'}</TableCell>
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

        {bulkOperation.error && <ErrorMessage error={bulkOperation.error} fallback="一括移設に失敗しました。" />}

        {result && (
          <div className="flex flex-col gap-2 rounded-md border border-border p-4">
            <p className="text-sm font-medium text-foreground">
              成功 {result.succeeded_count}件 / 失敗 {result.failed_count}件
            </p>
            <ul className="flex flex-col gap-1 text-sm">
              {result.results.map((r) => (
                <li key={r.asset_id} className="flex items-center gap-2">
                  <Badge tone={r.success ? 'success' : 'danger'}>{r.success ? '成功' : '失敗'}</Badge>
                  <span className="text-muted-foreground">{r.success ? '移設しました。' : r.error}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => navigate('/assets')}>
            戻る
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={targets.length === 0 || !locationText.trim()}
            isLoading={bulkOperation.isPending}
          >
            {targets.length}件を確定
          </Button>
        </div>
      </div>
    </Card>
  )
}
