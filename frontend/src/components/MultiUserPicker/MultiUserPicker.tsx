import { useEffect, useMemo, useRef, useState } from 'react'
import { useFormFieldControl } from '../FormField/formFieldControl'
import { useQueries } from '@tanstack/react-query'
import { Check, ChevronsUpDown, X } from 'lucide-react'
import { searchUsers } from '../../api/users'
import { useUserSearch } from '../../hooks/useUsers'
import { cn } from '../../lib/utils'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '../ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

/** backendの`/users/search`の`ids`上限。 */
const IDS_CHUNK_SIZE = 100

export interface MultiUserPickerProps {
  id: string
  value: string[]
  onChange: (userIds: string[]) => void
  placeholder?: string
  /** 選択済みユーザーの表示名(氏名とメールアドレス)マップが変わるたびに呼ばれる。
   *  一括付与の結果表示で対象者名を出す用途向け(省略可)。 */
  onLabelsChange?: (labels: Record<string, string>) => void
}

/**
 * 氏名/メールアドレスで検索して複数の社員を選ぶ入力(`UserPicker`の複数選択版)。
 * 選択済みの社員は下にチップとして表示し、チップの削除ボタンで選択解除できる。
 */
export function MultiUserPicker({
  id,
  value,
  onChange,
  placeholder = '氏名またはメールアドレスで検索',
  onLabelsChange,
}: MultiUserPickerProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [labels, setLabels] = useState<Record<string, string>>({})
  const { data } = useUserSearch(query, 100)
  const suggestions = data?.data ?? []

  // 一覧から選んだ手元のラベルに無いID(初期値・作り直し・親から値が変わった)だけIDから取得する。
  const unresolvedIds = Array.from(new Set(value.filter((userId) => !labels[userId])))
  const chunks: string[][] = []
  for (let i = 0; i < unresolvedIds.length; i += IDS_CHUNK_SIZE) {
    chunks.push(unresolvedIds.slice(i, i + IDS_CHUNK_SIZE))
  }
  const lookups = useQueries({
    queries: chunks.map((chunk) => ({
      queryKey: ['users', 'by-ids', chunk],
      queryFn: () => searchUsers(undefined, undefined, undefined, chunk),
    })),
  })
  const lookupKey = lookups.map((q) => (q.data ? q.data.data.map((u) => u.id).join(',') : q.status)).join('|')
  const fetchedLabels = useMemo(() => {
    const map: Record<string, string> = {}
    for (const q of lookups) {
      for (const user of q.data?.data ?? []) map[user.id] = `${user.name}(${user.email})`
    }
    return map
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lookupKey])
  const allLabels = useMemo(() => ({ ...fetchedLabels, ...labels }), [fetchedLabels, labels])

  const displayName = (userId: string): string => {
    const known = allLabels[userId]
    if (known) return known
    const index = chunks.findIndex((chunk) => chunk.includes(userId))
    if (index >= 0 && lookups[index]?.isPending) return '読み込み中…'
    return '不明な社員'
  }

  // 取得した名前も含めて親へ渡す(更新関数の外で呼ぶ。内容が変わったときだけ)。
  const notifiedKey = useRef('{}')
  useEffect(() => {
    const key = JSON.stringify(allLabels)
    if (key === notifiedKey.current) return
    notifiedKey.current = key
    onLabelsChange?.(allLabels)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allLabels])

  const toggle = (userId: string, label: string) => {
    setLabels((prev) => ({ ...prev, [userId]: label }))
    if (value.includes(userId)) {
      onChange(value.filter((id) => id !== userId))
    } else {
      onChange([...value, userId])
    }
  }

  const remove = (userId: string) => {
    onChange(value.filter((id) => id !== userId))
  }

  const control = useFormFieldControl(id, 'native')
  return (
    <div className="flex flex-col gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            id={id}
            type="button"
            role="combobox"
            aria-expanded={open}
            {...control}
            className={cn(
              'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40',
              value.length === 0 && 'text-muted-foreground',
            )}
          >
            <span className="truncate">{value.length > 0 ? `${value.length}名を選択中` : placeholder}</span>
            <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
          </button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
          align="start"
        >
          <Command shouldFilter={false}>
            <CommandInput placeholder={placeholder} value={query} onValueChange={setQuery} />
            <CommandList>
              <CommandEmpty>該当する社員が見つかりません</CommandEmpty>
              <CommandGroup>
                {suggestions.map((user) => (
                  <CommandItem
                    key={user.id}
                    value={String(user.id)}
                    onSelect={() => toggle(user.id, `${user.name}(${user.email})`)}
                  >
                    <Check className={cn('size-4', value.includes(user.id) ? 'opacity-100' : 'opacity-0')} />
                    <span className="min-w-0 truncate">
                      {user.name}({user.email})
                    </span>
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>

      {value.length > 0 && (
        <ul className="flex flex-wrap gap-2">
          {value.map((userId) => (
            <li key={userId}>
              <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-secondary px-2 py-1 text-xs font-medium text-secondary-foreground">
                <span className="max-w-[16rem] truncate">{displayName(userId)}</span>
                <button
                  type="button"
                  aria-label={`${displayName(userId)}を選択解除`}
                  onClick={() => remove(userId)}
                  className="rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                >
                  <X className="size-3" />
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
