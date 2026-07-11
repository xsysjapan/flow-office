import { CircleAlert } from 'lucide-react'
import { ApiError } from '../../api/client'
import { Alert, AlertDescription } from '../ui/alert'

export interface ErrorMessageProps {
  error: unknown
  fallback?: string
}

export function ErrorMessage({ error, fallback = '予期しないエラーが発生しました。' }: ErrorMessageProps) {
  const message = error instanceof ApiError ? error.message : error instanceof Error ? error.message : fallback
  const fieldErrors = error instanceof ApiError ? error.errors : undefined

  return (
    <Alert variant="destructive" className="mb-4">
      <CircleAlert />
      <AlertDescription>
        <p>{message}</p>
        {fieldErrors && (
          <ul className="list-disc pl-4">
            {Object.entries(fieldErrors).map(([field, messages]) => (
              <li key={field}>{messages.join(' ')}</li>
            ))}
          </ul>
        )}
      </AlertDescription>
    </Alert>
  )
}
