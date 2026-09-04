import { useEffect, useState } from 'react'
import type { WorkCalendarYear } from '../../api/types'
import { useCorrectWorkCalendarYearFiscalYear } from '../../hooks/useWorkCalendars'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { Textarea } from '../ui/textarea'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../ui/dialog'

export interface CorrectFiscalYearDialogProps {
  /** 対象年度。nullのときは閉じた状態として扱う。 */
  year: WorkCalendarYear | null
  companyCalendarId: string
  onOpenChange: (open: boolean) => void
  onCorrected?: () => void
}

/**
 * 管理者専用: 誤って公開してしまった年度の年度番号・開始日・終了日を、ステータス
 * (draft/published/archived)や締め済み月の有無にかかわらず強制的に訂正する特例操作。
 * 通常の公開/取消/廃止/複製とは別枠の「公開時の入力ミス救済ツール」であり、
 * `WorkCalendarDaysPage`の年度アクション群からは視覚的に分離して呼び出す。
 */
export function CorrectFiscalYearDialog({ year, companyCalendarId, onOpenChange, onCorrected }: CorrectFiscalYearDialogProps) {
  const correctFiscalYear = useCorrectWorkCalendarYearFiscalYear(companyCalendarId)

  const [fiscalYear, setFiscalYear] = useState('')
  const [startsOn, setStartsOn] = useState('')
  const [endsOn, setEndsOn] = useState('')
  const [reason, setReason] = useState('')

  // 呼び出し側はDialogを常時マウントしたまま`year`のnull/非nullで開閉を制御するため
  // (`WorkCalendarDaysPage`参照)、Radixの`onOpenChange`は開いた瞬間には発火しない。
  // そのため、対象年度が渡された(=開かれた)タイミングでフォームを年度の現在値に同期する。
  useEffect(() => {
    if (!year) return
    setFiscalYear(String(year.fiscal_year))
    setStartsOn(year.starts_on)
    setEndsOn(year.ends_on)
    setReason('')
    correctFiscalYear.reset()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [year?.id])

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      correctFiscalYear.reset()
    }
    onOpenChange(open)
  }

  const handleSubmit = () => {
    if (!year) return

    correctFiscalYear.mutate(
      {
        id: year.id,
        input: {
          fiscal_year: Number(fiscalYear),
          starts_on: startsOn,
          ends_on: endsOn,
          reason: reason || undefined,
        },
      },
      {
        onSuccess: () => {
          onCorrected?.()
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={year !== null} onOpenChange={handleOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>年度を訂正しますか?</DialogTitle>
          <DialogDescription>
            公開済み・廃止済みの年度、締め済みの月がある年度でも、年度番号・期間を強制的に書き換えます。
            通常の公開・取消・複製とは異なり、誤って公開してしまった年度番号や期間を訂正するための特例操作です。
            日次・月次の勤怠実績データ自体は変更しません。
          </DialogDescription>
        </DialogHeader>

        {correctFiscalYear.error && <ErrorMessage error={correctFiscalYear.error} />}

        <div className="flex flex-col gap-1">
          <FormField label="年度番号" htmlFor="correct-fiscal-year">
            <Input
              id="correct-fiscal-year"
              type="number"
              value={fiscalYear}
              onChange={(e) => setFiscalYear(e.target.value)}
            />
          </FormField>
          <FormField label="開始日" htmlFor="correct-starts-on">
            <Input
              id="correct-starts-on"
              type="date"
              value={startsOn}
              onChange={(e) => setStartsOn(e.target.value)}
            />
          </FormField>
          <FormField label="終了日" htmlFor="correct-ends-on">
            <Input id="correct-ends-on" type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} />
          </FormField>
          <FormField label="訂正理由(任意)" htmlFor="correct-reason">
            <Textarea id="correct-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
          </FormField>
        </div>

        <DialogFooter>
          <Button variant="secondary" onClick={() => handleOpenChange(false)}>
            キャンセル
          </Button>
          <Button variant="danger" isLoading={correctFiscalYear.isPending} onClick={handleSubmit}>
            訂正する
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
