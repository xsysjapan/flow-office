import * as React from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { DayPicker } from 'react-day-picker'
import { cn } from '../../lib/utils'
import { buttonVariants } from './button'

export type CalendarProps = React.ComponentProps<typeof DayPicker>

/**
 * react-day-picker(shadcn/ui相当)のカレンダー本体。単体では使わず、
 * `DatePicker`(Popover + Button トリガー)経由で使うことを想定する。
 */
export function Calendar({ className, classNames, showOutsideDays = true, ...props }: CalendarProps) {
  return (
    <DayPicker
      showOutsideDays={showOutsideDays}
      className={cn('p-3', className)}
      classNames={{
        months: 'flex flex-col gap-2',
        month: 'flex flex-col gap-4',
        month_caption: 'flex justify-center pt-1 relative items-center h-9',
        caption_label: 'text-sm font-medium',
        nav: 'flex items-center justify-between absolute inset-x-0 top-0 h-9',
        button_previous: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-7 bg-transparent p-0 text-muted-foreground hover:text-foreground',
        ),
        button_next: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-7 bg-transparent p-0 text-muted-foreground hover:text-foreground',
        ),
        month_grid: 'w-full border-collapse mt-2',
        weekdays: 'flex',
        weekday: 'text-muted-foreground w-9 text-xs font-normal',
        week: 'flex w-full mt-1',
        day: 'p-0 text-center text-sm relative [&:has([data-selected])]:bg-accent size-9',
        day_button: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-9 p-0 font-normal aria-selected:opacity-100 hover:bg-accent',
        ),
        selected: 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground focus:bg-primary focus:text-primary-foreground [&>button]:bg-primary [&>button]:text-primary-foreground',
        today: '[&>button]:border [&>button]:border-border',
        outside: 'text-muted-foreground opacity-50',
        disabled: 'text-muted-foreground opacity-50',
        hidden: 'invisible',
        ...classNames,
      }}
      components={{
        Chevron: ({ orientation, ...chevronProps }) =>
          orientation === 'left' ? (
            <ChevronLeft className="size-4" {...chevronProps} />
          ) : (
            <ChevronRight className="size-4" {...chevronProps} />
          ),
      }}
      {...props}
    />
  )
}
