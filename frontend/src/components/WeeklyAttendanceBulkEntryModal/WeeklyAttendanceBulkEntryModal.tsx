import { useState } from 'react'
import { useAuth } from '../../auth/useAuth'
import { useGenerateAttendancePattern } from '../../hooks/useAttendance'
import { attendancePatternResultMessage } from '../../utils/attendancePatternResultMessage'
import { browserOffsetString } from '../../utils/offsetDateTime'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import {
  buildWeeklyPatternFromSimpleState,
  defaultSimplePatternState,
  expandSimplePatternToWeekdayRows,
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
  /** 確定完了時に呼び出し元画面へ完了メッセージを渡す(モーダル自身は結果を表示せず閉じる)。 */
  onCompleted?: (message: string) => void
}

/**
 * 週次勤怠画面(WeekAttendancePage)から開く一括入力モーダル。対象は常に本人。
 * 「まとめて設定」(開始/終了時刻を1組だけ入力し、適用する曜日を選ぶだけの簡易入力)を
 * 既定タブとし、曜日ごとに個別の時刻を指定したい場合向けに従来の詳細入力をもう1つの
 * タブとして残す。「まとめて設定」タブではそのまま確定させず、必ず「曜日ごとに設定」
 * タブへ内容を展開してから確定させる(誤って複数曜日を一括で確定してしまう事故を防ぐ)。
 */
export function WeeklyAttendanceBulkEntryModal({
  defaultFrom,
  defaultTo,
  open: controlledOpen,
  onOpenChange,
  onCompleted,
}: WeeklyAttendanceBulkEntryModalProps) {
  const { user } = useAuth()
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const isOpen = isControlled ? controlledOpen : uncontrolledOpen

  const [activeTab, setActiveTab] = useState<'simple' | 'detailed'>('simple')
  const [offset, setOffset] = useState(browserOffsetString())
  const [reason, setReason] = useState('')
  const [simplePatternState, setSimplePatternState] = useState<SimplePatternState>(defaultSimplePatternState())
  const [detailedPatternState, setDetailedPatternState] = useState<Record<number, WeekdayRowState>>(
    defaultWeeklyPatternState(),
  )
  const [overwriteMode, setOverwriteMode] = useState<'skip_existing' | 'overwrite_existing'>('skip_existing')

  const generatePattern = useGenerateAttendancePattern()

  const handleOpenChange = (next: boolean) => {
    if (!isControlled) setUncontrolledOpen(next)
    onOpenChange?.(next)
    if (next) {
      setActiveTab('simple')
      setReason('')
      setSimplePatternState(defaultSimplePatternState())
      setDetailedPatternState(defaultWeeklyPatternState())
      generatePattern.reset()
    }
  }

  const handleDetailedWeekdayChange = (iso: number, patch: Partial<WeekdayRowState>) => {
    setDetailedPatternState((prev) => ({ ...prev, [iso]: { ...prev[iso], ...patch } }))
  }

  const handleExpandToDetailed = () => {
    setDetailedPatternState(expandSimplePatternToWeekdayRows(simplePatternState))
    setActiveTab('detailed')
  }

  const handleGenerate = () => {
    if (!user || !reason) return
    generatePattern.mutate(
      {
        user_id: user.id,
        from: defaultFrom,
        to: defaultTo,
        utc_offset: offset,
        weekly_pattern: buildWeeklyPattern(detailedPatternState),
        overwrite_mode: overwriteMode,
        reason,
      },
      {
        onSuccess: (data) => {
          onCompleted?.(attendancePatternResultMessage(data))
          handleOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleOpenChange}>
      {!isControlled && (
        <Button variant="secondary" onClick={() => handleOpenChange(true)}>
          一括入力
        </Button>
      )}
      <DialogContent size="large" className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>週次の一括入力</DialogTitle>
          <DialogDescription>出退勤・休憩時刻を指定して一括で確定する。</DialogDescription>
        </DialogHeader>

        <p className="text-sm text-muted-foreground">適用期間: {defaultFrom} 〜 {defaultTo}</p>

        {generatePattern.error && <ErrorMessage error={generatePattern.error} />}

        <FormField label="タイムゾーンオフセット" htmlFor="weekly-attendance-offset" required>
          <Input
            id="weekly-attendance-offset"
            value={offset}
            placeholder="+09:00"
            pattern="^[+-]\d{2}:\d{2}$"
            onChange={(e) => setOffset(e.target.value)}
          />
        </FormField>

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
            <p className="mt-3 text-xs text-muted-foreground">
              内容を確認のうえ確定するため、「次へ(曜日ごとの内容を確認)」を押すと「曜日ごとに設定」タブに展開されます。ここではまだ確定されません。
            </p>
          </TabsContent>
          <TabsContent value="detailed">
            <WeekdayScheduleFields state={detailedPatternState} onChange={handleDetailedWeekdayChange} />
          </TabsContent>
        </Tabs>

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

        {activeTab === 'simple' ? (
          <Button onClick={handleExpandToDetailed}>次へ(曜日ごとの内容を確認)</Button>
        ) : (
          <Button isLoading={generatePattern.isPending} disabled={!user || !reason} onClick={handleGenerate}>
            確定する
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}
