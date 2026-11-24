import * as React from 'react'
import { cn } from '../../lib/utils'
import { useOverflowEdges } from '../../hooks/useOverflowEdges'

// 横にはみ出している側の端にだけ、内側の影(`--foreground`を薄めた色)を出す。
// 影は`data-overflow-start`・`data-overflow-end`(useOverflowEdges)で切り替え、強い影・グラデーションは使わない。
// Tailwindが検出できるよう、クラスは文字列リテラルのまま書く(連結・補間しない)。
const OVERFLOW_EDGE_CLASSES = [
  '[--edge-start:0_0_transparent] [--edge-end:0_0_transparent] [box-shadow:var(--edge-start),var(--edge-end)]',
  'data-[overflow-start]:[--edge-start:inset_8px_0_8px_-8px_color-mix(in_srgb,var(--foreground)_18%,transparent)]',
  'data-[overflow-end]:[--edge-end:inset_-8px_0_8px_-8px_color-mix(in_srgb,var(--foreground)_18%,transparent)]',
]

export function Table({ className, ...props }: React.HTMLAttributes<HTMLTableElement>) {
  const wrapperRef = useOverflowEdges<HTMLDivElement>()
  return (
    <div ref={wrapperRef} className={cn('relative w-full overflow-x-auto', ...OVERFLOW_EDGE_CLASSES)}>
      <table className={cn('w-full caption-bottom text-sm', className)} {...props} />
    </div>
  )
}

export function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn('[&_tr]:border-b [&_tr]:border-border', className)} {...props} />
}

export function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />
}

export function TableFooter({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return (
    <tfoot
      className={cn('border-t border-border bg-muted/50 font-medium [&>tr]:last:border-b-0', className)}
      {...props}
    />
  )
}

export function TableRow({ className, ...props }: React.HTMLAttributes<HTMLTableRowElement>) {
  return (
    <tr
      className={cn('border-b border-border transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted', className)}
      {...props}
    />
  )
}

export function TableHead({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      className={cn(
        'h-9 px-3 text-left align-middle text-xs font-medium text-muted-foreground whitespace-nowrap [&:has([role=checkbox])]:pr-0',
        className,
      )}
      {...props}
    />
  )
}

export function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cn('px-3 py-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0', className)}
      {...props}
    />
  )
}

export function TableCaption({ className, ...props }: React.HTMLAttributes<HTMLTableCaptionElement>) {
  return <caption className={cn('mt-4 text-sm text-muted-foreground', className)} {...props} />
}
