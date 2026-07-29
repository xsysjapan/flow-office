import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog/ConfirmDialog'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { ExpenseItemsTable } from '../../components/ExpenseItemsTable/ExpenseItemsTable'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { SingleExpenseItemForm, type SingleExpenseItemFieldSet } from '../../components/SingleExpenseItemForm/SingleExpenseItemForm'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategory, ExpenseEntryPreset } from '../../api/types'
import { useWeek } from '../../hooks/useAttendance'
import {
  useAddExpenseItem,
  useAddExpenseItemsBulk,
  useCreateExpenseClaim,
  useDeleteExpenseClaim,
  useDeleteExpenseItem,
  useExpenseClaim,
  useSubmitExpenseClaim,
  useUpdateExpenseClaimTitle,
  useUpdateExpenseItem,
} from '../../hooks/useExpenseClaims'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import { useEditableRows } from '../../hooks/useEditableRows'
import { useApplyExpenseEntryPreset, useExpenseEntryPresets } from '../../hooks/useExpenseEntryPresets'
import { mondayOf, formatDate } from '../../utils/weekDates'
import { workLocationTypeLabel } from '../../utils/statusLabels'

function emptyItem(categoryId?: number): SaveExpenseItemInput {
  return { category_id: categoryId ?? 0, usage_date: '', amount: 0 }
}

/** UC-X004b〜d: 区分コードから単発入力フォームの`fieldSet`を決める。会食・宿泊・その他以外は
 *  すべて汎用(取引先必須+内容)の`generic`にまとめ、区分が増えてもフロント分岐を増やさない。
 *  「その他」は取引先が無い経費(郵送料の実費精算等)もあり得るため、取引先を任意項目にした
 *  専用の`other`を使う。 */
function fieldSetForCategory(category: ExpenseCategory): SingleExpenseItemFieldSet {
  if (category.code === 'meal') return 'meal'
  if (category.code === 'lodging') return 'lodging'
  if (category.code === 'other') return 'other'
  return 'generic'
}

/** UC-X004: 対象日の勤怠実績(出社/客先訪問等)を入力補助として参考表示するのみで、
 *  金額計算・確定判定には使わない(docs/30-usecases-expense.md)。 */
function AttendanceReferenceLookup() {
  const [targetDate, setTargetDate] = useState('')
  const weekStart = targetDate ? formatDate(mondayOf(new Date(`${targetDate}T00:00:00`))) : ''
  const { data: week, isLoading } = useWeek(weekStart || '1970-01-01')
  const day = week?.find((d) => d.work_date === targetDate)

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <FormField label="対象日の勤怠実績を確認" htmlFor="attendance-reference-date">
        <Input
          id="attendance-reference-date"
          type="date"
          value={targetDate}
          onChange={(e) => setTargetDate(e.target.value)}
        />
      </FormField>
      {targetDate && (
        <p className="text-sm text-muted-foreground">
          {isLoading
            ? '確認中...'
            : day?.work_location_type
              ? `${targetDate}: ${workLocationTypeLabel(day.work_location_type)}と記録されています`
              : `${targetDate}の勤怠実績は見つかりませんでした`}
        </p>
      )}
    </div>
  )
}

/** プリセットの下書き定義1件をuseEditableRows用の行データへ変換する。利用日は
 *  プリセットには持たせず(利用のたびに変わるため)、ここでは空のまま生成し、
 *  ユーザーが確認・入力してから保存する(「経費精算機能 設計・実装指示書」9.1)。 */
function presetItemToRow(item: ExpenseEntryPreset['definition'][number]): SaveExpenseItemInput {
  return {
    category_id: item.category_id,
    usage_date: '',
    amount: item.amount ?? 0,
    description: item.description ?? undefined,
    payment_bearer: item.payment_bearer ?? undefined,
    attributes: item.attributes ?? undefined,
  }
}

/** 交通費はUC-X002/X003の移動区間テンプレートを廃止し、経費全体で共通の入力プリセットに
 *  一本化する。この区分に関係する明細を1件以上含むプリセットだけを候補として表示し、
 *  クリックすると明細の下書き行を追加する(保存は既存の表形式レビューで行う)。 */
function PresetPicker({ categoryId, onApply }: { categoryId: number; onApply: (rows: SaveExpenseItemInput[]) => void }) {
  const { data: presets, isLoading, error } = useExpenseEntryPresets()
  const applyPreset = useApplyExpenseEntryPreset()

  const applicablePresets = (presets ?? []).filter((preset) =>
    preset.definition.some((item) => item.category_id === categoryId),
  )

  if (isLoading) return null
  if (error) return <ErrorMessage error={error} fallback="プリセットの取得に失敗しました。" />
  if (applicablePresets.length === 0) return null

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <span className="text-sm font-medium text-foreground">プリセットから追加</span>
      <div className="flex flex-wrap gap-2">
        {applicablePresets.map((preset) => (
          <Button
            key={preset.id}
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => {
              applyPreset.mutate(preset.id)
              onApply(preset.definition.map(presetItemToRow))
            }}
          >
            {preset.name}({preset.definition.length}件)
          </Button>
        ))}
      </div>
    </div>
  )
}

/** UC-X004: 経費区分を選ぶステップ。対象期間は聞かず、区分を選ぶだけの画面。この時点では
 *  まだ何もサーバーに送信しない。下書き(expense_claims)自体は、実際に明細を1件保存した
 *  瞬間に初めて作成する(区分を選んだだけでデータを作らない)。 */
function CategorySelectionStep({
  categories,
  onSelect,
  onBack,
}: {
  categories: ExpenseCategory[]
  onSelect: (category: ExpenseCategory) => void
  onBack?: () => void
}) {
  const activeCategories = categories.filter((category) => category.is_active)

  return (
    <Card
      title="経費区分を選ぶ"
      actions={
        onBack && (
          <Button variant="secondary" size="sm" onClick={onBack}>
            登録方法の選択に戻る
          </Button>
        )
      }
    >
      <p className="mb-3 text-sm text-muted-foreground">
        対象期間の入力は不要です。まず精算したい経費区分を選んでください。
      </p>
      <div className="flex flex-wrap gap-2">
        {activeCategories.map((category) => (
          <Button key={category.id} variant="secondary" onClick={() => onSelect(category)}>
            {category.name}
          </Button>
        ))}
      </div>
    </Card>
  )
}

type ExpenseClaimEntryMode = 'individual' | 'bulk'

/** 経費精算を開始する最初の分岐。1件をすぐ登録したいのか(タイトルは登録内容から
 *  自動的に提案する)、複数件をまとめて1つの申請にしたいのか(先にタイトルを決める)を
 *  最初に選ばせる。 */
function EntryModeSelectionStep({ onSelect }: { onSelect: (mode: ExpenseClaimEntryMode) => void }) {
  return (
    <Card title="経費精算を始める">
      <p className="mb-3 text-sm text-muted-foreground">登録方法を選んでください。</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2 rounded-md border border-border p-4">
          <h3 className="font-semibold text-foreground">個別に経費登録</h3>
          <p className="flex-1 text-sm text-muted-foreground">
            1件の経費をすぐに登録します。タイトルは登録内容から自動的に提案します。
          </p>
          <div>
            <Button onClick={() => onSelect('individual')}>個別に登録する</Button>
          </div>
        </div>
        <div className="flex flex-col gap-2 rounded-md border border-border p-4">
          <h3 className="font-semibold text-foreground">まとめて経費登録</h3>
          <p className="flex-1 text-sm text-muted-foreground">
            複数の経費をまとめて1つの申請にします。まず申請のタイトルを入力してください。
          </p>
          <div>
            <Button onClick={() => onSelect('bulk')}>まとめて登録する</Button>
          </div>
        </div>
      </div>
    </Card>
  )
}

/** よく使われるタイトルの候補。年月は現在日時から組み立てる。クリックするとそのまま
 *  タイトルとして決定できる(入力の手間を省く)。 */
function suggestedBulkTitles(): string[] {
  const now = new Date()
  const label = `${now.getFullYear()}年${now.getMonth() + 1}月分`
  return [`${label}経費`, `${label}交通費`, '出張精算']
}

/** UC-X004(まとめて経費登録): 明細を入力する前に、まず申請のタイトルを決める。
 *  よく使う候補をクリックするだけで決定できるようにし、自由入力も許容する。 */
function BulkTitleStep({
  onSubmit,
  onBack,
  isSubmitting,
  error,
}: {
  onSubmit: (title: string) => void
  onBack: () => void
  isSubmitting: boolean
  error: unknown
}) {
  const [title, setTitle] = useState('')
  const suggestions = suggestedBulkTitles()

  return (
    <Card
      title="申請タイトルを入力"
      actions={
        <Button variant="secondary" size="sm" onClick={onBack}>
          登録方法の選択に戻る
        </Button>
      }
    >
      {error !== null && error !== undefined && <ErrorMessage error={error} />}
      <p className="mb-3 text-sm text-muted-foreground">例: 2026年7月分交通費、大阪出張分</p>

      <div className="mb-3 flex flex-wrap gap-2">
        {suggestions.map((suggestion) => (
          <Button
            key={suggestion}
            type="button"
            variant="secondary"
            size="sm"
            isLoading={isSubmitting}
            onClick={() => onSubmit(suggestion)}
          >
            {suggestion}
          </Button>
        ))}
      </div>

      <FormField label="タイトル" htmlFor="bulk-claim-title" required>
        <Input
          id="bulk-claim-title"
          placeholder="例: 2026年7月分交通費"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
        />
      </FormField>

      <Button disabled={!title.trim()} isLoading={isSubmitting} onClick={() => onSubmit(title.trim())}>
        次へ
      </Button>
    </Card>
  )
}

/** 「個別に経費登録」で保存された最初の明細から、申請タイトルの候補を組み立てる
 *  (例: 「宿泊費(2026-07-20)」)。ユーザーは後から自由に変更できる。 */
function suggestIndividualTitle(categoryName: string | undefined, usageDate: string): string {
  return usageDate ? `${categoryName ?? '経費精算'}(${usageDate})` : (categoryName ?? '経費精算')
}

/** 明細の追加・修正・削除ができる(=編集を再開できる)ステータス。backendの
 *  `ExpenseClaimStatus::editable()`と一致させる。 */
const EDITABLE_STATUSES = ['draft', 'returned']

/**
 * UC-X002/X004〜X013: 経費精算の新規作成・下書き編集。対象期間は入力させず、まず経費区分を
 * 選ぶ。区分の`entry_mode`が`batch`(交通費)なら表形式入力・移動経路入力・テンプレート一括
 * 生成の3タブを、`single`(会食・宿泊・消耗品・その他)なら区分専用の1件入力フォームを表示する。
 * `expense_claims`は区分を選んだだけでは作成せず、明細を1件でも実際に保存した時点で初めて
 * 作成する。対象期間はusage_dateから自動算出される派生値として表示するだけにする(原則2)。
 * URLに`:id`が含まれる場合(下書きの編集を再開する`/expenses/:id/edit`)は、既存のclaimIdを
 * そのまま使う。また`?category=<区分コード>`が付いている場合(メニューのよく使う区分への
 * ショートカット)は、新規作成時に限り区分選択ステップを飛ばしてそのまま該当区分の入力
 * フォームを表示する。
 */
export function ExpenseClaimNewPage() {
  const navigate = useNavigate()
  const { id: routeClaimId } = useParams<{ id?: string }>()
  const [searchParams] = useSearchParams()
  const categoryCodeParam = searchParams.get('category')
  const [claimId, setClaimId] = useState<string | undefined>(routeClaimId)
  const [entryMode, setEntryMode] = useState<ExpenseClaimEntryMode | undefined>(undefined)
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | undefined>(undefined)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)

  const createClaim = useCreateExpenseClaim()
  const startClaimTitle = useUpdateExpenseClaimTitle()
  const { data: claim, isLoading: isLoadingClaim, error: claimError } = useExpenseClaim(claimId)
  const { data: categories, isLoading: isLoadingCategories, error: categoriesError } = useExpenseCategories()

  // ?category=のショートカットはページ表示時に1回だけ適用する。「区分を変更する」で
  // 選択を解除した後にこのeffectが再実行され、同じ区分へ戻されてしまわないようにする。
  // メニューからのショートカットは「個別に経費登録」相当として扱い、登録方法選択を省略する。
  const appliedCategoryShortcut = useRef(false)
  useEffect(() => {
    if (routeClaimId || appliedCategoryShortcut.current || !categoryCodeParam || !categories) return
    appliedCategoryShortcut.current = true
    const shortcutCategory = categories.find((category) => category.code === categoryCodeParam && category.is_active)
    if (shortcutCategory) {
      setEntryMode('individual')
      setSelectedCategoryId(shortcutCategory.id)
    }
  }, [routeClaimId, categoryCodeParam, categories])

  const { rows, addRow, updateRow, removeRow, duplicateRow, moveRow, appendRows, toData, reset } =
    useEditableRows<SaveExpenseItemInput>([])
  const addItem = useAddExpenseItem()
  const addItemsBulk = useAddExpenseItemsBulk()
  const updateItem = useUpdateExpenseItem()
  const deleteClaimMutation = useDeleteExpenseClaim()
  const deleteItem = useDeleteExpenseItem()
  const submitClaim = useSubmitExpenseClaim(claimId ?? '')

  if (isLoadingCategories) return <LoadingState />
  if (categoriesError) return <ErrorMessage error={categoriesError} fallback="経費区分の取得に失敗しました。" />

  const selectedCategory = categories?.find((category) => category.id === selectedCategoryId)

  /** claimIdが未確定(=まだ何も保存していない新規作成)なら、この場で下書きを作成して
   *  そのIDを返す。既にある場合は作成し直さない(UC-X013: 別区分の明細を追加)。 */
  const ensureClaimId = async (): Promise<string> => {
    if (claimId) return claimId
    const created = await createClaim.mutateAsync()
    setClaimId(created.id)
    return created.id
  }

  const handleSelectEntryMode = (mode: ExpenseClaimEntryMode) => {
    setEntryMode(mode)
  }

  /** 「まとめて経費登録」: 先にタイトルを確定させてから下書きを作成する。 */
  const handleStartBulkClaim = async (title: string) => {
    const id = await ensureClaimId()
    await startClaimTitle.mutateAsync({ claimId: id, title })
  }

  const handleSelectCategory = (category: ExpenseCategory) => {
    setSelectedCategoryId(category.id)
  }

  const handleSaveItems = async () => {
    const items = toData().filter((item) => item.usage_date && item.amount)
    if (items.length === 0) return
    const wasNewClaim = !claimId
    const id = await ensureClaimId()
    await addItemsBulk.mutateAsync({ claimId: id, items })
    if (wasNewClaim && entryMode === 'individual') {
      const categoryName = categories?.find((category) => category.id === items[0].category_id)?.name
      void startClaimTitle.mutateAsync({ claimId: id, title: suggestIndividualTitle(categoryName, items[0].usage_date) })
    }
    reset([])
  }

  const handleSaveSingleItem = async (input: SaveExpenseItemInput) => {
    const wasNewClaim = !claimId
    const id = await ensureClaimId()
    await addItem.mutateAsync({ claimId: id, input })
    if (wasNewClaim && entryMode === 'individual') {
      const categoryName = categories?.find((category) => category.id === input.category_id)?.name
      void startClaimTitle.mutateAsync({ claimId: id, title: suggestIndividualTitle(categoryName, input.usage_date) })
    }
  }

  const handleSubmit = async () => {
    if (!claimId || !approverUserId) return
    await submitClaim.mutateAsync(approverUserId)
    navigate(`/expenses/${claimId}`)
  }

  if (claimId && isLoadingClaim) return <LoadingState />
  if (claimId && claimError) return <ErrorMessage error={claimError} fallback="経費精算の取得に失敗しました。" />

  if (claimId && claim && !EDITABLE_STATUSES.includes(claim.status)) {
    return (
      <Card title="この経費精算は編集できません">
        <p className="text-sm text-muted-foreground">
          申請済み・承認済み・取消済みの経費精算は明細を編集できません。詳細画面から状態を確認してください。
        </p>
        <Button className="mt-3" variant="secondary" onClick={() => navigate(`/expenses/${claimId}`)}>
          詳細画面へ
        </Button>
      </Card>
    )
  }

  // 新規作成のみ(下書き再開・区分ショートカットを除く)。まず登録方法を選ばせる。
  if (!claimId && !entryMode && !categoryCodeParam) {
    return <EntryModeSelectionStep onSelect={handleSelectEntryMode} />
  }

  // 「まとめて経費登録」は明細入力の前に申請タイトルを確定させる。
  if (!claimId && entryMode === 'bulk') {
    return (
      <BulkTitleStep
        onBack={() => setEntryMode(undefined)}
        onSubmit={(title) => void handleStartBulkClaim(title)}
        isSubmitting={createClaim.isPending || startClaimTitle.isPending}
        error={createClaim.error ?? startClaimTitle.error}
      />
    )
  }

  if (!selectedCategory) {
    return (
      <div className="flex flex-col gap-6">
        {categoriesError && <ErrorMessage error={categoriesError} />}
        {createClaim.error && <ErrorMessage error={createClaim.error} />}
        <CategorySelectionStep
          categories={categories ?? []}
          onSelect={handleSelectCategory}
          onBack={!claimId ? () => setEntryMode(undefined) : undefined}
        />

        {claim && (
          <SavedItemsAndSubmit
            claim={claim}
            approverUserId={approverUserId}
            onApproverChange={setApproverUserId}
            onUpdateItem={(itemId, input) => updateItem.mutate({ claimId: claim.id, itemId, input })}
            onDeleteItem={(itemId) => deleteItem.mutate({ claimId: claim.id, itemId })}
            onSubmit={() => void handleSubmit()}
            onDeleteClaim={() => {
              deleteClaimMutation.mutate(claim.id, { onSuccess: () => navigate('/expenses') })
            }}
            deleteClaimMutation={deleteClaimMutation}
            submitClaim={submitClaim}
          />
        )}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={`${selectedCategory.name}の明細を入力`}
        actions={
          <Button variant="secondary" size="sm" onClick={() => setSelectedCategoryId(undefined)}>
            {(claim?.items.length ?? 0) > 0 ? '別の区分の明細を追加する' : '区分を変更する'}
          </Button>
        }
      >
        {selectedCategory.entry_mode === 'batch' ? (
          <>
            <AttendanceReferenceLookup />

            <div className="mt-4">
              <PresetPicker categoryId={selectedCategory.id} onApply={appendRows} />
            </div>

            <div className="mt-4">
              <ExpenseItemsTable
                rows={rows}
                categories={categories ?? []}
                onAddRow={() => addRow(emptyItem(selectedCategory.id))}
                onUpdateRow={updateRow}
                onRemoveRow={removeRow}
                onDuplicateRow={duplicateRow}
                onMoveRow={moveRow}
                onPasteRows={appendRows}
              />
            </div>

            {rows.length > 0 && (
              <div className="mt-4">
                <Button
                  isLoading={createClaim.isPending || addItemsBulk.isPending}
                  onClick={() => void handleSaveItems()}
                >
                  明細を保存する({rows.length}件)
                </Button>
                {addItemsBulk.error && <ErrorMessage error={addItemsBulk.error} />}
              </div>
            )}
          </>
        ) : (
          <>
            {addItem.error && <ErrorMessage error={addItem.error} />}
            <SingleExpenseItemForm
              fieldSet={fieldSetForCategory(selectedCategory)}
              categoryId={selectedCategory.id}
              fieldDefinitions={selectedCategory.field_definitions}
              onSubmit={(input) => void handleSaveSingleItem(input)}
              isSubmitting={createClaim.isPending || addItem.isPending}
            />
          </>
        )}
      </Card>

      {claim && (
        <SavedItemsAndSubmit
          claim={claim}
          approverUserId={approverUserId}
          onApproverChange={setApproverUserId}
          onUpdateItem={(itemId, input) => updateItem.mutate({ claimId: claim.id, itemId, input })}
          onDeleteItem={(itemId) => deleteItem.mutate({ claimId: claim.id, itemId })}
          onSubmit={() => void handleSubmit()}
          submitClaim={submitClaim}
          onDeleteClaim={() => {
            deleteClaimMutation.mutate(claim.id, { onSuccess: () => navigate('/expenses') })
          }}
          deleteClaimMutation={deleteClaimMutation}
        />
      )}
    </div>
  )
}

/** UC-X010: 保存済み明細一覧と申請セクション。対象期間は明細のusage_dateから自動算出された
 *  claim.period_from/period_toをそのまま表示するだけで、編集項目としては持たない。
 *  下書き(draft)のみ「この下書きを削除する」で不要な経費精算そのものを削除できる。 */
function SavedItemsAndSubmit({
  claim,
  approverUserId,
  onApproverChange,
  onUpdateItem,
  onDeleteItem,
  onSubmit,
  submitClaim,
  onDeleteClaim,
  deleteClaimMutation,
}: {
  claim: NonNullable<ReturnType<typeof useExpenseClaim>['data']>
  approverUserId: string | undefined
  onApproverChange: (userId: string | undefined) => void
  onUpdateItem: (itemId: string, input: SaveExpenseItemInput) => void
  onDeleteItem: (itemId: string) => void
  onSubmit: () => void
  onDeleteClaim: () => void
  deleteClaimMutation: ReturnType<typeof useDeleteExpenseClaim>
  submitClaim: ReturnType<typeof useSubmitExpenseClaim>
}) {
  const period =
    claim.period_from && claim.period_to ? `${claim.period_from} 〜 ${claim.period_to}` : '対象期間未確定'
  const updateTitle = useUpdateExpenseClaimTitle()
  const [titleInput, setTitleInput] = useState(claim.title ?? '')
  // 「個別に経費登録」では明細保存後にタイトルが自動提案される。ユーザーが自分で編集を
  // 始めるまでは、そのバックグラウンド更新をこの入力欄に反映し続ける。
  const [titleEditedByUser, setTitleEditedByUser] = useState(false)
  useEffect(() => {
    if (!titleEditedByUser) setTitleInput(claim.title ?? '')
  }, [claim.title, titleEditedByUser])

  return (
    <>
      <Card title="申請タイトル(任意)">
        {updateTitle.error && <ErrorMessage error={updateTitle.error} />}
        <div className="flex items-center gap-2">
          <Input
            aria-label="申請タイトル"
            placeholder="例: 7月分の立替経費、大阪出張分"
            value={titleInput}
            onChange={(e) => {
              setTitleInput(e.target.value)
              setTitleEditedByUser(true)
            }}
          />
          <Button
            variant="secondary"
            isLoading={updateTitle.isPending}
            disabled={titleInput === (claim.title ?? '')}
            onClick={() =>
              updateTitle.mutate(
                { claimId: claim.id, title: titleInput || null },
                { onSuccess: () => setTitleEditedByUser(false) },
              )
            }
          >
            保存
          </Button>
        </div>
      </Card>

      <Card title={`保存済みの明細(${claim.items.length}件・対象期間: ${period})`}>
        {claim.items.length === 0 ? (
          <p className="text-sm text-muted-foreground">保存済みの明細はまだありません。上のフォームから追加してください。</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>日付</TableHead>
                <TableHead>経費区分</TableHead>
                <TableHead>内容</TableHead>
                <TableHead>金額</TableHead>
                <TableHead>定期区間控除</TableHead>
                <TableHead>領収書</TableHead>
                <TableHead>操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {claim.items.map((item) => {
                const requiresReceipt = item.evidence_type === 'receipt_required'
                const deductionAmount = item.commuting_deduction_amount ?? 0
                return (
                  <TableRow key={item.id}>
                    <TableCell className="text-muted-foreground">{item.usage_date}</TableCell>
                    <TableCell className="text-muted-foreground">{item.category?.name}</TableCell>
                    <TableCell className="text-muted-foreground">{item.description}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.amount.toLocaleString()}円
                      {deductionAmount > 0 && (
                        <span className="block text-xs">
                          会社負担額 {(item.amount - deductionAmount).toLocaleString()}円
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <label className="flex items-center gap-2 text-xs text-foreground">
                        <Checkbox
                          checked={deductionAmount > 0}
                          onCheckedChange={(checked) =>
                            onUpdateItem(item.id, {
                              category_id: item.category_id,
                              usage_date: item.usage_date,
                              description: item.description ?? undefined,
                              amount: item.amount,
                              project_id: item.project_id ?? undefined,
                              commuting_deduction_amount: checked === true ? deductionAmount : 0,
                            })
                          }
                        />
                        定期区間を含む
                      </label>
                      {deductionAmount > 0 && (
                        <Input
                          className="mt-1 w-24"
                          type="number"
                          aria-label={`${item.usage_date}の定期区間控除額`}
                          value={deductionAmount}
                          onChange={(e) =>
                            onUpdateItem(item.id, {
                              category_id: item.category_id,
                              usage_date: item.usage_date,
                              description: item.description ?? undefined,
                              amount: item.amount,
                              project_id: item.project_id ?? undefined,
                              commuting_deduction_amount: Number(e.target.value),
                            })
                          }
                        />
                      )}
                    </TableCell>
                    <TableCell>
                      <AttachmentPanel ownerType="expense_item" ownerId={item.id} required={requiresReceipt} compact />
                    </TableCell>
                    <TableCell>
                      <Button variant="danger" size="sm" onClick={() => onDeleteItem(item.id)}>
                        削除
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </Card>

      <Card title="申請する">
        {submitClaim.error && <ErrorMessage error={submitClaim.error} />}
        {deleteClaimMutation.error && <ErrorMessage error={deleteClaimMutation.error} />}
        <FormField label="承認者" htmlFor="approver" required>
          <UserPicker id="approver" value={approverUserId} onChange={onApproverChange} />
        </FormField>
        <div className="flex items-center gap-3">
          <Button
            isLoading={submitClaim.isPending}
            disabled={!approverUserId || claim.items.length === 0}
            onClick={onSubmit}
          >
            申請する
          </Button>
          <Badge tone="neutral">下書き</Badge>
          {claim.status === 'draft' && (
            <ConfirmDialog
              trigger={<Button variant="danger">この下書きを削除する</Button>}
              title="この経費精算を削除しますか?"
              description="削除すると元に戻せません。保存済みの明細もすべて削除されます。"
              isConfirming={deleteClaimMutation.isPending}
              onConfirm={onDeleteClaim}
            />
          )}
        </div>
      </Card>
    </>
  )
}
