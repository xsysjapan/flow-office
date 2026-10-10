import { Link } from 'react-router-dom'
import { Button } from '../Button/Button'

export type LeaveRequestSubjectType = 'paid_leave_request' | 'special_leave_request' | 'compensatory_leave_request'

/**
 * 差し戻された休暇申請の案内と、差戻し中の申請一覧への導線。
 *
 * 差戻しは申請前の状態に戻る(休暇は勤怠の休暇表示から外れる)。申請者は申請詳細の「提出する」で
 * 同じ内容のまま再提出できる。取消は一覧の取消ボタンから行う。
 * 差戻し中の申請は休暇申請の一覧からは申請詳細を特定できないため、申請一覧の差戻し絞り込みへ遷移させる。
 */
export function LeaveReturnedNotice({ subjectType }: { subjectType: LeaveRequestSubjectType }) {
  return (
    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
      <span>差し戻されています。申請詳細の「提出する」で再提出できます。</span>
      <Button asChild variant="secondary">
        <Link to={`/requests?status=returned&subjectType=${subjectType}`}>差戻し中の申請を開く</Link>
      </Button>
    </div>
  )
}
