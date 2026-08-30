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
import { FormField } from '../../../components/FormField/FormField'
import { PermissionDenied } from '../../../components/PermissionDenied/PermissionDenied'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../components/ui/table'
import { UserPicker } from '../../../components/UserPicker/UserPicker'
import { useAssetLoanEligibility, useBulkAssetOperation, useResolveAssetForBulk } from '../../../hooks/useAsset'

/**
 * バックオフィス一括貸与(spec「50. QR操作画面」、asset.manage必須)。backoffice/approval
 * 方式が混在してよい(spec 17番)。貸出先ユーザーを先に選択させてから対象を追加する
 * (approval方式は承認済み申請の有無をスキャン時点で`loan-eligibility`により検証する)。
 */
export function BackofficeBulkLendPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = user?.effective_permissions?.includes('asset.manage') ?? false

  const resolveAsset = useResolveAssetForBulk()
  const checkEligibility = useAssetLoanEligibility()
  const bulkOperation = useBulkAssetOperation()

  const [borrowerUserId, setBorrowerUserId] = useState<string | undefined>(undefined)
  const [targets, setTargets] = useState<Asset[]>([])
  const [scanError, setScanError] = useState<string | null>(null)
  const [result, setResult] = useState<AssetBulkOperationResult | null>(null)

  if (!canManage) {
    return <PermissionDenied message="バックオフィス一括貸与を行う権限がありません。必要な場合は管理者に依頼してください。" />
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

      const eligibility = await checkEligibility.mutateAsync({ assetId: asset.id, borrowerUserId })
      if (!eligibility.eligible) {
        setScanError(`「${asset.name}」(${asset.asset_no})は貸与できません: ${eligibility.reason ?? '対象外です。'}`)
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
    if (!borrowerUserId) return
    const res = await bulkOperation.mutateAsync({
      operation: 'backoffice_lend',
      asset_ids: targets.map((t) => t.id),
      borrower_user_id: borrowerUserId,
    })
    setResult(res)
    setTargets((prev) => prev.filter((t) => !res.results.some((r) => r.asset_id === t.id && r.success)))
  }

  return (
    <Card title="バックオフィス一括貸与">
      <div className="flex flex-col gap-6">
        <div className="w-full max-w-sm">
          <FormField label="貸出先" htmlFor="backoffice-bulk-lend-borrower" required>
            <UserPicker
              id="backoffice-bulk-lend-borrower"
              value={borrowerUserId}
              onChange={(next) => {
                setBorrowerUserId(next)
                setTargets([])
                setResult(null)
              }}
            />
          </FormField>
        </div>

        <AssetScanInput
          id="backoffice-bulk-lend-scan"
          label="貸与対象に追加する備品"
          onSubmit={handleScan}
          isPending={resolveAsset.isPending || checkEligibility.isPending}
          disabled={!borrowerUserId}
          disabledReason="先に貸出先ユーザーを選択してください。"
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
                <TableHead>貸出方式</TableHead>
                <TableHead aria-label="操作" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {targets.map((asset) => (
                <TableRow key={asset.id}>
                  <TableCell className="font-medium text-foreground">{asset.asset_no}</TableCell>
                  <TableCell>{asset.name}</TableCell>
                  <TableCell>
                    <Badge tone="neutral">{asset.lending_method === 'approval' ? '承認制' : 'バックオフィス'}</Badge>
                  </TableCell>
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

        {bulkOperation.error && <ErrorMessage error={bulkOperation.error} fallback="一括貸与に失敗しました。" />}

        {result && (
          <div className="flex flex-col gap-2 rounded-md border border-border p-4">
            <p className="text-sm font-medium text-foreground">
              成功 {result.succeeded_count}件 / 失敗 {result.failed_count}件
            </p>
            <ul className="flex flex-col gap-1 text-sm">
              {result.results.map((r) => (
                <li key={r.asset_id} className="flex items-center gap-2">
                  <Badge tone={r.success ? 'success' : 'danger'}>{r.success ? '成功' : '失敗'}</Badge>
                  <span className="text-muted-foreground">{r.success ? '貸与しました。' : r.error}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => navigate('/assets')}>
            戻る
          </Button>
          <Button onClick={handleConfirm} disabled={targets.length === 0 || !borrowerUserId} isLoading={bulkOperation.isPending}>
            {targets.length}件を確定
          </Button>
        </div>
      </div>
    </Card>
  )
}
