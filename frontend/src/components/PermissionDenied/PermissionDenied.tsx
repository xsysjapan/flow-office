import { ShieldAlert } from 'lucide-react'
import { Alert, AlertDescription } from '../ui/alert'

export interface PermissionDeniedProps {
  message?: string
}

export function PermissionDenied({ message = 'この操作を行う権限がありません。' }: PermissionDeniedProps) {
  return (
    <Alert className="border-warning/30 bg-warning/10 text-warning [&>svg]:text-warning">
      <ShieldAlert />
      <AlertDescription>
        <p>{message}</p>
      </AlertDescription>
    </Alert>
  )
}
