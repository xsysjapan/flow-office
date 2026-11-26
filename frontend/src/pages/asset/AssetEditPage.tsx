import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { BackLink } from '../../components/BackLink/BackLink'
import { useBackTarget, useKeepBackState } from '../../navigation/backState'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { fieldErrors } from '../../utils/fieldErrors'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Input } from '../../components/ui/input'
import { Textarea } from '../../components/ui/textarea'
import { notify } from '../../lib/notify'
import { useAsset, useUpdateAssetDetails } from '../../hooks/useAsset'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { isAssetEditDirty } from './assetDirty'

/**
 * 備品の詳細編集(spec「実装対象」)。名称・カテゴリ・シリアル番号・備考のみを扱う。
 * 管理区分変更・貸出方式変更は業務操作(専用ダイアログ、`AssetDetailPage`)側で行うため、
 * この編集フォームには含めない(タスク指示参照)。
 */
export function AssetEditPage() {
  const { id } = useParams<{ id: string }>()
  // パスの変数(備品ID)が変わったら入力を作り直す。
  return <AssetEditForm key={id} />
}

function AssetEditForm() {
  const { id } = useParams<{ id: string }>()
  const assetId = id ?? ''
  const navigate = useNavigate()
  const backTarget = useBackTarget()
  const keepBackState = useKeepBackState()
  const { data: asset, isLoading, error } = useAsset(assetId)
  const updateAsset = useUpdateAssetDetails()

  const [name, setName] = useState('')
  const [category, setCategory] = useState('')
  const [serialNumber, setSerialNumber] = useState('')
  const [notes, setNotes] = useState('')

  useEffect(() => {
    if (!asset) return
    setName(asset.name)
    setCategory(asset.category)
    setSerialNumber(asset.serial_number ?? '')
    setNotes(asset.notes ?? '')
  }, [asset])

  const isDirty = asset
    ? isAssetEditDirty(
        { name: asset.name, category: asset.category, serialNumber: asset.serial_number ?? '', notes: asset.notes ?? '' },
        { name, category, serialNumber, notes },
      )
    : false
  const { navigateAfterSave } = useUnsavedChangesGuard(isDirty)

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="備品の取得に失敗しました。" />
  }
  if (!asset) return null

  const isValid = name.trim() !== '' && category.trim() !== ''
  const { of, rest } = fieldErrors(updateAsset.error, ['name', 'category', 'serial_number', 'notes'] as const)

  async function handleSave() {
    try {
      await updateAsset.mutateAsync({
        id: assetId,
        input: {
          name,
          category,
          serial_number: serialNumber || null,
          notes: notes || null,
        },
      })
    } catch {
      // 失敗は`updateAsset.error`として項目の下・ボタンの近くに出る。
      return
    }
    notify.success(`${name}を保存しました`)
    // 遷移先(詳細)が戻り先と同じ画面なら戻り先とその遷移元を引き継ぎ、違えば現在の遷移元を引き継ぐ。
    const detailPath = `/assets/${assetId}`
    if (backTarget && backTarget.to.split(/[?#]/)[0] === detailPath) {
      navigateAfterSave(backTarget.to, { state: backTarget.state })
    } else {
      navigateAfterSave(detailPath, { state: keepBackState })
    }
  }

  return (
    <>
      <BackLink />
      <Card title="備品を編集">
        <FormLayout>
          <FormField label="名称" htmlFor="asset-edit-name" required error={of('name')}>
            <Input id="asset-edit-name" value={name} onChange={(e) => setName(e.target.value)} />
          </FormField>
          <FormField label="カテゴリ" htmlFor="asset-edit-category" required error={of('category')}>
            <Input id="asset-edit-category" value={category} onChange={(e) => setCategory(e.target.value)} />
          </FormField>

          <FormField label="シリアル番号" htmlFor="asset-edit-serial" error={of('serial_number')}>
            <Input id="asset-edit-serial" value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} />
          </FormField>

          <FormField label="備考" htmlFor="asset-edit-notes" error={of('notes')}>
            <Textarea id="asset-edit-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>

          {rest != null && <ErrorMessage error={rest} />}
          <FormActions>
            <Button variant="secondary" onClick={() => navigate(backTarget?.to ?? `/assets/${assetId}`, { state: backTarget?.state, replace: true })}>
              キャンセル
            </Button>
            <Button isLoading={updateAsset.isPending} disabled={!isValid} onClick={() => void handleSave()}>
              保存
            </Button>
          </FormActions>
        </FormLayout>
      </Card>
    </>
  )
}
