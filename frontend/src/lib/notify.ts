import { toast } from 'sonner'

/**
 * 変更操作の成功をトーストで知らせる。画面から`sonner`を直接importせず、必ずこれを使う。
 * 成功のみ通知する(エラー・部分失敗は操作した場所にErrorMessageで出す)。文言は「何がどうなったか」を書く。
 * テストでは各テストファイルの先頭で`vi.mock('<相対パス>/lib/notify', () => ({ notify: { success: vi.fn() } }))`と書き、
 * `beforeEach`で`vi.mocked(notify.success).mockClear()`してから、呼ばれた文言を確かめる(例: BackOfficeTaskListPage.test.tsx)。
 */
export const notify = {
  success(message: string, opts?: { description?: string }) {
    toast.success(message, { description: opts?.description })
  },
}
