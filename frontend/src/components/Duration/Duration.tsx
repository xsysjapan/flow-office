import { cn } from '../../lib/utils'

export interface DurationProps {
  minutes: number
  className?: string
}

function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60

  if (hours === 0) return `${remainingMinutes}分`
  if (remainingMinutes === 0) return `${hours}時間`
  return `${hours}時間${remainingMinutes}分`
}

function formatCompactDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return `${hours}:${String(remainingMinutes).padStart(2, '0')}`
}

/** PCでは日本語、モバイルでは省スペース表記で分数を表示する。 */
export function Duration({ minutes, className }: DurationProps) {
  return (
    <span className={cn(className)}>
      <span className="hidden sm:inline">{formatDuration(minutes)}</span>
      <span className="sm:hidden">{formatCompactDuration(minutes)}</span>
    </span>
  )
}