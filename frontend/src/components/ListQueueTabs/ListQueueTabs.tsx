import { Tabs, TabsList, TabsTrigger } from '../ui/tabs'
import { cn } from '../../lib/utils'

export interface ListQueueTab {
  value: string
  label: string
  count?: number
}

export interface ListQueueTabsProps {
  tabs: ListQueueTab[]
  value: string
  onChange: (value: string) => void
  'aria-label': string
  className?: string
}

/** 作業キュー型の一覧の主軸タブ(件数付き)。切り替えはonChangeのみで、パネルは持たない。 */
export function ListQueueTabs({ tabs, value, onChange, 'aria-label': ariaLabel, className }: ListQueueTabsProps) {
  return (
    <Tabs value={value} onValueChange={onChange} className={className}>
      <TabsList aria-label={ariaLabel} className={cn('max-w-full overflow-x-auto')}>
        {tabs.map((tab) => (
          <TabsTrigger
            key={tab.value}
            value={tab.value}
            aria-label={tab.count !== undefined ? `${tab.label} ${tab.count}件` : undefined}
          >
            {tab.label}
            {tab.count !== undefined && <span className="ml-1 text-muted-foreground">{tab.count}</span>}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  )
}
