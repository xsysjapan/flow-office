import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BackLink } from '../../../components/BackLink/BackLink'
import { useBackTarget } from '../../../navigation/backState'
import { useAuth } from '../../../auth/useAuth'
import type { AssetBulkOperationResult } from '../../../api/asset'
import type { Asset } from '../../../api/types'
import { AssetPicker } from '../../../components/AssetPicker/AssetPicker'
import { Button } from '../../../components/Button/Button'
import { Card } from '../../../components/Card/Card'
import { BulkOperationError } from '../../../components/BulkOperationError/BulkOperationError'
import { ErrorMessage } from '../../../components/ErrorMessage/ErrorMessage'
import { notify } from '../../../lib/notify'
import { FormActions } from '../../../components/FormActions/FormActions'
import { FormLayout } from '../../../components/FormLayout/FormLayout'
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
  const backTarget = useBackTarget()
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
    setResult(null)
    try {
      const res = await bulkOperation.mutateAsync({
        operation: 'relocate',
        asset_ids: targets.map((t) => t.id),
        location_text: locationText.trim(),
      })
      setResult(res)
      notify.success(`${res.data.length}件を移設しました`)
      setTargets([])
    } catch {
      // 失敗(全部か無しかの拒否を含む)は bulkOperation.error として画面に出る。選択は残す。
    }
  }

  return (
    <>
      <BackLink />
      <Card title="備品の一括移設">
        <div className="flex flex-col gap-6">
          <FormLayout>
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

            <AssetPicker
              id="bulk-relocate-scan"
              label="移設対象に追加する備品"
              onSubmit={handleScan}
              isPending={resolveAsset.isPending}
              disabled={!locationText.trim()}
              disabledReason="先に移設先を入力してください。"
            />
            {scanError && <ErrorMessage error={new Error(scanError)} />}
          </FormLayout>

          {targets.length === 0 ? (
            <p className="text-sm text-muted-foreground">まだ対象がありません。</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>管理番号</TableHead>
                  <TableHead className="whitespace-normal min-w-48">名称</TableHead>
                  <TableHead>現在の設置場所</TableHead>
                  <TableHead aria-label="操作" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {targets.map((asset) => (
                  <TableRow key={asset.id}>
                    <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                    <TableCell className="whitespace-normal min-w-48">{asset.name}</TableCell>
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

          <FormLayout>
            {result && (
              <p className="mb-4 text-sm font-medium text-foreground">
                {result.data.length}件を移設しました。
              </p>
            )}
            {bulkOperation.error && (
              <BulkOperationError
                error={bulkOperation.error}
                labels={Object.fromEntries(targets.map((t) => [t.id, `${t.name}(${t.asset_no})`]))}
                fallback="一括移設に失敗しました。"
              />
            )}
            <FormActions>
              <Button variant="secondary" onClick={() => navigate(backTarget?.to ?? '/assets', { state: backTarget?.state, replace: true })}>
                キャンセル
              </Button>
              <Button
                onClick={handleConfirm}
                disabled={targets.length === 0 || !locationText.trim()}
                isLoading={bulkOperation.isPending}
              >
                {targets.length}件を確定
              </Button>
            </FormActions>
          </FormLayout>
        </div>
      </Card>
    </>
  )
}
