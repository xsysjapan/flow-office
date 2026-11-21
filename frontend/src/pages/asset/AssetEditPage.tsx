import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { BackLink } from '../../components/BackLink/BackLink'
import { useBackTarget, useKeepBackState } from '../../navigation/backState'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { Input } from '../../components/ui/input'
import { Textarea } from '../../components/ui/textarea'
import { useAsset, useUpdateAssetDetails } from '../../hooks/useAsset'

/**
 * 備品の詳細編集(spec「実装対象」)。名称・カテゴリ・シリアル番号・備考のみを扱う。
 * 管理区分変更・貸出方式変更は業務操作(専用ダイアログ、`AssetDetailPage`)側で行うため、
 * この編集フォームには含めない(タスク指示参照)。
 */
export function AssetEditPage() {
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

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="備品の取得に失敗しました。" />
  }
  if (!asset) return null

  const isValid = name.trim() !== '' && category.trim() !== ''

  async function handleSave() {
    await updateAsset.mutateAsync({
      id: assetId,
      input: {
        name,
        category,
        serial_number: serialNumber || null,
        notes: notes || null,
      },
    })
    // 遷移先(詳細)が戻り先と同じ画面なら戻り先とその遷移元を引き継ぎ、違えば現在の遷移元を引き継ぐ。
    const detailPath = `/assets/${assetId}`
    if (backTarget && backTarget.to.split(/[?#]/)[0] === detailPath) {
      navigate(backTarget.to, { state: backTarget.state })
    } else {
      navigate(detailPath, { state: keepBackState })
    }
  }

  return (
    <>
      <BackLink />
      <Card title={`${asset.name}を編集`}>
        {updateAsset.error && <ErrorMessage error={updateAsset.error} />}

        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="名称" htmlFor="asset-edit-name" required>
              <Input id="asset-edit-name" value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>
            <FormField label="カテゴリ" htmlFor="asset-edit-category" required>
              <Input id="asset-edit-category" value={category} onChange={(e) => setCategory(e.target.value)} />
            </FormField>
          </div>

          <FormField label="シリアル番号" htmlFor="asset-edit-serial">
            <Input id="asset-edit-serial" value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} />
          </FormField>

          <FormField label="備考" htmlFor="asset-edit-notes">
            <Textarea id="asset-edit-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>

          <div className="mt-1 flex items-center gap-2">
            <Button variant="secondary" onClick={() => navigate(backTarget?.to ?? `/assets/${assetId}`, { state: backTarget?.state, replace: true })}>
              キャンセル
            </Button>
            <Button isLoading={updateAsset.isPending} disabled={!isValid} onClick={() => void handleSave()}>
              保存
            </Button>
          </div>
        </div>
      </Card>
    </>
  )
}
