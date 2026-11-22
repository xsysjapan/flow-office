import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { notify } from '../../lib/notify'
import { BulkActionDialog, type BulkActionResult } from './BulkActionDialog'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

function Harness({
  onExecute,
  onSucceeded = vi.fn(),
  confirmDisabled,
}: {
  onExecute: () => Promise<BulkActionResult>
  onSucceeded?: (ids: string[]) => void
  confirmDisabled?: boolean
}) {
  const [open, setOpen] = useState(true)
  return (
    <>
      <button onClick={() => setOpen(true)}>開く</button>
      <BulkActionDialog
        open={open}
        onOpenChange={setOpen}
        title="選択した3件を完了にしますか?"
        summary="選択した3件"
        description="完了すると担当者を変更できなくなります。"
        confirmLabel="3件を完了にする"
        confirmDisabled={confirmDisabled}
        onExecute={onExecute}
        onSucceeded={onSucceeded}
        successMessage={(count) => `${count}件のタスクを完了にしました`}
      >
        <input aria-label="入力欄" />
      </BulkActionDialog>
    </>
  )
}

/** フッターの「閉じる」。右上の×ボタン(sr-onlyで同名)とは別なので、最後の一致を使う。 */
async function findCloseButton() {
  const buttons = await screen.findAllByRole('button', { name: '閉じる' })
  return buttons[buttons.length - 1]
}

describe('BulkActionDialog', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('shows the title, summary, description and input', () => {
    render(<Harness onExecute={vi.fn()} />)

    expect(screen.getByText('選択した3件を完了にしますか?')).toBeInTheDocument()
    expect(screen.getByText('選択した3件')).toBeInTheDocument()
    expect(screen.getByText('完了すると担当者を変更できなくなります。')).toBeInTheDocument()
    expect(screen.getByLabelText('入力欄')).toBeInTheDocument()
  })

  it('keeps the confirm button disabled when confirmDisabled is set', () => {
    render(<Harness onExecute={vi.fn()} confirmDisabled />)

    expect(screen.getByRole('button', { name: '3件を完了にする' })).toBeDisabled()
  })

  it('closes, reports the succeeded ids and notifies once when everything succeeds', async () => {
    const user = userEvent.setup()
    const onSucceeded = vi.fn()
    render(
      <Harness
        onExecute={() => Promise.resolve({ succeededIds: ['a', 'b', 'c'], failures: [] })}
        onSucceeded={onSucceeded}
      />,
    )

    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))

    await waitFor(() => expect(screen.queryByText('選択した3件を完了にしますか?')).not.toBeInTheDocument())
    expect(onSucceeded).toHaveBeenCalledWith(['a', 'b', 'c'])
    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('3件のタスクを完了にしました')
  })

  it('cannot be closed while running', async () => {
    const user = userEvent.setup()
    let resolve: (value: BulkActionResult) => void = () => {}
    render(<Harness onExecute={() => new Promise<BulkActionResult>((r) => (resolve = r))} />)

    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))
    expect(await screen.findByRole('button', { name: 'キャンセル' })).toBeDisabled()
    await user.keyboard('{Escape}')
    expect(screen.getByText('選択した3件を完了にしますか?')).toBeInTheDocument()

    resolve({ succeededIds: ['a'], failures: [] })
    await waitFor(() => expect(screen.queryByText('選択した3件を完了にしますか?')).not.toBeInTheDocument())
  })

  it('stays open with the failure breakdown on partial failure and notifies only after it is closed', async () => {
    const user = userEvent.setup()
    const onSucceeded = vi.fn()
    render(
      <Harness
        onExecute={() =>
          Promise.resolve({
            succeededIds: ['a', 'b'],
            failures: [{ id: 'c', label: '名刺申請(佐藤)', message: '完了・取消済みのタスクは担当者を変更できません。' }],
          })
        }
        onSucceeded={onSucceeded}
      />,
    )

    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))

    expect(await screen.findByText('2件のタスクを完了にしました。1件はできませんでした。')).toBeInTheDocument()
    expect(screen.getByText('名刺申請(佐藤): 完了・取消済みのタスクは担当者を変更できません。')).toBeInTheDocument()
    expect(screen.getByText('できなかった1件は選択したままです。')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'キャンセル' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '3件を完了にする' })).not.toBeInTheDocument()
    expect(onSucceeded).toHaveBeenCalledWith(['a', 'b'])
    expect(notify.success).not.toHaveBeenCalled()

    await user.click(await findCloseButton())

    await waitFor(() => expect(screen.queryByText('実行結果')).not.toBeInTheDocument())
    expect(notify.success).toHaveBeenCalledTimes(1)
    expect(notify.success).toHaveBeenCalledWith('2件のタスクを完了にしました')
  })

  it('does not notify when every item failed', async () => {
    const user = userEvent.setup()
    const onSucceeded = vi.fn()
    render(
      <Harness
        onExecute={() =>
          Promise.resolve({ succeededIds: [], failures: [{ id: 'a', label: 'タスクA', message: '理由A' }] })
        }
        onSucceeded={onSucceeded}
      />,
    )

    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))
    expect(await screen.findByText('1件ともできませんでした。')).toBeInTheDocument()
    await user.click(await findCloseButton())

    await waitFor(() => expect(screen.queryByText('実行結果')).not.toBeInTheDocument())
    expect(onSucceeded).not.toHaveBeenCalled()
    expect(notify.success).not.toHaveBeenCalled()
  })

  it('stays open with an error message when onExecute throws, without touching the selection', async () => {
    const user = userEvent.setup()
    const onSucceeded = vi.fn()
    render(
      <Harness
        onExecute={() => Promise.reject(new ApiError(422, '遷移できないタスクが含まれています。'))}
        onSucceeded={onSucceeded}
      />,
    )

    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))

    expect(await screen.findByText('遷移できないタスクが含まれています。')).toBeInTheDocument()
    expect(screen.getByText('選択した3件を完了にしますか?')).toBeInTheDocument()
    expect(onSucceeded).not.toHaveBeenCalled()
    expect(notify.success).not.toHaveBeenCalled()
  })

  it('clears the previous result when reopened', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        onExecute={() =>
          Promise.resolve({ succeededIds: ['a'], failures: [{ id: 'b', label: 'B', message: '理由B' }] })
        }
      />,
    )
    await user.click(screen.getByRole('button', { name: '3件を完了にする' }))
    await user.click(await findCloseButton())
    await waitFor(() => expect(screen.queryByText('実行結果')).not.toBeInTheDocument())

    await user.click(screen.getByRole('button', { name: '開く' }))

    expect(await screen.findByText('選択した3件を完了にしますか?')).toBeInTheDocument()
  })
})
