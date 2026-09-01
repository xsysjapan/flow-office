import { useState } from 'react'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '../ui/command'
import { Input } from '../ui/input'
import { Popover, PopoverAnchor, PopoverContent } from '../ui/popover'

export interface CategoryComboboxProps {
  id: string
  value: string
  onChange: (value: string) => void
  suggestions: string[]
  placeholder?: string
}

/**
 * 自由入力を許しつつ、既存カテゴリ候補をサジェストする補完付き入力。
 * spec(論点6): 表記ゆれ抑制のための候補提示であり、選択を強制しない
 * (候補にない文字列も`onChange`でそのまま確定できる)。
 */
export function CategoryCombobox({ id, value, onChange, suggestions, placeholder }: CategoryComboboxProps) {
  const [open, setOpen] = useState(false)
  const filtered = suggestions.filter((s) => s.toLowerCase().includes(value.trim().toLowerCase()))
  const showSuggestions = open && filtered.length > 0

  return (
    <Popover open={showSuggestions} onOpenChange={setOpen}>
      <PopoverAnchor asChild>
        <Input
          id={id}
          role="combobox"
          aria-expanded={showSuggestions}
          autoComplete="off"
          placeholder={placeholder}
          value={value}
          onChange={(e) => {
            onChange(e.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
        />
      </PopoverAnchor>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
        align="start"
        onOpenAutoFocus={(e) => e.preventDefault()}
        onCloseAutoFocus={(e) => e.preventDefault()}
      >
        <Command shouldFilter={false}>
          <CommandList>
            <CommandEmpty>候補はありません</CommandEmpty>
            <CommandGroup>
              {filtered.map((suggestion) => (
                <CommandItem
                  key={suggestion}
                  value={suggestion}
                  // Inputのblurより先にクリックを処理するためmousedownでpreventDefaultする
                  onMouseDown={(e) => e.preventDefault()}
                  onSelect={() => {
                    onChange(suggestion)
                    setOpen(false)
                  }}
                >
                  {suggestion}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
