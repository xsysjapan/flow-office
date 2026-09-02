import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { CategoryCombobox } from '../../components/CategoryCombobox/CategoryCombobox'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Textarea } from '../../components/ui/textarea'
import type { AssetLendingMethod, AssetManagementType } from '../../api/types'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { useRegisterAsset } from '../../hooks/useAsset'
import { useAssetNumberRuleCategories, useAssetNumberRules } from '../../hooks/useAssetNumberRules'
import { assetLendingMethodLabel, assetManagementTypeLabel } from '../../utils/statusLabels'

const MANAGEMENT_TYPE_OPTIONS: AssetManagementType[] = ['lending', 'installation']
const LENDING_METHOD_OPTIONS: AssetLendingMethod[] = ['self_service', 'backoffice', 'approval']

/**
 * spec 論点10の判定順: ①category完全一致かつenabled=true → ②(①が無い場合のみ)
 * isDefault=trueかつenabled=true → ③いずれも無ければ手入力(null)。
 */
function resolveAutoNumberingRule(category: string, rules: AssetNumberRule[]): AssetNumberRule | null {
  const trimmed = category.trim()
  if (!trimmed) return null
  const matched = rules.find((rule) => rule.category === trimmed)
  if (matched) return matched.enabled ? matched : null
  const defaultRule = rules.find((rule) => rule.isDefault)
  return defaultRule?.enabled ? defaultRule : null
}

/**
 * 備品の新規登録(spec「実装対象」)。管理区分が貸出品の場合のみ貸出方式を選ばせ、
 * 貸出方式がセルフ貸出(self_service)の場合は通常配置場所を必須にする
 * (spec「貸出方式(lending_method)とLendAsset呼び出し条件」)。
 */
export function AssetRegisterPage() {
  const navigate = useNavigate()
  const registerAsset = useRegisterAsset()
  const numberRules = useAssetNumberRules()
  const numberRuleCategories = useAssetNumberRuleCategories()

  const [assetNo, setAssetNo] = useState('')
  const [name, setName] = useState('')
  const [category, setCategory] = useState('')
  const [serialNumber, setSerialNumber] = useState('')
  const [managementType, setManagementType] = useState<AssetManagementType>('lending')
  const [lendingMethod, setLendingMethod] = useState<AssetLendingMethod>('backoffice')
  const [defaultLocationText, setDefaultLocationText] = useState('')
  const [notes, setNotes] = useState('')

  const isLending = managementType === 'lending'
  const requiresDefaultLocation = isLending && lendingMethod === 'self_service'
  const autoNumberingRule = resolveAutoNumberingRule(category, numberRules.data ?? [])
  const isAutoNumbered = autoNumberingRule !== null
  const isValid =
    (isAutoNumbered || assetNo.trim() !== '') &&
    name.trim() !== '' &&
    category.trim() !== '' &&
    (!requiresDefaultLocation || defaultLocationText.trim() !== '')

  async function handleSave() {
    const asset = await registerAsset.mutateAsync({
      asset_no: isAutoNumbered ? null : assetNo,
      name,
      category,
      serial_number: serialNumber || null,
      management_type: managementType,
      lending_method: isLending ? lendingMethod : null,
      default_location_text: isLending && defaultLocationText ? defaultLocationText : null,
      notes: notes || null,
    })
    navigate(`/assets/${asset.id}`)
  }

  return (
    <Card title="備品を登録">
      {registerAsset.error && <ErrorMessage error={registerAsset.error} />}

      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="管理番号" htmlFor="asset-no" required={!isAutoNumbered}>
            <Input
              id="asset-no"
              value={isAutoNumbered ? '' : assetNo}
              onChange={(e) => setAssetNo(e.target.value)}
              placeholder={isAutoNumbered ? '保存時に自動採番されます' : '例: EQ-00121'}
              disabled={isAutoNumbered}
              readOnly={isAutoNumbered}
            />
          </FormField>
          <FormField label="名称" htmlFor="asset-name" required>
            <Input id="asset-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="例: ThinkPad X1" />
          </FormField>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="カテゴリ" htmlFor="asset-category" required>
            <CategoryCombobox
              id="asset-category"
              value={category}
              onChange={setCategory}
              suggestions={numberRuleCategories.data ?? []}
              placeholder="例: ノートPC"
            />
          </FormField>
          <FormField label="シリアル番号" htmlFor="asset-serial">
            <Input id="asset-serial" value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} />
          </FormField>
        </div>

        <FormField label="管理区分" htmlFor="asset-management-type" required>
          <NativeSelect
            id="asset-management-type"
            value={managementType}
            onChange={(e) => setManagementType(e.target.value as AssetManagementType)}
          >
            {MANAGEMENT_TYPE_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {assetManagementTypeLabel(value)}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        {isLending && (
          <FormField label="貸出方式" htmlFor="asset-lending-method" required>
            <NativeSelect
              id="asset-lending-method"
              value={lendingMethod}
              onChange={(e) => setLendingMethod(e.target.value as AssetLendingMethod)}
            >
              {LENDING_METHOD_OPTIONS.map((value) => (
                <option key={value} value={value}>
                  {assetLendingMethodLabel(value)}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        )}

        {isLending && (
          <FormField label="通常配置場所" htmlFor="asset-default-location" required={requiresDefaultLocation}>
            <Input
              id="asset-default-location"
              value={defaultLocationText}
              onChange={(e) => setDefaultLocationText(e.target.value)}
              placeholder="例: 本社4F 備品庫"
            />
            {requiresDefaultLocation && (
              <p className="mt-1 text-xs text-muted-foreground">セルフ貸出方式では通常配置場所の設定が必須です。</p>
            )}
          </FormField>
        )}

        <FormField label="備考" htmlFor="asset-notes">
          <Textarea id="asset-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>

        <div className="mt-1 flex flex-col gap-1.5">
          <div className="flex items-center gap-2">
            <Button variant="secondary" onClick={() => navigate('/assets')}>
              キャンセル
            </Button>
            <Button isLoading={registerAsset.isPending} disabled={!isValid} onClick={() => void handleSave()}>
              作成
            </Button>
          </div>
          {requiresDefaultLocation && !defaultLocationText && (
            <p className="text-xs text-muted-foreground">通常配置場所を入力してください。</p>
          )}
        </div>
      </div>
    </Card>
  )
}
