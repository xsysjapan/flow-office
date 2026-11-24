import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BackLink } from '../../components/BackLink/BackLink'
import { PageHeader } from '../../components/PageHeader/PageHeader'
import { Button } from '../../components/Button/Button'
import { Card } from '../../components/Card/Card'
import { ErrorMessage } from '../../components/ErrorMessage/ErrorMessage'
import { notify } from '../../lib/notify'
import { FormActions } from '../../components/FormActions/FormActions'
import { FormField } from '../../components/FormField/FormField'
import { FormLayout } from '../../components/FormLayout/FormLayout'
import { FormRow } from '../../components/FormRow/FormRow'
import { FormSection } from '../../components/FormSection/FormSection'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { NativeSelect } from '../../components/ui/native-select'
import { useBackTarget, useKeepBackState } from '../../navigation/backState'
import { useCreateHolidayCalendarSource, useHolidayCalendarSources } from '../../hooks/useHolidayCalendarSources'
import { useCreateWorkCalendar } from '../../hooks/useWorkCalendars'
import type { WeekdayHolidayPattern, WeekdayHolidayPatternDayType } from '../../api/types'

const ICS_FILE_ACCEPT = '.ics,.ical,.ifb'

const WEEKDAY_KEYS: (keyof WeekdayHolidayPattern)[] = ['1', '2', '3', '4', '5', '6', '7']

const WEEKDAY_LABELS: Record<keyof WeekdayHolidayPattern, string> = {
  '1': '月曜日',
  '2': '火曜日',
  '3': '水曜日',
  '4': '木曜日',
  '5': '金曜日',
  '6': '土曜日',
  '7': '日曜日',
}

const DAY_TYPE_OPTIONS: { value: WeekdayHolidayPatternDayType; label: string }[] = [
  { value: 'working', label: '勤務日' },
  { value: 'company_holiday', label: '所定休日' },
  { value: 'legal_holiday', label: '法定休日' },
]

/** 今日時点の暗黙ルール(土=所定休日・日=法定休日)と同じデフォルト値。 */
const DEFAULT_PATTERN: WeekdayHolidayPattern = {
  '1': 'working',
  '2': 'working',
  '3': 'working',
  '4': 'working',
  '5': 'working',
  '6': 'company_holiday',
  '7': 'legal_holiday',
}

const WEEK_STARTS_ON_OPTIONS = [
  { value: '0', label: '日曜日' },
  { value: '1', label: '月曜日' },
  { value: '2', label: '火曜日' },
  { value: '3', label: '水曜日' },
  { value: '4', label: '木曜日' },
  { value: '5', label: '金曜日' },
  { value: '6', label: '土曜日' },
]

/** ドロップダウンの「設定しない」「未設定」選択肢の値。 */
const NONE_OPTION_VALUE = ''

function emptyFormState() {
  return {
    name: '',
    weekStartsOn: NONE_OPTION_VALUE,
    fiscalYearStartMonth: '',
    fiscalYearStartDay: '',
    weekdayPattern: DEFAULT_PATTERN,
    // バックエンドのデフォルト(省略時true)に合わせる。ロックした状態で作成すると、
    // 作成直後に日別編集で祝日等を個別設定できず(UC-C010)、そのために毎回この
    // チェックボックスを操作する追加手順が必要になってしまうため。
    allowDailyHolidayOverride: true,
    holidaySourceId: NONE_OPTION_VALUE,
  }
}

/**
 * UC-C009: 会社カレンダー本体の新規作成ページ。名称のみが必須で、週の開始曜日・
 * 年度開始月日・曜日ごとの休日設定・祝日iCalendarソースの割当は任意。
 * 入力項目が多いため、以前はDialogだったが`ui-interaction-patterns` SKILL.md §2.11に
 * 従いPageへ切り出した(`known-gaps.md` §10)。
 *
 * Pattern exception: 祝日iCalendarソースの追加ボタン文言に「登録する」を使う。
 * Reason: 外部iCalendarソースの取り込み自体が「登録」と呼ばれる業務用語のため
 * (ui-interaction-patterns SKILL.md §2.7の例外)。
 */
export function WorkCalendarCreatePage() {
  const navigate = useNavigate()
  const backTarget = useBackTarget()
  const keepBackState = useKeepBackState()
  const { data: sourcesData } = useHolidayCalendarSources()
  const sources = sourcesData ?? []
  const createCalendar = useCreateWorkCalendar()
  const createSource = useCreateHolidayCalendarSource()

  const [form, setForm] = useState(emptyFormState())

  const [isRegisteringSource, setIsRegisteringSource] = useState(false)
  const [newSourceName, setNewSourceName] = useState('')
  const [newSourceMode, setNewSourceMode] = useState<'url' | 'upload'>('url')
  const [newSourceIcsUrl, setNewSourceIcsUrl] = useState('')
  const [newSourceIcsFile, setNewSourceIcsFile] = useState<File | undefined>(undefined)

  const patch = (next: Partial<typeof form>) => setForm((prev) => ({ ...prev, ...next }))

  const handleCreateSource = () => {
    createSource.mutate(
      {
        name: newSourceName,
        ics_url: newSourceMode === 'url' ? newSourceIcsUrl : undefined,
        ics_file: newSourceMode === 'upload' ? newSourceIcsFile : undefined,
      },
      {
        onSuccess: (created) => {
          setNewSourceName('')
          setNewSourceMode('url')
          setNewSourceIcsUrl('')
          setNewSourceIcsFile(undefined)
          setIsRegisteringSource(false)
          notify.success('iCalendarソースを登録しました')
          patch({ holidaySourceId: created.id })
        },
      },
    )
  }

  const handleSubmit = () => {
    createCalendar.mutate(
      {
        name: form.name,
        week_starts_on: form.weekStartsOn === NONE_OPTION_VALUE ? undefined : Number(form.weekStartsOn),
        fiscal_year_start_month: form.fiscalYearStartMonth ? Number(form.fiscalYearStartMonth) : undefined,
        fiscal_year_start_day: form.fiscalYearStartDay ? Number(form.fiscalYearStartDay) : undefined,
        weekday_holiday_pattern: form.weekdayPattern,
        allows_daily_holiday_override: form.allowDailyHolidayOverride,
        holiday_calendar_source_id: form.holidaySourceId === NONE_OPTION_VALUE ? undefined : form.holidaySourceId,
      },
      {
        // 作成後は作成された会社カレンダーの詳細画面(正史画面)へ遷移する(SKILL.md §2.5)。
        onSuccess: (created) => {
          notify.success(`「${created.name}」を作成しました`)
          navigate(`/admin/work-calendars/${created.id}`, { state: keepBackState })
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <BackLink className="mb-0" />

      <PageHeader description="会社カレンダーの名称などを入力して作成し、必要なら休日ソースも登録します。" />

      <Card>
        <FormLayout>
        <FormField label="カレンダー名" htmlFor="create-calendar-name" required>
          <Input id="create-calendar-name" value={form.name} onChange={(e) => patch({ name: e.target.value })} />
        </FormField>

        <FormField label="週の開始曜日" htmlFor="create-calendar-week-starts-on">
          <NativeSelect
            id="create-calendar-week-starts-on"
            value={form.weekStartsOn}
            onChange={(e) => patch({ weekStartsOn: e.target.value })}
          >
            <option value={NONE_OPTION_VALUE}>未設定(既定値を使用)</option>
            {WEEK_STARTS_ON_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        <FormRow>
          <FormField label="年度開始月" htmlFor="create-calendar-fiscal-year-start-month">
            <Input
              id="create-calendar-fiscal-year-start-month"
              type="number"
              min={1}
              max={12}
              value={form.fiscalYearStartMonth}
              onChange={(e) => patch({ fiscalYearStartMonth: e.target.value })}
            />
          </FormField>

          <FormField label="年度開始日" htmlFor="create-calendar-fiscal-year-start-day">
            <Input
              id="create-calendar-fiscal-year-start-day"
              type="number"
              min={1}
              max={31}
              value={form.fiscalYearStartDay}
              onChange={(e) => patch({ fiscalYearStartDay: e.target.value })}
            />
          </FormField>
        </FormRow>

        <FormSection
          title="曜日ごとの休日区分"
          headingLevel={2}
          description="ここで設定した内容がカレンダーの年度作成時の曜日ごとの休日区分になります。"
        >
          <FormRow columns={4}>
            {WEEKDAY_KEYS.map((weekdayKey) => (
              <FormField
                key={weekdayKey}
                label={WEEKDAY_LABELS[weekdayKey]}
                htmlFor={`create-calendar-weekday-pattern-${weekdayKey}`}
              >
                <NativeSelect
                  id={`create-calendar-weekday-pattern-${weekdayKey}`}
                  value={form.weekdayPattern[weekdayKey]}
                  onChange={(e) =>
                    patch({
                      weekdayPattern: {
                        ...form.weekdayPattern,
                        [weekdayKey]: e.target.value as WeekdayHolidayPatternDayType,
                      },
                    })
                  }
                >
                  {DAY_TYPE_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </NativeSelect>
              </FormField>
            ))}
          </FormRow>
          <div className="mb-4">
            <label className="flex items-center gap-2 text-sm text-foreground">
              <Checkbox
                aria-label="曜日ごとの休日設定を日ごとに個別変更できるようにする"
                checked={form.allowDailyHolidayOverride}
                onCheckedChange={(checked) => patch({ allowDailyHolidayOverride: checked === true })}
              />
              曜日ごとの休日設定を日ごとに個別変更できるようにする
            </label>
            <p className="mt-1 text-xs text-muted-foreground">
              この設定を有効にすると、会社カレンダーの各日ごとに休日区分を個別に変更できるようになります。
            </p>
          </div>
        </FormSection>

        <div className="mt-4" />

        <FormField label="休日iCalendarソース" htmlFor="create-calendar-holiday-source">
          <NativeSelect
            id="create-calendar-holiday-source"
            value={form.holidaySourceId}
            onChange={(e) => patch({ holidaySourceId: e.target.value })}
          >
            <option value={NONE_OPTION_VALUE}>設定しない</option>
            {sources.map((source) => (
              <option key={source.id} value={source.id}>
                {source.name}
              </option>
            ))}
          </NativeSelect>
        </FormField>

        {createSource.error && <ErrorMessage error={createSource.error} />}

        {!isRegisteringSource ? (
          <div className="mb-4">
            <Button variant="secondary" onClick={() => setIsRegisteringSource(true)}>
              新しいiCalendarを登録する
            </Button>
          </div>
        ) : (
          <div className="mb-4">
            <div>
              <FormField label="名称" htmlFor="create-calendar-holiday-source-name" required>
                <Input
                  id="create-calendar-holiday-source-name"
                  value={newSourceName}
                  onChange={(e) => setNewSourceName(e.target.value)}
                />
              </FormField>

              <div className="mb-4 flex flex-col gap-2">
                <span className="text-sm font-medium text-foreground">登録方法</span>
                <div className="flex gap-4">
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <input
                      type="radio"
                      name="create-calendar-holiday-source-mode"
                      checked={newSourceMode === 'url'}
                      onChange={() => setNewSourceMode('url')}
                    />
                    URLで登録
                  </label>
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <input
                      type="radio"
                      name="create-calendar-holiday-source-mode"
                      checked={newSourceMode === 'upload'}
                      onChange={() => setNewSourceMode('upload')}
                    />
                    ファイルをアップロード
                  </label>
                </div>
              </div>
            </div>

            {newSourceMode === 'url' ? (
              <FormField label="iCalendar URL" htmlFor="create-calendar-holiday-source-ics-url" required>
                <Input
                  id="create-calendar-holiday-source-ics-url"
                  type="url"
                  value={newSourceIcsUrl}
                  onChange={(e) => setNewSourceIcsUrl(e.target.value)}
                />
              </FormField>
            ) : (
              <FormField label="iCalendarファイル" htmlFor="create-calendar-holiday-source-ics-file" required>
                <input
                  id="create-calendar-holiday-source-ics-file"
                  type="file"
                  accept={ICS_FILE_ACCEPT}
                  onChange={(e) => setNewSourceIcsFile(e.target.files?.[0])}
                />
              </FormField>
            )}

            <FormActions>
              <Button variant="secondary" onClick={() => setIsRegisteringSource(false)}>
                キャンセル
              </Button>
              <Button
                isLoading={createSource.isPending}
                disabled={!newSourceName || (newSourceMode === 'url' ? !newSourceIcsUrl : !newSourceIcsFile)}
                onClick={handleCreateSource}
              >
                登録する
              </Button>
            </FormActions>
          </div>
        )}

        {!form.name && <p className="mb-2 text-xs text-muted-foreground">カレンダー名を入力してください。</p>}
        {createCalendar.error && <ErrorMessage error={createCalendar.error} />}
        <FormActions>
          <Button variant="secondary" onClick={() =>
              backTarget
                ? navigate(backTarget.to, { state: backTarget.state, replace: true })
                : navigate('/admin/work-calendars')
            }>
            キャンセル
          </Button>
          <Button isLoading={createCalendar.isPending} disabled={!form.name} onClick={handleSubmit}>
            作成
          </Button>
        </FormActions>
        </FormLayout>
      </Card>
    </div>
  )
}
