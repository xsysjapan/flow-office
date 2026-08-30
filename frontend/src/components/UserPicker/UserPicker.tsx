import { useState } from 'react'
import { Check, ChevronsUpDown } from 'lucide-react'
import { useUserSearch } from '../../hooks/useUsers'
import { cn } from '../../lib/utils'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '../ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover'

export interface UserPickerProps {
  id: string
  value: string | undefined
  onChange: (userId: string | undefined) => void
  placeholder?: string
  /**
   * 指定すると、globalスコープで当該Permissionを保有するユーザーのみに選択肢を絞り込む
   * (例: 備品貸出申請の承認者を`asset.manage`保有者に絞る)。
   */
  permission?: string
}

/**
 * 氏名/メールアドレスで検索して社員を1人選ぶ入力。承認者指定・担当者割り当て・
 * 有給付与対象者選択など、社員IDを1件確定させたい場面で共通利用する。
 */
export function UserPicker({
  id,
  value,
  onChange,
  placeholder = '氏名またはメールアドレスで検索',
  permission,
}: UserPickerProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [selectedLabel, setSelectedLabel] = useState<string>()
  const { data } = useUserSearch(query, 100, permission)
  const suggestions = data?.data ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          id={id}
          type="button"
          role="combobox"
          aria-expanded={open}
          className={cn(
            'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40',
            !value && 'text-muted-foreground',
          )}
        >
          <span className="truncate">{value ? selectedLabel : placeholder}</span>
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
                  onSelect={() => {
                    onChange(user.id)
                    setSelectedLabel(`${user.name}(${user.email})`)
                    setQuery('')
                    setOpen(false)
                  }}
                >
                  <Check className={cn('size-4', user.id === value ? 'opacity-100' : 'opacity-0')} />
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
  )
}
