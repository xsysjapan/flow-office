import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '../Button/Button'

export interface PaginationProps {
  currentPage: number
  lastPage: number
  total: number
  onPageChange: (page: number) => void
}

export function Pagination({ currentPage, lastPage, total, onPageChange }: PaginationProps) {
  if (lastPage <= 1) return null

  return (
    <nav aria-label="ページ送り" className="mt-4 flex items-center justify-between gap-4">
      <p className="text-sm text-muted-foreground">
        {total}件中 {currentPage} / {lastPage} ページ
      </p>
      <div className="flex items-center gap-2">
        <Button
          size="icon"
          variant="secondary"
          aria-label="前のページ"
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
        >
          <ChevronLeft aria-hidden="true" />
        </Button>
        <Button
          size="icon"
          variant="secondary"
          aria-label="次のページ"
          disabled={currentPage >= lastPage}
          onClick={() => onPageChange(currentPage + 1)}
        >
          <ChevronRight aria-hidden="true" />
        </Button>
      </div>
    </nav>
  )
}
