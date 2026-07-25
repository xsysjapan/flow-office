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
      className={cn('relative w-fit p-3', className)}
      classNames={{
        months: 'relative flex flex-col gap-4',
        month: 'flex w-fit flex-col gap-3',
        month_caption: 'flex h-8 w-full items-center justify-center px-9',
        caption_label: 'flex h-8 items-center justify-center text-sm font-medium leading-none select-none',
        nav: 'absolute inset-x-0 top-0 z-10 flex h-8 items-center justify-between',
        button_previous: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-8 bg-transparent p-0 text-muted-foreground select-none hover:text-foreground',
        ),
        button_next: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-8 bg-transparent p-0 text-muted-foreground select-none hover:text-foreground',
        ),
        month_grid: 'table-fixed border-collapse',
        weekdays: 'table-row',
        weekday: 'size-9 p-0 text-center text-xs font-normal text-muted-foreground select-none',
        week: 'table-row',
        day: 'relative size-9 p-0 text-center text-sm',
        day_button: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-9 p-0 font-normal aria-selected:opacity-100',
        ),
        selected:
          '[&>button]:bg-primary [&>button]:text-primary-foreground [&>button]:hover:bg-primary [&>button]:hover:text-primary-foreground [&>button]:focus-visible:bg-primary',
        range_start: 'bg-accent [&>button]:rounded-l-md',
        range_middle: 'bg-accent [&>button]:rounded-none [&>button]:bg-transparent [&>button]:text-foreground',
        range_end: 'bg-accent [&>button]:rounded-r-md',
        today: '[&>button]:bg-accent [&>button]:text-accent-foreground',
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
