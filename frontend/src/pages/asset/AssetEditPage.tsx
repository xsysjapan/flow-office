import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
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
    navigate(`/assets/${assetId}`)
  }

  return (
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
          <Button variant="secondary" onClick={() => navigate(`/assets/${assetId}`)}>
            キャンセル
          </Button>
          <Button isLoading={updateAsset.isPending} disabled={!isValid} onClick={() => void handleSave()}>
            保存
          </Button>
        </div>
      </div>
    </Card>
  )
}
