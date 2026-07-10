import './LoadingState.css'

export interface LoadingStateProps {
  label?: string
}

export function LoadingState({ label = '読み込み中...' }: LoadingStateProps) {
  return (
    <p className="fo-loading" role="status">
      {label}
    </p>
  )
}
