import { TriangleAlert } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useCorrectionPending } from '../../api/correctionPending'
import { useAuth } from '../../auth/useAuth'
import { Alert, AlertDescription } from '../ui/alert'

/**
 * 旧い形式のイベントの補正待ち(503, code=correction_pending)の間、画面上部に出す案内。
 * 全画面を塞がず(メンテナンス表示とは別)、補正は 管理メニュー > 運用コマンド から実行する。
 * 運用コマンドの権限(admin_command.view)がある利用者にだけリンクを出す。
 */
export function CorrectionPendingBanner() {
  const { message } = useCorrectionPending()
  const { user } = useAuth()

  if (message === null) return null

  const canOpenCommands = Boolean(user?.effective_permissions?.includes('admin_command.view'))

  return (
    <Alert className="mb-4">
      <TriangleAlert />
      <AlertDescription>
        <p>{message}</p>
        <p>
          管理者は 管理メニュー &gt; 運用コマンド から補正を実行してください。
          {canOpenCommands && (
            <>
              {' '}
              <Link to="/admin/commands" className="font-medium underline">
                運用コマンドを開く
              </Link>
            </>
          )}
        </p>
      </AlertDescription>
    </Alert>
  )
}
