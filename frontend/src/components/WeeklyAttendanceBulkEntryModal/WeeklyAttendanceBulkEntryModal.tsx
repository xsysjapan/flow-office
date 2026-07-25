import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { useGenerateAttendancePattern, usePreviewAttendancePattern } from '../../hooks/useAttendance'
import { browserOffsetString } from '../../utils/offsetDateTime'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import {
  buildWeeklyPatternFromSimpleState,
  defaultSimplePatternState,
  SimplePatternFields,
  type SimplePatternState,
} from '../SimplePatternFields/SimplePatternFields'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog'
import { Input } from '../ui/input'
import { NativeSelect } from '../ui/native-select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs'
import { buildWeeklyPattern, defaultWeeklyPatternState, WeekdayScheduleFields, type WeekdayRowState } from '../WeekdayScheduleFields/WeekdayScheduleFields'

export interface WeeklyAttendanceBulkEntryModalProps {
  /** 呼び出し元(週次勤怠画面)が表示している週の開始日・終了日を初期値にする。 */
  defaultFrom: string
  defaultTo: string
  /** 一覧から個別にトリガーボタンを描画したくない場合(制御されたopen/onOpenChange)向け。省略時は自前のトリガーボタンを表示する。 */
  open?: boolean
  onOpenChange?: (open: boolean) => void
}

/**
 * 週次勤怠画面(WeekAttendancePage)から開く一括入力モーダル。対象は常に本人。
 * 「まとめて設定」(開始/終了時刻を1組だけ入力し、適用する曜日を選ぶだけの簡易入力)を
 * 既定タブとし、曜日ごとに個別の時刻を指定したい場合向けに従来の詳細入力をもう1つの
 * タブとして残す。
 */
export function WeeklyAttendanceBulkEntryModal({
  defaultFrom,
  defaultTo,
  open: controlledOpen,
  onOpenChange,
}: WeeklyAttendanceBulkEntryModalProps) {
  const { user } = useAuth()
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const isOpen = isControlled ? controlledOpen : uncontrolledOpen

  const [activeTab, setActiveTab] = useState<'simple' | 'detailed'>('simple')
  const [from, setFrom] = useState(defaultFrom)
  const [to, setTo] = useState(defaultTo)
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const [simplePatternState, setSimplePatternState] = useState<SimplePatternState>(defaultSimplePatternState())
  const [detailedPatternState, setDetailedPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [overwriteMode, setOverwriteMode] = useState<'skip_existing' | 'overwrite_existing'>('skip_existing')

  const previewPattern = usePreviewAttendancePattern()
  const generatePattern = useGenerateAttendancePattern()

  const handleOpenChange = (next: boolean) => {
    if (!isControlled) setUncontrolledOpen(next)
    onOpenChange?.(next)
    if (next) {
      setFrom(defaultFrom)
      setTo(defaultTo)
      setReason('')
      previewPattern.reset()
      generatePattern.reset()
    }
  }

  const handleDetailedWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setDetailedPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const weeklyPattern =
    activeTab === 'simple'
      ? buildWeeklyPatternFromSimpleState(simplePatternState)
      : buildWeeklyPattern(detailedPatternState)

  const handlePreview = () => {
    if (!from || !to) return
    previewPattern.mutate({ from, to, utc_offset: offset, weekly_pattern: weeklyPattern })
  }

  const handleGenerate = () => {
    if (!user || !from || !to || !reason) return
    generatePattern.mutate({
      user_id: user.id,
      from,
      to,
      utc_offset: offset,
      weekly_pattern: weeklyPattern,
      overwrite_mode: overwriteMode,
      reason,
    })
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      {!isControlled && (
        <Button variant="secondary" onClick={() => handleOpenChange(true)}>
          一括入力
        </Button>
      )}
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>週次の一括入力</DialogTitle>
          <DialogDescription>出退勤・休憩時刻を指定して、期間へ一括展開する。</DialogDescription>
        </DialogHeader>

        {previewPattern.error && <ErrorMessage error={previewPattern.error} />}
        {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="適用開始日" htmlFor="weekly-attendance-from" required>
            <Input id="weekly-attendance-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>

          <FormField label="適用終了日" htmlFor="weekly-attendance-to" required>
            <Input id="weekly-attendance-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </FormField>

          <FormField label="タイムゾーンオフセット" htmlFor="weekly-attendance-offset" required>
            <Input
              id="weekly-attendance-offset"
              value={offset}
              placeholder="+09:00"
              pattern="^[+-]\d{2}:\d{2}$"
              onChange={(e) => setOffset(e.target.value)}
            />
          </FormField>
        </div>

        <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as 'simple' | 'detailed')}>
          <TabsList>
            <TabsTrigger value="simple">まとめて設定</TabsTrigger>
            <TabsTrigger value="detailed">曜日ごとに設定</TabsTrigger>
          </TabsList>
          <TabsContent value="simple">
            <SimplePatternFields
              state={simplePatternState}
              onChange={(patch) => setSimplePatternState((prev) => ({ ...prev, ...patch }))}
            />
          </TabsContent>
          <TabsContent value="detailed">
            <WeekdayScheduleFields state={detailedPatternState} onChange={handleDetailedWeekdayChange} />
          </TabsContent>
        </Tabs>

        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" isLoading={previewPattern.isPending} disabled={!from || !to} onClick={handlePreview}>
            プレビューする
          </Button>
        </div>

        {previewPattern.data && (
          <ul className="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
            {previewPattern.data.days.map((day) => (
              <li key={day.date} className="text-foreground">
                {day.date}: {day.start_time}〜{day.end_time}
                {day.has_existing_day && <span className="ml-1 text-xs text-muted-foreground">(既存実績あり)</span>}
                {day.is_locked && <span className="ml-1 text-xs text-destructive">(締め済み)</span>}
              </li>
            ))}
          </ul>
        )}

        <FormField label="確定理由" htmlFor="weekly-attendance-reason" required>
          <Input id="weekly-attendance-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>

        <FormField label="既存の実績がある日の扱い" htmlFor="weekly-attendance-overwrite-mode">
          <NativeSelect
            id="weekly-attendance-overwrite-mode"
            value={overwriteMode}
            onChange={(e) => setOverwriteMode(e.target.value as 'skip_existing' | 'overwrite_existing')}
          >
            <option value="skip_existing">既存の実績がある日はスキップする(安全)</option>
            <option value="overwrite_existing">既存の実績がある日も上書きする</option>
          </NativeSelect>
          <p className="mt-1 text-xs text-muted-foreground">締め済み・承認済みの月に属する日はどちらを選んでも変更されない。</p>
        </FormField>

        <Button
          isLoading={generatePattern.isPending}
          disabled={!user || !from || !to || !reason}
          onClick={handleGenerate}
        >
          確定する
        </Button>

        {generatePattern.data && (
          <p className="text-sm text-foreground">
            {generatePattern.data.created_count}件作成・{generatePattern.data.updated_count}件更新しました。
            {generatePattern.data.skipped_count > 0 && `既存実績のため${generatePattern.data.skipped_count}件をスキップしました。`}
            {generatePattern.data.rejected_count > 0 && `締め済み等のため${generatePattern.data.rejected_count}件は反映できませんでした。`}
          </p>
        )}
      </DialogContent>
    </Dialog>
  )
}
