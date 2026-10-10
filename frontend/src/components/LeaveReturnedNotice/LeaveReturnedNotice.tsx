import { Link } from 'react-router-dom'
import { Button } from '../Button/Button'

/**
 * 差し戻された休暇申請の案内と、その申請の申請詳細への導線。
 *
 * 差戻しは申請前の状態に戻る(休暇は勤怠の休暇表示から外れる)。申請者は申請詳細の「提出する」で
 * 同じ内容のまま再提出できる。取消は一覧の取消ボタンから行う。
 * 導線は休暇申請の`workflow_request_id`(対応するワークフローのID)から申請詳細へ直接向ける。
 * `workflowRequestId`がnullのときは導線を出さない(案内の文だけ出す)。
 */
export function LeaveReturnedNotice({ workflowRequestId }: { workflowRequestId: string | null }) {
  return (
    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
      <span>差し戻されています。申請詳細の「提出する」で再提出できます。</span>
      {workflowRequestId !== null && (
        <Button asChild variant="secondary">
          <Link to={`/requests/${workflowRequestId}`}>申請詳細を開く</Link>
        </Button>
      )}
    </div>
  )
}
