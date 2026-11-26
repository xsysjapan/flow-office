import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as attachmentsApi from '../../api/attachments'
import { ApiError } from '../../api/client'
import type { Attachment } from '../../api/types'
import { AttachmentPanel } from './AttachmentPanel'
import { notify } from '../../lib/notify'

vi.mock('../../lib/notify', () => ({ notify: { success: vi.fn() } }))

function renderPanel(
  props: Partial<React.ComponentProps<typeof AttachmentPanel>> = {},
  attachments: Attachment[] = [],
) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  vi.spyOn(attachmentsApi, 'fetchAttachments').mockResolvedValue(attachments)

  return render(
    <QueryClientProvider client={queryClient}>
      <AttachmentPanel ownerType="expense_item" ownerId="expense-item-1" {...props} />
    </QueryClientProvider>,
  )
}

describe('AttachmentPanel', () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear()
  })

  it('renders the list of existing attachments with formatted size', async () => {
    renderPanel(
      {},
      [
        { id: 'attachment-9', file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 2048, uploaded_by: 'applicant-1', created_at: null },
      ],
    )

    expect(await screen.findByText('receipt.pdf(2.0KB)')).toBeInTheDocument()
  })

  it('shows the empty state message when there are no attachments', async () => {
    renderPanel({}, [])

    expect(await screen.findByText('添付ファイルはありません。')).toBeInTheDocument()
  })

  it('uploads a selected file with the given ownerType and ownerId', async () => {
    vi.spyOn(attachmentsApi, 'uploadAttachment').mockResolvedValue({
      id: 'attachment-1',
      file_name: 'receipt.pdf',
      mime_type: 'application/pdf',
      file_size: 100,
      uploaded_by: 'applicant-1',
      created_at: null,
    })

    renderPanel()
    await screen.findByText('添付ファイルはありません。')

    const file = new File(['dummy'], 'receipt.pdf', { type: 'application/pdf' })
    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    await userEvent.upload(input, file)

    await waitFor(() =>
      expect(attachmentsApi.uploadAttachment).toHaveBeenCalledWith('expense_item', 'expense-item-1', file),
    )
    await waitFor(() => expect(notify.success).toHaveBeenCalledWith('receipt.pdfを添付しました'))
  })

  it('shows the 422 file error under the file input and the rest in the summary, each once', async () => {
    const errors = { file: ['ファイルのエラー'], unknown_key: ['未知のキーのエラー'] }
    vi.spyOn(attachmentsApi, 'uploadAttachment').mockRejectedValue(new ApiError(422, '最初のエラーの文', errors))

    renderPanel()
    await screen.findByText('添付ファイルはありません。')
    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    await userEvent.upload(input, new File(['dummy'], 'receipt.pdf', { type: 'application/pdf' }))

    expect(await screen.findByText('入力内容を確認してください。')).toBeInTheDocument()
    expect(screen.getAllByText('ファイルのエラー')).toHaveLength(1)
    expect(screen.getAllByText('未知のキーのエラー')).toHaveLength(1)
    expect(screen.queryByText('最初のエラーの文')).not.toBeInTheDocument()
  })

  it('downloads an attachment on click', async () => {
    vi.spyOn(attachmentsApi, 'downloadAttachment').mockResolvedValue(undefined)

    renderPanel(
      {},
      [
        { id: 'attachment-9', file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 2048, uploaded_by: 'applicant-1', created_at: null },
      ],
    )

    await userEvent.click(await screen.findByRole('button', { name: 'ダウンロード' }))

    await waitFor(() => expect(attachmentsApi.downloadAttachment).toHaveBeenCalledWith('attachment-9', 'receipt.pdf'))
  })

  it('hides the upload UI when readOnly', async () => {
    renderPanel({ readOnly: true }, [])

    await screen.findByText('添付ファイルはありません。')
    expect(document.querySelector('input[type="file"]')).not.toBeInTheDocument()
  })

  it('shows a warning when required and no attachments exist', async () => {
    renderPanel({ required: true }, [])

    expect(await screen.findByText('領収書の添付が必要です')).toBeInTheDocument()
  })

  it('does not show the warning when required but attachments exist', async () => {
    renderPanel(
      { required: true },
      [
        { id: 'attachment-9', file_name: 'receipt.pdf', mime_type: 'application/pdf', file_size: 2048, uploaded_by: 'applicant-1', created_at: null },
      ],
    )

    await screen.findByText('receipt.pdf(2.0KB)')
    expect(screen.queryByText('領収書の添付が必要です')).not.toBeInTheDocument()
  })

  it('renders in compact mode without error', async () => {
    renderPanel({ compact: true }, [])

    expect(await screen.findByText('添付ファイルはありません。')).toBeInTheDocument()
  })
})
