import { ApiError } from '../../api/client'
import './ErrorMessage.css'

export interface ErrorMessageProps {
  error: unknown
  fallback?: string
}

export function ErrorMessage({ error, fallback = '予期しないエラーが発生しました。' }: ErrorMessageProps) {
  const message = error instanceof ApiError ? error.message : error instanceof Error ? error.message : fallback
  const fieldErrors = error instanceof ApiError ? error.errors : undefined

  return (
    <div className="fo-error" role="alert">
      <p>{message}</p>
      {fieldErrors && (
        <ul>
          {Object.entries(fieldErrors).map(([field, messages]) => (
            <li key={field}>{messages.join(' ')}</li>
          ))}
        </ul>
      )}
    </div>
  )
}
