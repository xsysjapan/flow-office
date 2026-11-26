import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BackLink } from '../../components/BackLink/BackLink'
import { useBackTarget, useKeepBackState } from '../../navigation/backState'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { CategoryCombobox } from '../../components/CategoryCombobox/CategoryCombobox'
import { fieldErrors } from '../../utils/fieldErrors'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Textarea } from '../../components/ui/textarea'
import type { AssetLendingMethod, AssetManagementType } from '../../api/types'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { notify } from '../../lib/notify'
import { useRegisterAsset } from '../../hooks/useAsset'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { ASSET_REGISTER_INITIAL, isAssetRegisterDirty } from './assetDirty'
import { useAssetNumberRuleCategories, useAssetNumberRules } from '../../hooks/useAssetNumberRules'
import { assetLendingMethodLabel, assetManagementTypeLabel } from '../../utils/statusLabels'

const MANAGEMENT_TYPE_OPTIONS: AssetManagementType[] = ['lending', 'installation']
const LENDING_METHOD_OPTIONS: AssetLendingMethod[] = ['self_service', 'backoffice', 'approval']

/**
 * spec 論点10の判定順: ①category完全一致かつis_active=true → ②(①が無い場合のみ)
 * is_default=trueかつis_active=true → ③いずれも無ければ手入力(null)。
 */
function resolveAutoNumberingRule(category: string, rules: AssetNumberRule[]): AssetNumberRule | null {
  const trimmed = category.trim()
  if (!trimmed) return null
  const matched = rules.find((rule) => rule.category === trimmed)
  if (matched) return matched.is_active ? matched : null
  const defaultRule = rules.find((rule) => rule.is_default)
  return defaultRule?.is_active ? defaultRule : null
}

/**
 * 備品の新規登録(spec「実装対象」)。管理区分が貸出品の場合のみ貸出方式を選ばせ、
 * 貸出方式がセルフ貸出(self_service)の場合は通常配置場所を必須にする
 * (spec「貸出方式(lending_method)とLendAsset呼び出し条件」)。
 */
export function AssetRegisterPage() {
  const navigate = useNavigate()
  const backTarget = useBackTarget()
  const keepBackState = useKeepBackState()
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

  const isDirty = isAssetRegisterDirty(ASSET_REGISTER_INITIAL, {
    assetNo,
    name,
    category,
    serialNumber,
    managementType,
    lendingMethod,
    defaultLocationText,
    notes,
  })
  const { navigateAfterSave } = useUnsavedChangesGuard(isDirty)

  const isLending = managementType === 'lending'
  const requiresDefaultLocation = isLending && lendingMethod === 'self_service'
  const autoNumberingRule = resolveAutoNumberingRule(category, numberRules.data ?? [])
  const isAutoNumbered = autoNumberingRule !== null
  // 描く項目と同じ条件で、項目の下に出す422のキーを組み立てる(貸出方式・通常配置場所は貸出品のときだけ描く)。
  const { of, rest } = fieldErrors(registerAsset.error, [
    'asset_no',
    'name',
    'category',
    'serial_number',
    'management_type',
    'notes',
    ...(isLending ? (['lending_method', 'default_location_text'] as const) : []),
  ] as const)
  const isValid =
    (isAutoNumbered || assetNo.trim() !== '') &&
    name.trim() !== '' &&
    category.trim() !== '' &&
    (!requiresDefaultLocation || defaultLocationText.trim() !== '')

  async function handleSave() {
    let asset: Awaited<ReturnType<typeof registerAsset.mutateAsync>>
    try {
      asset = await registerAsset.mutateAsync({
        asset_no: isAutoNumbered ? null : assetNo,
        name,
        category,
        serial_number: serialNumber || null,
        management_type: managementType,
        lending_method: isLending ? lendingMethod : null,
        default_location_text: isLending && defaultLocationText ? defaultLocationText : null,
        notes: notes || null,
      })
    } catch {
      // 失敗は`registerAsset.error`として項目の下・ボタンの近くに出る。
      return
    }
    notify.success(`${name}を登録しました`)
    navigateAfterSave(`/assets/${asset.id}`, { state: keepBackState })
  }

  return (
    <>
      <BackLink />
      <Card title="備品を作成">
        <FormLayout>
          <FormField label="管理番号" htmlFor="asset-no" required={!isAutoNumbered} error={of('asset_no')}>
            <Input
              id="asset-no"
              value={isAutoNumbered ? '' : assetNo}
              onChange={(e) => setAssetNo(e.target.value)}
              placeholder={isAutoNumbered ? '保存時に自動採番されます' : '例: EQ-00121'}
              disabled={isAutoNumbered}
              readOnly={isAutoNumbered}
            />
          </FormField>
          <FormField label="名称" htmlFor="asset-name" required error={of('name')}>
            <Input id="asset-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="例: ThinkPad X1" />
          </FormField>
          <FormField label="カテゴリ" htmlFor="asset-category" required error={of('category')}>
            <CategoryCombobox
              id="asset-category"
              value={category}
              onChange={setCategory}
              suggestions={numberRuleCategories.data ?? []}
              placeholder="例: ノートPC"
            />
          </FormField>
          <FormField label="シリアル番号" htmlFor="asset-serial" error={of('serial_number')}>
            <Input id="asset-serial" value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} />
          </FormField>

          <FormField label="管理区分" htmlFor="asset-management-type" required error={of('management_type')}>
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
            <FormField label="貸出方式" htmlFor="asset-lending-method" required error={of('lending_method')}>
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
            <FormField
              label="通常配置場所"
              htmlFor="asset-default-location"
              required={requiresDefaultLocation}
              error={of('default_location_text')}
              description={requiresDefaultLocation ? 'セルフ貸出方式では通常配置場所の設定が必須です。' : undefined}
            >
              <Input
                id="asset-default-location"
                value={defaultLocationText}
                onChange={(e) => setDefaultLocationText(e.target.value)}
                placeholder="例: 本社4F 備品庫"
              />
            </FormField>
          )}

          <FormField label="備考" htmlFor="asset-notes" error={of('notes')}>
            <Textarea id="asset-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>

          {requiresDefaultLocation && !defaultLocationText && (
            <p className="mb-2 text-xs text-muted-foreground">通常配置場所を入力してください。</p>
          )}
          {rest != null && <ErrorMessage error={rest} />}
          <FormActions>
            <Button variant="secondary" onClick={() => navigate(backTarget?.to ?? '/assets', { state: backTarget?.state, replace: true })}>
              キャンセル
            </Button>
            <Button isLoading={registerAsset.isPending} disabled={!isValid} onClick={() => void handleSave()}>
              作成
            </Button>
          </FormActions>
        </FormLayout>
      </Card>
    </>
  )
}
