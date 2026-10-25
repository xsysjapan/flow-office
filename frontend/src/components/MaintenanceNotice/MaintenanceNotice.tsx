import { useEffect } from 'react'
import { Wrench } from 'lucide-react'
import { useMaintenanceStatus } from '../../hooks/useReadModelRebuilds'
import { Alert, AlertDescription, AlertTitle } from '../ui/alert'

export interface MaintenanceNoticeProps {
  /** APIが返したメッセージ。 */
  message: string
}

/**
 * 全画面の「メンテナンス中」表示。表示している間は GET /maintenance-status を15秒ごとに読み、
 * maintenance:false になったら画面を再読込する。
 */
export function MaintenanceNotice({ message }: MaintenanceNoticeProps) {
  const status = useMaintenanceStatus(true)
  const maintenance = status.data?.maintenance

  useEffect(() => {
    if (maintenance === false) window.location.reload()
  }, [maintenance])

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-6">
      <Alert className="max-w-lg">
        <Wrench />
        <AlertTitle>メンテナンス中</AlertTitle>
        <AlertDescription>
          <p>{message}</p>
          <p>終了すると自動で画面を読み込み直します。</p>
        </AlertDescription>
      </Alert>
    </div>
  )
}
