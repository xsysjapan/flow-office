import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import type { Asset } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { PermissionDenied } from '../../components/PermissionDenied/PermissionDenied'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { Separator } from '../../components/ui/separator'
import { Textarea } from '../../components/ui/textarea'
import {
  useAsset,
  useAssetHistory,
  useAssetLoanRequests,
  useChangeAssetLendingMethod,
  useChangeAssetManagementType,
  useCompleteAssetRepair,
  useDeleteAsset,
  useDisposeAsset,
  useInstallAsset,
  useLendAsset,
  useRecoverAssetFromLost,
  useReissueAssetQrCode,
  useRelocateAsset,
  useRemoveAssetFromInstallation,
  useReportAssetLost,
  useReturnAsset,
  useSetAssetDefaultLocation,
  useStartAssetRepair,
} from '../../hooks/useAsset'
import { useCreateWorkflowRequest, useSubmitWorkflowRequest } from '../../hooks/useWorkflowRequests'
import {
  assetHistoryEventTypeLabel,
  assetInstallationStatusLabel,
  assetLendingMethodLabel,
  assetLendingStatusLabel,
  assetManagementTypeLabel,
} from '../../utils/statusLabels'

function formatDateTime(value: string | null): string {
  if (!value) return '-'
  return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' })
}

function SectionHeading({ children }: { children: string }) {
  return <h3 className="text-sm font-semibold text-foreground">{children}</h3>
}

const ASSET_MANAGE_PERMISSION = 'asset.manage'

/**
 * 貸与フォーム(バックオフィス貸与)。借用者・返却予定日を指定する。承認制(approval)の場合は
 * 対象borrowerUserIdに紐づく承認済み・未貸与の申請一覧から1件選ばせる(spec 論点2-3。
 * 1件しかなければ自動選択、0件ならエラー表示)。サーバー側(LendAssetHandler)も改めて
 * 存在・整合性を検証する(spec「貸出方式とLendAsset呼び出し条件」)。
 */
function LendAssetDialog({ asset }: { asset: Asset }) {
  const lendAsset = useLendAsset()
  const [borrowerUserId, setBorrowerUserId] = useState<string | undefined>(undefined)
  const [expectedReturnAt, setExpectedReturnAt] = useState<string | undefined>(undefined)
  const [loanRequestId, setLoanRequestId] = useState<string | undefined>(undefined)
  const isApproval = asset.lending_method === 'approval'

  const {
    data: approvedLoanRequests,
    isLoading: isLoadingLoanRequests,
  } = useAssetLoanRequests(asset.id, { status: 'approved', borrowerUserId }, isApproval && Boolean(borrowerUserId))

  // 承認済み申請が1件しかない場合は自動選択する(spec 論点2-3)。
  useEffect(() => {
    if (approvedLoanRequests?.length === 1) {
      setLoanRequestId(approvedLoanRequests[0].id)
    }
  }, [approvedLoanRequests])

  const resetSelection = () => {
    setBorrowerUserId(undefined)
    setExpectedReturnAt(undefined)
    setLoanRequestId(undefined)
  }

  return (
    <ConfirmActionDialog
      triggerLabel="貸与する"
      triggerVariant="primary"
      title={`${asset.name}を貸与する`}
      description="借用者を指定して貸与します。"
      confirmLabel="貸与する"
      isPending={lendAsset.isPending}
      error={lendAsset.error}
      onConfirm={async () => {
        if (!borrowerUserId) throw new Error('借用者を選択してください。')
        if (isApproval && !loanRequestId) throw new Error('承認済みの貸出申請を選択してください。')
        await lendAsset.mutateAsync({
          id: asset.id,
          input: {
            borrower_user_id: borrowerUserId,
            expected_return_at: expectedReturnAt || null,
            loan_request_id: isApproval ? loanRequestId : null,
          },
        })
        resetSelection()
      }}
      onOpenChange={(open) => {
        if (open) resetSelection()
      }}
    >
      <FormField label="借用者" htmlFor="lend-borrower" required>
        <UserPicker
          id="lend-borrower"
          value={borrowerUserId}
          onChange={(userId) => {
            setBorrowerUserId(userId)
            setLoanRequestId(undefined)
          }}
        />
      </FormField>
      <FormField label="返却予定日" htmlFor="lend-expected-return">
        <DatePicker id="lend-expected-return" value={expectedReturnAt} onChange={setExpectedReturnAt} />
      </FormField>

      {isApproval && borrowerUserId && (
        <FormField label="承認済みの貸出申請" htmlFor="lend-loan-request" required>
          {isLoadingLoanRequests ? (
            <LoadingState />
          ) : !approvedLoanRequests || approvedLoanRequests.length === 0 ? (
            <p className="text-sm text-destructive">この借用者に対する承認済み・未貸与の申請がありません。</p>
          ) : approvedLoanRequests.length === 1 ? (
            // 1件しかない場合は自動選択済み(上のuseEffect、spec 論点2-3)。
            <p className="text-sm text-foreground">
              {approvedLoanRequests[0].purpose ? `利用目的: ${approvedLoanRequests[0].purpose}` : '(利用目的の記載なし)'}
            </p>
          ) : (
            <NativeSelect
              id="lend-loan-request"
              value={loanRequestId ?? ''}
              onChange={(e) => setLoanRequestId(e.target.value || undefined)}
            >
              <option value="">選択してください({approvedLoanRequests.length}件の承認済み申請)</option>
              {approvedLoanRequests.map((loanRequest) => (
                <option key={loanRequest.id} value={loanRequest.id}>
                  {loanRequest.purpose ? loanRequest.purpose : '(利用目的の記載なし)'} / 承認日:{' '}
                  {loanRequest.approved_at ? formatDateTime(loanRequest.approved_at) : '-'}
                </option>
              ))}
            </NativeSelect>
          )}
        </FormField>
      )}
    </ConfirmActionDialog>
  )
}

/**
 * 貸出申請フォーム(UC-L07)。approval方式・available状態の資産に対し、利用目的と
 * 承認者を指定して申請する。承認者はspec 論点10の通り`asset.manage`権限保有者に絞る
 * (`UserPicker`の`permission`propで`GET /users/search?permission=asset.manage`に絞り込む)。
 */
function AssetLoanRequestDialog({ asset }: { asset: Asset }) {
  const createRequest = useCreateWorkflowRequest()
  const submitRequest = useSubmitWorkflowRequest()
  const [purpose, setPurpose] = useState('')
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)

  const isPending = createRequest.isPending || submitRequest.isPending
  const error = createRequest.error ?? submitRequest.error

  return (
    <ConfirmActionDialog
      triggerLabel="貸出申請"
      triggerVariant="primary"
      title={`${asset.name}の貸出を申請する`}
      description="承認されると、承認者(または備品管理担当者)が貸与操作を行います。"
      confirmLabel="申請する"
      isPending={isPending}
      error={error}
      onConfirm={async () => {
        if (!approverUserId) throw new Error('承認者を選択してください。')
        const created = await createRequest.mutateAsync({
          request_type_code: 'asset_loan',
          title: `${asset.name}(${asset.asset_no})の貸出申請`,
          form_data: { asset_id: asset.id, purpose },
          approver_user_id: approverUserId,
        })
        await submitRequest.mutateAsync({ id: created.id, approverUserId })
        setPurpose('')
        setApproverUserId(undefined)
      }}
      onOpenChange={(open) => {
        if (open) {
          setPurpose('')
          setApproverUserId(undefined)
        }
      }}
    >
      <FormField label="利用目的" htmlFor="asset-loan-purpose">
        <Textarea id="asset-loan-purpose" value={purpose} onChange={(e) => setPurpose(e.target.value)} />
      </FormField>
      <FormField label="承認者" htmlFor="asset-loan-approver" required>
        <UserPicker
          id="asset-loan-approver"
          value={approverUserId}
          onChange={setApproverUserId}
          permission="asset.manage"
        />
      </FormField>
    </ConfirmActionDialog>
  )
}

/** self_service方式の資産を本人へ貸与する(セルフ貸出)。 */
function SelfBorrowButton({ asset, borrowerUserId }: { asset: Asset; borrowerUserId: string }) {
  const lendAsset = useLendAsset()
  return (
    <ConfirmActionDialog
      triggerLabel="借りる"
      triggerVariant="primary"
      title={`${asset.name}を借りますか?`}
      description="自分自身へ貸与します。"
      confirmLabel="借りる"
      isPending={lendAsset.isPending}
      error={lendAsset.error}
      onConfirm={() => lendAsset.mutateAsync({ id: asset.id, input: { borrower_user_id: borrowerUserId } })}
    />
  )
}

function ReturnAssetDialog({ asset }: { asset: Asset }) {
  const returnAsset = useReturnAsset()
  const [returnNote, setReturnNote] = useState('')

  return (
    <ConfirmActionDialog
      triggerLabel="返却"
      triggerVariant="primary"
      title={`${asset.name}を返却しますか?`}
      description="返却済みとして記録します。"
      confirmLabel="返却する"
      isPending={returnAsset.isPending}
      error={returnAsset.error}
      onConfirm={async () => {
        await returnAsset.mutateAsync({ id: asset.id, input: { return_note: returnNote || null } })
        setReturnNote('')
      }}
    >
      <FormField label="返却時の備考(任意)" htmlFor="return-note">
        <Input id="return-note" value={returnNote} onChange={(e) => setReturnNote(e.target.value)} />
      </FormField>
    </ConfirmActionDialog>
  )
}

function LocationTextDialog({
  triggerLabel,
  title,
  description,
  confirmLabel,
  isPending,
  error,
  onConfirm,
}: {
  triggerLabel: string
  title: string
  description: string
  confirmLabel: string
  isPending: boolean
  error: unknown
  onConfirm: (locationText: string) => Promise<unknown>
}) {
  const [locationText, setLocationText] = useState('')

  return (
    <ConfirmActionDialog
      triggerLabel={triggerLabel}
      triggerVariant="secondary"
      title={title}
      description={description}
      confirmLabel={confirmLabel}
      isPending={isPending}
      error={error}
      onConfirm={async () => {
        if (!locationText.trim()) throw new Error('場所を入力してください。')
        await onConfirm(locationText)
        setLocationText('')
      }}
      onOpenChange={(open) => {
        if (open) setLocationText('')
      }}
    >
      <FormField label="場所" htmlFor="location-text" required>
        <Input id="location-text" value={locationText} onChange={(e) => setLocationText(e.target.value)} />
      </FormField>
    </ConfirmActionDialog>
  )
}

function NoteDialog({
  triggerLabel,
  triggerVariant = 'secondary',
  title,
  description,
  confirmLabel,
  isPending,
  error,
  onConfirm,
}: {
  triggerLabel: string
  triggerVariant?: 'primary' | 'secondary' | 'danger'
  title: string
  description: string
  confirmLabel: string
  isPending: boolean
  error: unknown
  onConfirm: (note: string | null) => Promise<unknown>
}) {
  const [note, setNote] = useState('')

  return (
    <ConfirmActionDialog
      triggerLabel={triggerLabel}
      triggerVariant={triggerVariant}
      title={title}
      description={description}
      confirmLabel={confirmLabel}
      isPending={isPending}
      error={error}
      onConfirm={async () => {
        await onConfirm(note || null)
        setNote('')
      }}
      onOpenChange={(open) => {
        if (open) setNote('')
      }}
    >
      <FormField label="備考(任意)" htmlFor="note-text">
        <Textarea id="note-text" value={note} onChange={(e) => setNote(e.target.value)} />
      </FormField>
    </ConfirmActionDialog>
  )
}

function ManagementTypeChangeDialog({ asset }: { asset: Asset }) {
  const changeManagementType = useChangeAssetManagementType()
  const target = asset.management_type === 'lending' ? 'installation' : 'lending'

  return (
    <ConfirmActionDialog
      triggerLabel={target === 'installation' ? '設置備品へ区分変更' : '貸出備品へ区分変更'}
      triggerVariant="secondary"
      title={`${assetManagementTypeLabel(target)}に区分変更しますか?`}
      description="管理区分の変更は履歴として記録され、いつでも元に戻せます。"
      confirmLabel="区分変更する"
      isPending={changeManagementType.isPending}
      error={changeManagementType.error}
      onConfirm={() => changeManagementType.mutateAsync({ id: asset.id, managementType: target })}
    />
  )
}

function LendingMethodChangeDialog({ asset }: { asset: Asset }) {
  const changeLendingMethod = useChangeAssetLendingMethod()
  const [lendingMethod, setLendingMethod] = useState(asset.lending_method ?? 'backoffice')

  return (
    <ConfirmActionDialog
      triggerLabel="貸出方式変更"
      triggerVariant="secondary"
      title={`${asset.name}の貸出方式を変更する`}
      description="セルフ貸出方式へ変更する場合、通常配置場所が設定済みである必要があります。"
      confirmLabel="変更する"
      isPending={changeLendingMethod.isPending}
      error={changeLendingMethod.error}
      onConfirm={() => changeLendingMethod.mutateAsync({ id: asset.id, lendingMethod })}
      onOpenChange={(open) => {
        if (open) setLendingMethod(asset.lending_method ?? 'backoffice')
      }}
    >
      <FormField label="貸出方式" htmlFor="lending-method-select" required>
        <NativeSelect
          id="lending-method-select"
          value={lendingMethod}
          onChange={(e) => setLendingMethod(e.target.value as typeof lendingMethod)}
        >
          <option value="self_service">{assetLendingMethodLabel('self_service')}</option>
          <option value="backoffice">{assetLendingMethodLabel('backoffice')}</option>
          <option value="approval">{assetLendingMethodLabel('approval')}</option>
        </NativeSelect>
      </FormField>
    </ConfirmActionDialog>
  )
}

/**
 * 備品詳細(spec「UI設計方針」相当)。貸出備品・設置備品で表示項目を出し分ける。
 * フェーズ4第二弾: 資産の現在状態・management_type・lending_methodに応じて可能な操作
 * ボタンのみを出し分けて表示する(spec「49. UI設計方針」相当、タスク指示参照)。
 */
export function AssetDetailPage() {
  const { id } = useParams<{ id: string }>()
  const assetId = id ?? ''
  const navigate = useNavigate()
  const { user } = useAuth()
  const { data: asset, isLoading, error } = useAsset(assetId)
  const { data: history, isLoading: isLoadingHistory } = useAssetHistory(assetId)

  const removeFromInstallation = useRemoveAssetFromInstallation()
  const startRepair = useStartAssetRepair()
  const completeRepair = useCompleteAssetRepair()
  const reportLost = useReportAssetLost()
  const recoverFromLost = useRecoverAssetFromLost()
  const dispose = useDisposeAsset()
  const deleteAsset = useDeleteAsset()
  const reissueQrCode = useReissueAssetQrCode()
  const setDefaultLocation = useSetAssetDefaultLocation()
  const installAsset = useInstallAsset()
  const relocateAsset = useRelocateAsset()

  const [deleteError, setDeleteError] = useState<Error | null>(null)

  if (isLoading) return <LoadingState />
  if (error) {
    if (error instanceof ApiError && error.status === 403) return <PermissionDenied />
    return <ErrorMessage error={error} fallback="備品の取得に失敗しました。" />
  }
  if (!asset) return null

  const canManage = user?.effective_permissions?.includes(ASSET_MANAGE_PERMISSION) ?? false
  const isLending = asset.management_type === 'lending'
  const statusMeta = isLending
    ? asset.lending_status
      ? assetLendingStatusLabel(asset.lending_status)
      : null
    : asset.installation_status
      ? assetInstallationStatusLabel(asset.installation_status)
      : null

  const isSelfServiceForSelf =
    isLending && asset.lending_method === 'self_service' && asset.lending_status === 'available' && user
  const canDelete =
    isLending
      ? asset.lending_status !== 'loaned' && asset.lending_status !== 'repair'
      : asset.installation_status !== 'installed' && asset.installation_status !== 'repair'

  async function handleDelete() {
    setDeleteError(null)
    try {
      await deleteAsset.mutateAsync(assetId)
      navigate('/assets')
    } catch (e) {
      setDeleteError(e as Error)
      throw e
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link to="/assets" className="text-sm text-muted-foreground hover:text-foreground hover:underline">
        ← 一覧へ戻る
      </Link>

      <Card
        title={`${asset.name} / ${asset.asset_no}`}
        actions={
          <div className="flex items-center gap-2">
            {statusMeta && <Badge tone={statusMeta.tone}>{statusMeta.label}</Badge>}
            {canManage && (
              <Button variant="secondary" size="sm" asChild>
                <Link to={`/assets/${asset.id}/edit`}>編集</Link>
              </Button>
            )}
          </div>
        }
      >
        <div className="flex flex-col gap-6">
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
            <dt className="font-medium text-muted-foreground">管理番号</dt>
            <dd className="text-foreground">{asset.asset_no}</dd>
            <dt className="font-medium text-muted-foreground">名称</dt>
            <dd className="text-foreground">{asset.name}</dd>
            <dt className="font-medium text-muted-foreground">カテゴリ</dt>
            <dd className="text-foreground">{asset.category}</dd>
            <dt className="font-medium text-muted-foreground">シリアル番号</dt>
            <dd className="text-foreground">{asset.serial_number ?? '-'}</dd>
            <dt className="font-medium text-muted-foreground">管理区分</dt>
            <dd className="text-foreground">{assetManagementTypeLabel(asset.management_type)}</dd>

            {isLending && (
              <>
                <dt className="font-medium text-muted-foreground">貸出方式</dt>
                <dd className="text-foreground">
                  {asset.lending_method ? assetLendingMethodLabel(asset.lending_method) : '-'}
                </dd>
                <dt className="font-medium text-muted-foreground">通常配置場所</dt>
                <dd className="text-foreground">{asset.default_location_text ?? '未設定'}</dd>
                {asset.lending_status === 'loaned' && asset.current_loan && (
                  <>
                    <dt className="font-medium text-muted-foreground">貸出先</dt>
                    <dd className="text-foreground">{asset.current_loan.borrower?.name ?? '不明'}</dd>
                    <dt className="font-medium text-muted-foreground">貸与日</dt>
                    <dd className="text-foreground">{formatDateTime(asset.current_loan.loaned_at)}</dd>
                    <dt className="font-medium text-muted-foreground">返却予定日</dt>
                    <dd className="text-foreground">{formatDateTime(asset.current_loan.expected_return_at)}</dd>
                  </>
                )}
              </>
            )}

            {!isLending && asset.current_placement && (
              <>
                <dt className="font-medium text-muted-foreground">現在設置場所</dt>
                <dd className="text-foreground">{asset.current_placement.location_text}</dd>
                <dt className="font-medium text-muted-foreground">設置日</dt>
                <dd className="text-foreground">{formatDateTime(asset.current_placement.started_at)}</dd>
              </>
            )}

            <dt className="font-medium text-muted-foreground">QRコード</dt>
            <dd className="font-mono text-xs text-foreground">{asset.qr_token}</dd>
            <dt className="font-medium text-muted-foreground">備考</dt>
            <dd className="text-foreground">{asset.notes ?? '-'}</dd>
          </dl>

          <Separator />

          {/* 業務操作(spec「49. UI設計方針」相当)。状態・management_type・lending_methodに
              応じて可能な操作だけを出し分ける。asset.manage権限が無い一般ユーザーには
              self_service方式の[借りる]/[返却]以外を表示しない(タスク指示参照)。 */}
          <div className="flex flex-col gap-2">
            <SectionHeading>操作</SectionHeading>
            <div className="flex flex-wrap items-center gap-2">
              {isLending && asset.lending_status === 'available' && (
                <>
                  {isSelfServiceForSelf && user && <SelfBorrowButton asset={asset} borrowerUserId={user.id} />}
                  {/* UC-L07: 承認制の資産は認証済みユーザーなら誰でも貸出申請できる
                      (spec 論点10、asset.manage不要)。 */}
                  {asset.lending_method === 'approval' && <AssetLoanRequestDialog asset={asset} />}
                  {canManage && <LendAssetDialog asset={asset} />}
                  {canManage && <ManagementTypeChangeDialog asset={asset} />}
                  {canManage && <LendingMethodChangeDialog asset={asset} />}
                  {canManage && (
                    <LocationTextDialog
                      triggerLabel={asset.default_location_text ? '通常配置場所を変更' : '通常配置場所を設定'}
                      title="通常配置場所を設定する"
                      description="貸出方式がセルフ貸出の場合、この場所の設定が必須です。"
                      confirmLabel="設定する"
                      isPending={setDefaultLocation.isPending}
                      error={setDefaultLocation.error}
                      onConfirm={(locationText) =>
                        setDefaultLocation.mutateAsync({ id: asset.id, locationText })
                      }
                    />
                  )}
                  {canManage && (
                    <NoteDialog
                      triggerLabel="修理開始"
                      title={`${asset.name}の修理を開始しますか?`}
                      description="修理中の間は貸与できなくなります。"
                      confirmLabel="修理を開始する"
                      isPending={startRepair.isPending}
                      error={startRepair.error}
                      onConfirm={(note) => startRepair.mutateAsync({ id: asset.id, note })}
                    />
                  )}
                  {canManage && (
                    <NoteDialog
                      triggerLabel="紛失登録"
                      title={`${asset.name}を紛失として登録しますか?`}
                      description="紛失として記録します。発見後は改めて発見操作で復帰できます。"
                      confirmLabel="紛失登録する"
                      isPending={reportLost.isPending}
                      error={reportLost.error}
                      onConfirm={(note) => reportLost.mutateAsync({ id: asset.id, note })}
                    />
                  )}
                  {canManage && (
                    <NoteDialog
                      triggerLabel="廃棄"
                      triggerVariant="danger"
                      title={`${asset.name}を廃棄しますか?`}
                      description="この操作は元に戻せません。備品は廃棄済みとして記録されます。"
                      confirmLabel="廃棄する"
                      isPending={dispose.isPending}
                      error={dispose.error}
                      onConfirm={(note) => dispose.mutateAsync({ id: asset.id, note })}
                    />
                  )}
                  {canManage && (
                    <ConfirmActionDialog
                      triggerLabel="QR再発行"
                      triggerVariant="secondary"
                      title="QRコードを再発行しますか?"
                      description="現在のQRコードは無効になります。管理番号・履歴は変わりません。"
                      confirmLabel="再発行する"
                      isPending={reissueQrCode.isPending}
                      error={reissueQrCode.error}
                      onConfirm={() => reissueQrCode.mutateAsync(asset.id)}
                    />
                  )}
                  {canManage && canDelete && (
                    <ConfirmActionDialog
                      triggerLabel="削除"
                      triggerVariant="danger"
                      title={`${asset.name}を削除しますか?`}
                      description="この操作は元に戻せません。備品は検索・一覧から削除されます(操作履歴自体は保持されます)。"
                      confirmLabel="削除する"
                      isPending={deleteAsset.isPending}
                      error={deleteError}
                      onConfirm={handleDelete}
                      onOpenChange={(open) => {
                        if (open) setDeleteError(null)
                      }}
                    />
                  )}
                </>
              )}

              {isLending && asset.lending_status === 'loaned' && <ReturnAssetDialog asset={asset} />}

              {isLending && asset.lending_status === 'repair' && (
                <NoteDialog
                  triggerLabel="修理完了"
                  triggerVariant="primary"
                  title={`${asset.name}の修理を完了しますか?`}
                  description="修理完了として記録し、再び貸与できるようにします。"
                  confirmLabel="修理を完了する"
                  isPending={completeRepair.isPending}
                  error={completeRepair.error}
                  onConfirm={(note) => completeRepair.mutateAsync({ id: asset.id, note })}
                />
              )}

              {isLending && asset.lending_status === 'lost' && canManage && (
                <ConfirmActionDialog
                  triggerLabel="発見"
                  triggerVariant="primary"
                  title={`${asset.name}が見つかりましたか?`}
                  description="紛失状態を解除し、発見前の状況に復帰します。"
                  confirmLabel="発見として記録する"
                  isPending={recoverFromLost.isPending}
                  error={recoverFromLost.error}
                  onConfirm={() => recoverFromLost.mutateAsync(asset.id)}
                />
              )}

              {!isLending && asset.installation_status === 'stored' && canManage && (
                <>
                  <LocationTextDialog
                    triggerLabel="設置"
                    title={`${asset.name}を設置する`}
                    description="設置場所を指定して設置します。"
                    confirmLabel="設置する"
                    isPending={installAsset.isPending}
                    error={installAsset.error}
                    onConfirm={(locationText) => installAsset.mutateAsync({ id: asset.id, locationText })}
                  />
                  <ManagementTypeChangeDialog asset={asset} />
                  <NoteDialog
                    triggerLabel="修理開始"
                    title={`${asset.name}の修理を開始しますか?`}
                    description="修理中の間は設置できなくなります。"
                    confirmLabel="修理を開始する"
                    isPending={startRepair.isPending}
                    error={startRepair.error}
                    onConfirm={(note) => startRepair.mutateAsync({ id: asset.id, note })}
                  />
                  <NoteDialog
                    triggerLabel="紛失登録"
                    title={`${asset.name}を紛失として登録しますか?`}
                    description="紛失として記録します。発見後は改めて発見操作で復帰できます。"
                    confirmLabel="紛失登録する"
                    isPending={reportLost.isPending}
                    error={reportLost.error}
                    onConfirm={(note) => reportLost.mutateAsync({ id: asset.id, note })}
                  />
                  <NoteDialog
                    triggerLabel="廃棄"
                    triggerVariant="danger"
                    title={`${asset.name}を廃棄しますか?`}
                    description="この操作は元に戻せません。備品は廃棄済みとして記録されます。"
                    confirmLabel="廃棄する"
                    isPending={dispose.isPending}
                    error={dispose.error}
                    onConfirm={(note) => dispose.mutateAsync({ id: asset.id, note })}
                  />
                  {canDelete && (
                    <ConfirmActionDialog
                      triggerLabel="削除"
                      triggerVariant="danger"
                      title={`${asset.name}を削除しますか?`}
                      description="この操作は元に戻せません。備品は検索・一覧から削除されます(操作履歴自体は保持されます)。"
                      confirmLabel="削除する"
                      isPending={deleteAsset.isPending}
                      error={deleteError}
                      onConfirm={handleDelete}
                      onOpenChange={(open) => {
                        if (open) setDeleteError(null)
                      }}
                    />
                  )}
                </>
              )}

              {!isLending && asset.installation_status === 'installed' && canManage && (
                <>
                  <LocationTextDialog
                    triggerLabel="移設"
                    title={`${asset.name}を移設する`}
                    description="新しい設置場所を指定します。"
                    confirmLabel="移設する"
                    isPending={relocateAsset.isPending}
                    error={relocateAsset.error}
                    onConfirm={(locationText) => relocateAsset.mutateAsync({ id: asset.id, locationText })}
                  />
                  <ConfirmActionDialog
                    triggerLabel="撤去"
                    triggerVariant="secondary"
                    title={`${asset.name}を撤去しますか?`}
                    description="設置を終了し、保管中の状態に戻します。"
                    confirmLabel="撤去する"
                    isPending={removeFromInstallation.isPending}
                    error={removeFromInstallation.error}
                    onConfirm={() => removeFromInstallation.mutateAsync(asset.id)}
                  />
                </>
              )}

              {!isLending && asset.installation_status === 'repair' && canManage && (
                <NoteDialog
                  triggerLabel="修理完了"
                  triggerVariant="primary"
                  title={`${asset.name}の修理を完了しますか?`}
                  description="修理完了として記録し、保管中の状態に戻します。"
                  confirmLabel="修理を完了する"
                  isPending={completeRepair.isPending}
                  error={completeRepair.error}
                  onConfirm={(note) => completeRepair.mutateAsync({ id: asset.id, note })}
                />
              )}

              {!isLending && asset.installation_status === 'lost' && canManage && (
                <ConfirmActionDialog
                  triggerLabel="発見"
                  triggerVariant="primary"
                  title={`${asset.name}が見つかりましたか?`}
                  description="紛失状態を解除し、保管中の状態に戻します。"
                  confirmLabel="発見として記録する"
                  isPending={recoverFromLost.isPending}
                  error={recoverFromLost.error}
                  onConfirm={() => recoverFromLost.mutateAsync(asset.id)}
                />
              )}

              {((isLending && asset.lending_status === 'disposed') ||
                (!isLending && asset.installation_status === 'disposed')) && (
                <p className="text-sm text-muted-foreground">廃棄済みのため操作はありません。</p>
              )}
            </div>
          </div>

          <Separator />

          <div className="flex flex-col gap-2">
            <SectionHeading>履歴</SectionHeading>
            {isLoadingHistory ? (
              <LoadingState />
            ) : (
              <ul className="flex flex-col gap-1" aria-label="履歴">
                {history?.length ? (
                  history
                    .slice()
                    .reverse()
                    .map((entry) => (
                      <li key={entry.id} className="flex gap-3 text-sm">
                        <span className="min-w-[10rem] text-muted-foreground">{formatDateTime(entry.occurred_at)}</span>
                        <span className="text-foreground">{assetHistoryEventTypeLabel(entry.event_type)}</span>
                      </li>
                    ))
                ) : (
                  <p className="text-sm text-muted-foreground">履歴はありません。</p>
                )}
              </ul>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}
