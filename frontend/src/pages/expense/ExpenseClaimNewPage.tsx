import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { AttachmentPanel } from '../../components/AttachmentPanel/AttachmentPanel'
import { Badge } from '../../components/Badge/Badge'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ConfirmActionDialog } from '../../components/ConfirmActionDialog/ConfirmActionDialog'
import { DatePicker } from '../../components/DatePicker/DatePicker'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { ExpenseItemsTable } from '../../components/ExpenseItemsTable/ExpenseItemsTable'
import { FormField } from '../../components/FormField/FormField'
import { LoadingState } from '../../components/LoadingState/LoadingState'
import { SingleExpenseItemForm } from '../../components/SingleExpenseItemForm/SingleExpenseItemForm'
import { UserPicker } from '../../components/UserPicker/UserPicker'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table'
import type { SaveExpenseItemInput } from '../../api/expenseClaims'
import type { ExpenseCategory, ExpenseEntryPreset, ExpenseEntryPresetDefinitionItem } from '../../api/types'
import { useAppSettings } from '../../contexts/useAppSettings'
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
import { useUploadAttachment } from '../../hooks/useAttachments'
import { mondayOf, formatDate } from '../../utils/weekDates'
import { workLocationTypeLabel } from '../../utils/statusLabels'
import { fieldSetForCategory } from '../../utils/expenseItemFieldSet'

function emptyItem(categoryId?: number): SaveExpenseItemInput {
  return { category_id: categoryId ?? 0, usage_date: '', amount: 0 }
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
        <DatePicker
          id="attendance-reference-date"
          value={targetDate || undefined}
          onChange={(date) => setTargetDate(date ?? '')}
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

/** プリセットのうち、いま選んでいる経費区分に該当する明細だけを取り出す。プリセットは
 *  複数区分にまたがれる(例: 出張プリセット = 交通費 + 宿泊費)が、入力画面では常に区分を
 *  1つ選んでから明細を入力するため、選択中の区分以外の明細を混ぜて追加しない
 *  (「交通費を選んでいるのに宿泊費の行が増える」状態を作らない)。他の区分の明細は、
 *  その区分に切り替えて同じプリセットを選び直すことで同じ申請に追加できる。 */
function presetItemsForCategory(preset: ExpenseEntryPreset, categoryId: number): ExpenseEntryPresetDefinitionItem[] {
  return preset.definition.filter((item) => item.category_id === categoryId)
}

/** 選択中の区分以外の明細も持つプリセットであることの補足表示。件数を隠して黙って
 *  捨てるのではなく、「この区分の分だけを追加する」と分かるようにする。 */
function otherCategoryNotice(preset: ExpenseEntryPreset, categoryId: number): string | undefined {
  const others = preset.definition.length - presetItemsForCategory(preset, categoryId).length
  return others > 0 ? `${preset.name}には他の区分の明細が${others}件あります` : undefined
}

/** 交通費はUC-X002/X003の移動区間テンプレートを廃止し、経費全体で共通の入力プリセットに
 *  一本化する。プリセットはcategory_id(明細側)で経費区分に紐づいているため、この区分を
 *  含むプリセットだけをAPI側で絞り込んで取得し、クリックするとこの区分の明細だけを下書き行
 *  として追加する(保存は既存の表形式レビューで行う)。プリセット管理画面(/expenses/presets)
 *  へのリンクは、メニューではなくこの実際にプリセットを使う場面にだけ置き(いきなりプリセット
 *  管理から使い始める人は少ないため)、遷移先ではこの区分で自動的に絞り込まれた状態にする。 */
function PresetPicker({ categoryId, onApply }: { categoryId: number; onApply: (rows: SaveExpenseItemInput[]) => void }) {
  const { data: presets, isLoading, error } = useExpenseEntryPresets({ category_id: categoryId, perPage: 50 })
  const applyPreset = useApplyExpenseEntryPreset()

  const applicablePresets = (presets?.data ?? [])
    .map((preset) => ({ preset, items: presetItemsForCategory(preset, categoryId) }))
    .filter((entry) => entry.items.length > 0)

  if (isLoading) return null
  if (error) return <ErrorMessage error={error} fallback="プリセットの取得に失敗しました。" />

  const notices = applicablePresets
    .map(({ preset }) => otherCategoryNotice(preset, categoryId))
    .filter((notice): notice is string => notice !== undefined)

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-foreground">プリセットから追加</span>
        <Link className="text-xs text-muted-foreground underline" to={`/expenses/presets?category_id=${categoryId}`}>
          プリセットを管理する
        </Link>
      </div>
      {applicablePresets.length === 0 ? (
        <p className="text-sm text-muted-foreground">この区分に使えるプリセットはまだありません。</p>
      ) : (
        <>
          <div className="flex flex-wrap gap-2">
            {applicablePresets.map(({ preset, items }) => (
              <Button
                key={preset.id}
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => {
                  applyPreset.mutate(preset.id)
                  onApply(items.map(presetItemToRow))
                }}
              >
                {preset.name}({items.length}件)
              </Button>
            ))}
          </div>
          {notices.length > 0 && (
            <p className="text-xs text-muted-foreground">
              {notices.join('、')}。他の区分に切り替えてから同じプリセットを選ぶと、同じ申請に追加できます。
            </p>
          )}
        </>
      )}
    </div>
  )
}

/** 単票入力(会食・宿泊・消耗品・その他)向けのプリセット選択。表形式入力と異なり
 *  フォームは1件分の項目しか持てないため、プリセットの明細のうちこの区分に該当する
 *  1件だけを取り出してフォームへ適用する(複数該当してもプリセットごとに先頭の1件を使う)。 */
function SinglePresetPicker({
  categoryId,
  onApply,
}: {
  categoryId: number
  onApply: (item: ExpenseEntryPresetDefinitionItem) => void
}) {
  const { data: presets, isLoading, error } = useExpenseEntryPresets({ category_id: categoryId, perPage: 50 })
  const applyPreset = useApplyExpenseEntryPreset()

  const applicablePresets = (presets?.data ?? [])
    .map((preset) => ({ preset, item: presetItemsForCategory(preset, categoryId)[0] }))
    .filter(
      (entry): entry is { preset: ExpenseEntryPreset; item: ExpenseEntryPresetDefinitionItem } =>
        entry.item !== undefined,
    )

  if (isLoading) return null
  if (error) return <ErrorMessage error={error} fallback="プリセットの取得に失敗しました。" />

  const notices = applicablePresets
    .map(({ preset }) => otherCategoryNotice(preset, categoryId))
    .filter((notice): notice is string => notice !== undefined)

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-foreground">プリセットから入力</span>
        <Link className="text-xs text-muted-foreground underline" to={`/expenses/presets?category_id=${categoryId}`}>
          プリセットを管理する
        </Link>
      </div>
      {applicablePresets.length === 0 ? (
        <p className="text-sm text-muted-foreground">この区分に使えるプリセットはまだありません。</p>
      ) : (
        <>
          <div className="flex flex-wrap gap-2">
            {applicablePresets.map(({ preset, item }) => (
              <Button
                key={preset.id}
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => {
                  applyPreset.mutate(preset.id)
                  onApply(item)
                }}
              >
                {preset.name}
              </Button>
            ))}
          </div>
          {notices.length > 0 && (
            <p className="text-xs text-muted-foreground">
              {notices.join('、')}。他の区分に切り替えてから同じプリセットを選ぶと、同じ申請に追加できます。
            </p>
          )}
        </>
      )}
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
 * フォームを表示する。選択中の区分・登録方法(個別/まとめて)は逆方向にも`?category=`/
 * `?mode=`へ書き込み(SKILL.md §2.10)、リロード・URL共有で状態を維持できるようにする
 * (読み取りと同じく下書き編集時は対象外)。ただし、まだ何も保存していない新規作成の間
 * (claimId確定前)に限る。明細を1件でも保存してclaimIdが確定した後は、そのclaimId自体が
 * URLに反映されないため(`/expenses/new`のまま)、以降のステップ(タイトル入力・区分の
 * 追加選択等)はURL化していない。claimId確定後も`/expenses/:id/edit`へ遷移させれば
 * リロード耐性を持たせられるが、既存の`/expenses/:id/edit`ルート(下書き編集の再開)と
 * 動作を揃えるための状態構造の見直しが必要になるため、大規模な構造変更を避ける方針のもと
 * 今回は見送っている(`known-gaps.md`参照)。
 */
export function ExpenseClaimNewPage() {
  const { systemSettings } = useAppSettings()
  const approvalRequired = systemSettings.expense_claim_requires_approval
  const navigate = useNavigate()
  const { id: routeClaimId } = useParams<{ id?: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const categoryCodeParam = searchParams.get('category')
  const modeParam = searchParams.get('mode')
  const [claimId, setClaimId] = useState<string | undefined>(routeClaimId)
  const [entryMode, setEntryMode] = useState<ExpenseClaimEntryMode | undefined>(
    !routeClaimId && (modeParam === 'individual' || modeParam === 'bulk') ? modeParam : undefined,
  )
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | undefined>(undefined)
  const [approverUserId, setApproverUserId] = useState<string | undefined>(undefined)
  // 単票入力へ適用中のプリセット明細。同じプリセットを続けて選び直しても反映されるよう
  // トークンをインクリメントして渡し、区分を切り替えたら次の区分に持ち越さないよう破棄する。
  const [singlePresetItem, setSinglePresetItem] = useState<ExpenseEntryPresetDefinitionItem | null>(null)
  const [singlePresetToken, setSinglePresetToken] = useState(0)

  const createClaim = useCreateExpenseClaim()
  const startClaimTitle = useUpdateExpenseClaimTitle()
  const { data: claim, isLoading: isLoadingClaim, error: claimError } = useExpenseClaim(claimId)
  const { data: categories, isLoading: isLoadingCategories, error: categoriesError } = useExpenseCategories()

  // ?category=のショートカットはページ表示時に1回だけ適用する。「区分を変更する」で
  // 選択を解除した後にこのeffectが再実行され、同じ区分へ戻されてしまわないようにする。
  // メニューからのショートカットは「個別に経費登録」相当として扱い、登録方法選択を省略する。
  // 初期表示時の値をrefで固定して使う(選択中の区分をこの後`?category=`へ書き戻すため、
  // 生の`categoryCodeParam`を依存に使うと、その書き戻し自体でこのeffectが再度走ってしまう)。
  const appliedCategoryShortcut = useRef(false)
  const initialCategoryCodeParam = useRef(categoryCodeParam).current
  useEffect(() => {
    if (routeClaimId || appliedCategoryShortcut.current || !initialCategoryCodeParam || !categories) return
    appliedCategoryShortcut.current = true
    const shortcutCategory = categories.find(
      (category) => category.code === initialCategoryCodeParam && category.is_active,
    )
    if (shortcutCategory) {
      setEntryMode('individual')
      setSelectedCategoryId(shortcutCategory.id)
    }
  }, [routeClaimId, initialCategoryCodeParam, categories])

  // 区分を切り替えたら、前の区分向けに選んだプリセットを次の区分の入力へ持ち越さない。
  useEffect(() => {
    setSinglePresetItem(null)
    setSinglePresetToken(0)
  }, [selectedCategoryId])

  /** 選択中の経費区分・登録方法(個別/まとめて)を`?category=`/`?mode=`へ書き込む
   *  (既存の読み取りと対称にする。SKILL.md §2.10)。下書き編集(`/expenses/:id/edit`)では、
   *  そもそも読み取り側もこのショートカットを使わないため、URLも書き換えない。claimIdが
   *  一度作成された後(保存済み明細がある状態)のURL化は、状態構造の見直しが必要になるため
   *  見送っている(`known-gaps.md`参照)。 */
  function setCategoryParam(code: string | null) {
    if (routeClaimId) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (code) next.set('category', code)
        else next.delete('category')
        return next
      },
      { replace: true },
    )
  }

  function setModeParam(mode: ExpenseClaimEntryMode | null) {
    if (routeClaimId) return
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (mode) next.set('mode', mode)
        else next.delete('mode')
        return next
      },
      { replace: true },
    )
  }

  const { rows, addRow, updateRow, removeRow, duplicateRow, moveRow, appendRows, reset } =
    useEditableRows<SaveExpenseItemInput>([])
  // 表形式入力(バッチ)は、明細の保存(まとめてAPIへ送信)より前に領収書を選ばせたいため、
  // 行(rowId)ごとに選択中のファイルをこの画面側で保持しておく。明細作成後、返ってきた
  // 明細IDに対して選択済みのファイルをアップロードする。
  const [rowFiles, setRowFiles] = useState<Record<number, File | null>>({})
  const addItem = useAddExpenseItem()
  const addItemsBulk = useAddExpenseItemsBulk()
  const updateItem = useUpdateExpenseItem()
  const deleteClaimMutation = useDeleteExpenseClaim()
  const deleteItem = useDeleteExpenseItem()
  const submitClaim = useSubmitExpenseClaim(claimId ?? '')
  const uploadAttachment = useUploadAttachment()

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
    setModeParam(mode)
  }

  /** 登録方法選択ステップへ戻る(まだ何も保存していない新規作成のみ)。 */
  const handleClearEntryMode = () => {
    setEntryMode(undefined)
    setModeParam(null)
  }

  /** 「まとめて経費登録」: 先にタイトルを確定させてから下書きを作成する。 */
  const handleStartBulkClaim = async (title: string) => {
    const id = await ensureClaimId()
    await startClaimTitle.mutateAsync({ claimId: id, title })
  }

  const handleSelectCategory = (category: ExpenseCategory) => {
    setSelectedCategoryId(category.id)
    setCategoryParam(category.code)
  }

  /** 区分選択ステップへ戻る(区分を変更する/別の区分の明細を追加する)。URLの`?category=`も
   *  合わせてクリアし、リロード・URL共有時に古い区分へ戻らないようにする。 */
  const handleClearSelectedCategory = () => {
    setSelectedCategoryId(undefined)
    setCategoryParam(null)
  }

  const handleSaveItems = async () => {
    // rowIdを保ったまま絞り込むことで、保存後に返ってくる明細と選択済みファイル
    // (rowFiles)を行単位で対応付けられるようにする(toData()はrowIdを剥がしてしまう)。
    const validRows = rows.filter((row) => row.usage_date && row.amount)
    if (validRows.length === 0) return
    const items = validRows.map(({ rowId, ...rest }) => {
      void rowId
      return rest
    })
    const wasNewClaim = !claimId
    const id = await ensureClaimId()
    const createdItems = await addItemsBulk.mutateAsync({ claimId: id, items })
    await Promise.all(
      createdItems.map((item, index) => {
        const file = rowFiles[validRows[index].rowId]
        return file ? uploadAttachment.mutateAsync({ ownerType: 'expense_item', ownerId: item.id, file }) : undefined
      }),
    )
    if (wasNewClaim && entryMode === 'individual') {
      const categoryName = categories?.find((category) => category.id === items[0].category_id)?.name
      void startClaimTitle.mutateAsync({ claimId: id, title: suggestIndividualTitle(categoryName, items[0].usage_date) })
    }
    reset([])
    setRowFiles({})
  }

  const handleSaveSingleItem = async (input: SaveExpenseItemInput, receiptFile: File | null) => {
    const wasNewClaim = !claimId
    const id = await ensureClaimId()
    const createdItem = await addItem.mutateAsync({ claimId: id, input })
    if (receiptFile) {
      await uploadAttachment.mutateAsync({ ownerType: 'expense_item', ownerId: createdItem.id, file: receiptFile })
    }
    if (wasNewClaim && entryMode === 'individual') {
      const categoryName = categories?.find((category) => category.id === input.category_id)?.name
      void startClaimTitle.mutateAsync({ claimId: id, title: suggestIndividualTitle(categoryName, input.usage_date) })
    }
  }

  const handleSubmit = async () => {
    if (!claimId || (approvalRequired && !approverUserId)) return
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
        onBack={handleClearEntryMode}
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
          onBack={!claimId ? handleClearEntryMode : undefined}
        />

        {claim && (
          <SavedItemsAndSubmit
            claim={claim}
            approverUserId={approverUserId}
            approvalRequired={approvalRequired}
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

  // 交通費(entry_mode='batch')は「まとめて登録」では複数明細をまとめて入力する表形式を、
  // 「個別に登録」ではほかの単発経費と同様に1件入力で完結するフォームを使う。区分自体の
  // entry_modeは変えず、どちらの画面を出すかだけをここで振り分ける(下書き再開時など
  // entryModeが未確定の場合は、まとめて登録時と同じ表形式を既定にする)。
  const showBatchTable = selectedCategory.entry_mode === 'batch' && entryMode !== 'individual'

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={`${selectedCategory.name}の明細を入力`}
        actions={
          <Button variant="secondary" size="sm" onClick={handleClearSelectedCategory}>
            {(claim?.items.length ?? 0) > 0 ? '別の区分の明細を追加する' : '区分を変更する'}
          </Button>
        }
      >
        {selectedCategory.entry_mode === 'batch' && (
          <div className="mb-4">
            <AttendanceReferenceLookup />
          </div>
        )}

        {showBatchTable ? (
          <>
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
                rowFiles={rowFiles}
                onRowFileChange={(rowId, file) => setRowFiles((prev) => ({ ...prev, [rowId]: file }))}
              />
            </div>

            {rows.length > 0 && (
              <div className="mt-4">
                <Button
                  isLoading={createClaim.isPending || addItemsBulk.isPending || uploadAttachment.isPending}
                  onClick={() => void handleSaveItems()}
                >
                  明細を保存する({rows.length}件)
                </Button>
                {addItemsBulk.error && <ErrorMessage error={addItemsBulk.error} />}
                {uploadAttachment.error && <ErrorMessage error={uploadAttachment.error} />}
              </div>
            )}
          </>
        ) : (
          <>
            {addItem.error && <ErrorMessage error={addItem.error} />}
            {uploadAttachment.error && <ErrorMessage error={uploadAttachment.error} />}

            <div className="mb-4">
              <SinglePresetPicker
                categoryId={selectedCategory.id}
                onApply={(item) => {
                  setSinglePresetItem(item)
                  setSinglePresetToken((token) => token + 1)
                }}
              />
            </div>

            <SingleExpenseItemForm
              fieldSet={fieldSetForCategory(selectedCategory)}
              categoryId={selectedCategory.id}
              fieldDefinitions={selectedCategory.field_definitions}
              onSubmit={(input, receiptFile) => void handleSaveSingleItem(input, receiptFile)}
              isSubmitting={createClaim.isPending || addItem.isPending || uploadAttachment.isPending}
              presetItem={singlePresetItem}
              presetApplyToken={singlePresetToken}
            />
          </>
        )}
      </Card>

      {claim && (
        <SavedItemsAndSubmit
          claim={claim}
          approverUserId={approverUserId}
          approvalRequired={approvalRequired}
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
  approvalRequired,
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
  approvalRequired: boolean
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
        <FormField
          label={approvalRequired ? '承認者' : '承認者(任意)'}
          htmlFor="approver"
          required={approvalRequired}
        >
          <UserPicker id="approver" value={approverUserId} onChange={onApproverChange} />
          {!approvalRequired && (
            <p className="mt-1 text-xs text-muted-foreground">
              現在の設定では経費精算の申請に承認は不要です。申請すると同時に確定します。承認者の指定は任意です。
            </p>
          )}
        </FormField>
        <div className="flex items-center gap-3">
          <Button
            isLoading={submitClaim.isPending}
            disabled={(approvalRequired && !approverUserId) || claim.items.length === 0}
            onClick={onSubmit}
          >
            申請する
          </Button>
          <Badge tone="neutral">下書き</Badge>
          {claim.status === 'draft' && (
            <ConfirmActionDialog
              triggerLabel="この下書きを削除する"
              triggerVariant="danger"
              title="この経費精算を削除しますか?"
              description="削除すると元に戻せません。保存済みの明細もすべて削除されます。"
              confirmLabel="削除する"
              isPending={deleteClaimMutation.isPending}
              onConfirm={onDeleteClaim}
            />
          )}
        </div>
      </Card>
    </>
  )
}
