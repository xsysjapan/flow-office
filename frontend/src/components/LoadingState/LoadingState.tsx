import { Skeleton } from '../ui/skeleton'

export interface LoadingStateProps {
  label?: string
}

export function LoadingState({ label = '読み込み中...' }: LoadingStateProps) {
  return (
    <div role="status" className="flex flex-col gap-2 py-3">
      <span className="sr-only">{label}</span>
      <Skeleton className="h-5 w-2/3" aria-hidden="true" />
      <Skeleton className="h-5 w-1/2" aria-hidden="true" />
      <Skeleton className="h-24 w-full" aria-hidden="true" />
    </div>
  )
}
