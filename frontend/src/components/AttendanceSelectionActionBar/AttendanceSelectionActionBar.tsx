import { Link } from 'react-router-dom'
import { Button } from '../Button/Button'

export interface AttendanceSelectionActionBarProps {
  /** 選択中の日数 */
  selectedCount: number
  /** 特別休暇の申請導線を出すかどうか(システム設定で有効な特別休暇種別があるか) */
  hasSpecialLeaveTypes: boolean
  /** 選択した日付をカンマ区切りにしたクエリ文字列(各申請画面へ`?dates=`で渡す) */
  datesQuery: string
  /** 選択モードを終了する(選択解除) */
  onCancel: () => void
}

/**
 * 週次・月次勤怠の「選択モード」で表示する一括操作バー。iOS Mail風の選択モードで選んだ
 * 日付をもとに、有給休暇・特別休暇・代休の申請へまとめて遷移する導線をまとめる。
 *
 * モバイル幅(`sm`未満)では、①選択件数とキャンセルを1行、②申請導線ボタン列を横スクロール
 * 可能な別行、に分けて表示する(ボタン群がラップして崩れるのを防ぐ)。`sm`以上では従来通り
 * 1行に収める。
 */
export function AttendanceSelectionActionBar({
  selectedCount,
  hasSpecialLeaveTypes,
  datesQuery,
  onCancel,
}: AttendanceSelectionActionBarProps) {
  const hasSelection = selectedCount > 0

  return (
    <div className="flex w-full basis-full flex-col gap-2 sm:w-auto sm:basis-auto sm:flex-row sm:items-center">
      <div className="flex items-center justify-between gap-2 sm:contents">
        <span className="text-sm whitespace-nowrap text-muted-foreground">{selectedCount}件を選択中</span>
        <Button variant="secondary" size="sm" className="sm:order-last" onClick={onCancel}>
          キャンセル
        </Button>
      </div>
      <div className="-mx-1 flex gap-2 overflow-x-auto px-1 sm:mx-0 sm:overflow-visible sm:px-0">
        {hasSelection ? (
          <>
            <Button asChild variant="secondary" size="sm" className="shrink-0">
              <Link to={`/paid-leave?dates=${datesQuery}`}>有給休暇を申請する</Link>
            </Button>
            {hasSpecialLeaveTypes && (
              <Button asChild variant="secondary" size="sm" className="shrink-0">
                <Link to={`/special-leave?dates=${datesQuery}`}>特別休暇を申請する</Link>
              </Button>
            )}
            <Button asChild variant="secondary" size="sm" className="shrink-0">
              <Link to={`/compensatory-leave?dates=${datesQuery}`}>代休を申請する</Link>
            </Button>
          </>
        ) : (
          <>
            <Button variant="secondary" size="sm" className="shrink-0" disabled>
              有給休暇を申請する
            </Button>
            {hasSpecialLeaveTypes && (
              <Button variant="secondary" size="sm" className="shrink-0" disabled>
                特別休暇を申請する
              </Button>
            )}
            <Button variant="secondary" size="sm" className="shrink-0" disabled>
              代休を申請する
            </Button>
          </>
        )}
      </div>
    </div>
  )
}
