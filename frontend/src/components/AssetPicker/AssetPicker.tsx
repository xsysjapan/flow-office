import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import type { IScannerControls } from '@zxing/browser'
import { Camera, Check, Search } from 'lucide-react'
import { useAssetSearch } from '../../hooks/useAsset'
import { Button } from '../Button/Button'
import { Checkbox } from '../ui/checkbox'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '../ui/command'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { Popover, PopoverAnchor, PopoverContent } from '../ui/popover'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'

export interface AssetPickerProps {
  id: string
  /** 何を対象に追加しようとしているかの説明(例:「貸出対象に追加する備品」)。 */
  label: string
  onSubmit: (value: string) => Promise<void> | void
  isPending?: boolean
  disabled?: boolean
  disabledReason?: string
  /**
   * 連続読み取り(複数選択できる場面)を許可するか。既定はtrue。単一選択の場面
   * (今回対象外だが将来利用する場合)で使う場合はfalseを渡す(spec 論点12)。
   */
  allowContinuousScan?: boolean
}

/** QRの中身(`/assets/qr/{token}`形式のURL)が同一値で連続検出されるのを防ぐデバウンス時間。 */
const SCAN_DEBOUNCE_MS = 2000

type PickerMode = 'search' | 'scan'

/**
 * 備品ピッカー(spec 論点12)。テキストでの管理番号検索と、カメラでのQR読み取りの両方に
 * 対応した`AssetScanInput`の置き換え。外部インターフェース(`onSubmit`)は`AssetScanInput`と
 * 互換に保つ。
 */
export function AssetPicker({
  id,
  label,
  onSubmit,
  isPending = false,
  disabled = false,
  disabledReason,
  allowContinuousScan = true,
}: AssetPickerProps) {
  const [open, setOpen] = useState(false)
  const [mode, setMode] = useState<PickerMode>('search')
  const [query, setQuery] = useState('')
  const [continuousScan, setContinuousScan] = useState(true)
  const [cameraError, setCameraError] = useState<string | null>(null)

  const anchorRef = useRef<HTMLDivElement>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  const controlsRef = useRef<IScannerControls | null>(null)
  const lastScanRef = useRef<{ value: string; at: number } | null>(null)

  const { data } = useAssetSearch({ asset_no: query || undefined, per_page: 20 })
  const suggestions = data?.data ?? []

  const busy = isPending || disabled

  async function submit(value: string) {
    const trimmed = value.trim()
    if (!trimmed || busy) return
    await onSubmit(trimmed)
    setQuery('')
  }

  function handleInputKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') {
      e.preventDefault()
      submit(query)
    }
  }

  function openSearchMode() {
    setMode('search')
    setCameraError(null)
    setOpen(true)
  }

  function openScanMode() {
    if (busy) return
    setMode('scan')
    setOpen(true)
  }

  // カメラQRリーダー(mode==='scan'かつポップアップが開いている間だけ起動する)。
  useEffect(() => {
    if (mode !== 'scan' || !open) return
    if (!navigator.mediaDevices?.getUserMedia) {
      setCameraError('このブラウザはカメラ読み取りに対応していません。管理番号を入力して検索してください。')
      return
    }

    let cancelled = false
    setCameraError(null)

    async function start() {
      try {
        const { BrowserQRCodeReader } = await import('@zxing/browser')
        const reader = new BrowserQRCodeReader()
        const controls = await reader.decodeFromVideoDevice(undefined, videoRef.current ?? undefined, (result) => {
          if (!result || cancelled) return
          const value = result.getText()
          const now = Date.now()
          const last = lastScanRef.current
          if (last && last.value === value && now - last.at < SCAN_DEBOUNCE_MS) return
          lastScanRef.current = { value, at: now }

          void (async () => {
            await submit(value)
            if (!allowContinuousScan || !continuousScan) {
              controlsRef.current?.stop()
              setOpen(false)
              setMode('search')
            }
          })()
        })
        if (cancelled) {
          controls.stop()
          return
        }
        controlsRef.current = controls
      } catch {
        if (!cancelled) {
          setCameraError('カメラを起動できませんでした。権限を確認するか、管理番号を入力して検索してください。')
        }
      }
    }

    void start()

    return () => {
      cancelled = true
      controlsRef.current?.stop()
      controlsRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, open, allowContinuousScan, continuousScan])

  return (
    <div className="flex flex-col gap-2">
      <FormField label={label} htmlFor={id}>
        <Popover
          open={open}
          onOpenChange={(next) => {
            setOpen(next)
            if (!next) setMode('search')
          }}
        >
          <PopoverAnchor asChild>
            <div className="flex gap-2" ref={anchorRef}>
              <Input
                id={id}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onFocus={openSearchMode}
                onKeyDown={handleInputKeyDown}
                placeholder="管理番号で検索、またはEnterで追加"
                disabled={disabled}
                autoComplete="off"
              />
              <Button
                type="button"
                variant="secondary"
                size="icon"
                aria-label="QRカメラで読み取る"
                onClick={openScanMode}
                disabled={disabled}
              >
                <Camera />
              </Button>
            </div>
          </PopoverAnchor>
          <PopoverContent
            className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
            align="start"
            onOpenAutoFocus={(e) => e.preventDefault()}
            onInteractOutside={(e) => {
              // トリガーがAnchor(単なるref)のため、Radixの既定挙動では検索欄・カメラボタン
              // 自体をクリック/フォーカスするたびに「外側の操作」とみなされポップアップが
              // 即座に閉じてしまう。ピッカー自身の行(検索欄・カメラボタン)への操作は
              // 「外側」として扱わない。
              if (anchorRef.current?.contains(e.target as Node)) e.preventDefault()
            }}
          >
            {mode === 'search' ? (
              <Command shouldFilter={false}>
                <CommandList>
                  <CommandEmpty>該当する備品が見つかりません</CommandEmpty>
                  <CommandGroup>
                    {suggestions.map((asset) => (
                      <CommandItem key={asset.id} value={asset.id} onSelect={() => submit(asset.asset_no)}>
                        <Check className="size-4 opacity-0" />
                        <span className="min-w-0 truncate">
                          {asset.asset_no} {asset.name}
                        </span>
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            ) : (
              <div className="flex flex-col gap-3 p-3">
                {allowContinuousScan && (
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <Checkbox
                      checked={continuousScan}
                      onCheckedChange={(checked) => setContinuousScan(checked === true)}
                    />
                    連続読み取り
                  </label>
                )}
                {cameraError ? (
                  <>
                    <ErrorMessage error={new Error(cameraError)} />
                    <Button type="button" variant="secondary" onClick={openSearchMode}>
                      <Search />
                      テキスト検索に戻る
                    </Button>
                  </>
                ) : (
                  <video
                    ref={videoRef}
                    className="aspect-video w-full rounded-md bg-black"
                    muted
                    playsInline
                    aria-label="QRコードカメラ映像"
                  />
                )}
              </div>
            )}
          </PopoverContent>
        </Popover>
      </FormField>
      {disabled && disabledReason && <p className="text-sm text-muted-foreground">{disabledReason}</p>}
    </div>
  )
}
