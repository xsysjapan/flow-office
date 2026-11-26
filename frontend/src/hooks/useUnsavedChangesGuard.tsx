import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  useBlocker,
  useInRouterContext,
  useNavigate,
  type Location,
  type NavigateOptions,
  type To,
} from 'react-router-dom'
import { ConfirmActionDialog } from '../components/ConfirmActionDialog/ConfirmActionDialog'

/**
 * 止める移動の判定。`'pathname'`(既定)はパスが変わる移動、`'location'`はパスかクエリが変わる移動、
 * 関数は`(current, next) => boolean`(`ui-interaction-patterns` §2.9)。
 */
export type BlockOn = 'pathname' | 'location' | ((current: Location, next: Location) => boolean)

export interface UnsavedChangesGuardOptions {
  blockOn?: BlockOn
}

export interface UnsavedChangesGuard {
  /** 保存に成功した後の移動。この呼び出しの範囲だけ離脱警告を通す。 */
  navigateAfterSave: (to: To, options?: NavigateOptions) => void
  /** 外部への移動(`window.location.href = …`)など。フラグは戻さない(ページが離れるため)。 */
  leaveWithoutGuard: (fn: () => void) => void
  /** ページの中のキャンセル(表示の切り替え)の前に呼ぶ。変更があれば確認を出す。 */
  confirmDiscard: () => Promise<boolean>
}

interface Registration {
  active: boolean
  blockOn: (current: Location, next: Location) => boolean
}

interface GuardContextValue {
  register: (id: string, registration: Registration) => void
  unregister: (id: string) => void
  allowRef: { current: boolean }
  confirmDiscard: () => Promise<boolean>
}

const GuardContext = createContext<GuardContextValue | null>(null)

function evaluateBlockOn(blockOn: BlockOn | undefined, current: Location, next: Location): boolean {
  if (typeof blockOn === 'function') return blockOn(current, next)
  if (blockOn === 'location') {
    return current.pathname !== next.pathname || current.search !== next.search
  }
  return current.pathname !== next.pathname
}

/**
 * アプリの根に置く。`useBlocker`をここで1回だけ呼び、各ページが`useUnsavedChangesGuard`で登録した
 * 「変更あり」の画面から離れる移動を止めて確認のDialogを出す。登録と許可のフラグは、判定が描画の
 * 遅れの影響を受けないよう`ref`で持つ。
 */
export function UnsavedChangesProvider({ children }: { children: ReactNode }) {
  const registrations = useRef(new Map<string, Registration>())
  const allowRef = useRef(false)

  const blocker = useBlocker(({ currentLocation, nextLocation }) => {
    if (allowRef.current) {
      allowRef.current = false
      return false
    }
    for (const registration of registrations.current.values()) {
      if (registration.active && registration.blockOn(currentLocation, nextLocation)) return true
    }
    return false
  })

  const [discardResolver, setDiscardResolver] = useState<((ok: boolean) => void) | null>(null)

  const register = useCallback((id: string, registration: Registration) => {
    registrations.current.set(id, registration)
  }, [])
  const unregister = useCallback((id: string) => {
    registrations.current.delete(id)
  }, [])
  const confirmDiscard = useCallback(
    () =>
      new Promise<boolean>((resolve) => {
        setDiscardResolver(() => resolve)
      }),
    [],
  )

  const value = useMemo(
    () => ({ register, unregister, allowRef, confirmDiscard }),
    [register, unregister, confirmDiscard],
  )

  const resolveDiscard = (ok: boolean) => {
    discardResolver?.(ok)
    setDiscardResolver(null)
  }

  return (
    <GuardContext.Provider value={value}>
      {children}
      <ConfirmActionDialog
        open={blocker.state === 'blocked'}
        onOpenChange={(next) => {
          if (!next && blocker.state === 'blocked') blocker.reset()
        }}
        title="保存していない変更があります"
        description="このページを離れると、入力した内容は失われます。"
        confirmLabel="ページを離れる"
        confirmVariant="danger"
        cancelLabel="編集を続ける"
        onConfirm={() => {
          if (blocker.state === 'blocked') blocker.proceed()
        }}
      />
      <ConfirmActionDialog
        open={discardResolver !== null}
        onOpenChange={(next) => {
          if (!next) resolveDiscard(false)
        }}
        title="保存していない変更があります"
        description="編集をやめると、入力した内容は失われます。"
        confirmLabel="編集をやめる"
        confirmVariant="danger"
        cancelLabel="編集を続ける"
        onConfirm={() => resolveDiscard(true)}
      />
    </GuardContext.Provider>
  )
}

/**
 * 編集中に変更が失われるおそれがある間、アプリ内の移動(Provider配下)とタブを閉じる・リロードする
 * 操作(`beforeunload`)に対して確認を出す(`ui-interaction-patterns` §2.9)。Providerが無い
 * (MemoryRouterの単体テスト)ときは`beforeunload`だけを行う。
 */
export function useUnsavedChangesGuard(
  active: boolean,
  options?: UnsavedChangesGuardOptions,
): UnsavedChangesGuard {
  const context = useContext(GuardContext)
  // ルーター外(単体テストのrenderHook等)でも`beforeunload`だけは動かす。ルーターの有無は
  // マウント中に変わらないので、フックの呼び出し順は保たれる。
  const inRouter = useInRouterContext()
  // eslint-disable-next-line react-hooks/rules-of-hooks
  const navigate = inRouter ? useNavigate() : null
  const id = useId()
  const localAllowRef = useRef(false)
  const allowRef = context?.allowRef ?? localAllowRef
  const optionsRef = useRef(options)

  useEffect(() => {
    optionsRef.current = options
  })

  const register = context?.register
  const unregister = context?.unregister
  useEffect(() => {
    if (!register || !unregister) return
    register(id, {
      active,
      blockOn: (current, next) => evaluateBlockOn(optionsRef.current?.blockOn, current, next),
    })
    return () => unregister(id)
  }, [register, unregister, id, active])

  useEffect(() => {
    if (!active) return
    const handler = (e: BeforeUnloadEvent) => {
      if (allowRef.current) return
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [active, allowRef])

  const navigateAfterSave = useCallback(
    (to: To, navigateOptions?: NavigateOptions) => {
      allowRef.current = true
      try {
        if (!navigate) throw new Error('useUnsavedChangesGuard: navigateAfterSave requires a Router')
        void navigate(to, navigateOptions)
      } finally {
        allowRef.current = false
      }
    },
    [allowRef, navigate],
  )

  const leaveWithoutGuard = useCallback(
    (fn: () => void) => {
      allowRef.current = true
      try {
        fn()
      } catch (error) {
        allowRef.current = false
        throw error
      }
    },
    [allowRef],
  )

  const contextConfirmDiscard = context?.confirmDiscard
  const confirmDiscard = useCallback(async () => {
    if (!active || !contextConfirmDiscard) return true
    return contextConfirmDiscard()
  }, [active, contextConfirmDiscard])

  return { navigateAfterSave, leaveWithoutGuard, confirmDiscard }
}
