import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as assetApi from '../../api/asset'
import type { Asset, Paginated } from '../../api/types'
import { AssetPicker } from './AssetPicker'

const asset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: null,
  management_type: 'lending',
  lending_status: 'available',
  installation_status: null,
  lending_method: 'self_service',
  default_location_text: '本社4F',
  qr_token: 'qr-token-1',
  qr_url: 'https://example.com/assets/qr/qr-token-1',
  current_loan_id: null,
  notes: null,
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

const paginatedAssets: Paginated<Asset> = {
  data: [asset],
  meta: { current_page: 1, last_page: 1, total: 1 },
  links: { next: null, prev: null },
}

// getUserMediaが無い(未対応ブラウザ)場合のデフォルト値。カメラを実際に使うテストのみ上書きする。
let mockGetUserMedia: (() => Promise<MediaStream>) | undefined

beforeEach(() => {
  mockGetUserMedia = undefined
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: () =>
        mockGetUserMedia ? mockGetUserMedia() : Promise.reject(new Error('not mocked')),
    },
  })
})

afterEach(() => {
  vi.restoreAllMocks()
})

vi.mock('@zxing/browser', async () => {
  const stop = vi.fn()
  return {
    BrowserQRCodeReader: class {
      async decodeFromVideoDevice() {
        return { stop }
      }
    },
  }
})

function renderPicker(props: Partial<React.ComponentProps<typeof AssetPicker>> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onSubmit = props.onSubmit ?? vi.fn().mockResolvedValue(undefined)
  vi.spyOn(assetApi, 'searchAssets').mockResolvedValue(paginatedAssets)

  render(
    <QueryClientProvider client={queryClient}>
      <AssetPicker id="picker" label="貸出対象に追加する備品" onSubmit={onSubmit} {...props} />
    </QueryClientProvider>,
  )

  return onSubmit
}

describe('AssetPicker', () => {
  it('テキスト検索で候補を選ぶとonSubmitが呼ばれ、入力欄がクリアされる', async () => {
    const onSubmit = renderPicker()

    const input = screen.getByLabelText('貸出対象に追加する備品')
    await userEvent.click(input)
    await userEvent.type(input, 'EQ-00121')

    const option = await screen.findByRole('option', { name: /EQ-00121/ })
    await userEvent.click(option)

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith('EQ-00121'))
    expect(input).toHaveValue('')
  })

  it('テキスト欄でEnterを押すと入力値のままonSubmitが呼ばれる', async () => {
    const onSubmit = renderPicker()

    const input = screen.getByLabelText('貸出対象に追加する備品')
    await userEvent.click(input)
    await userEvent.type(input, 'EQ-00999{enter}')

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith('EQ-00999'))
  })

  it('カメラアイコンをクリックするとQRリーダー表示に切り替わる', async () => {
    mockGetUserMedia = () => new Promise(() => {}) // pending: 起動中の見た目を確認するだけでよい
    renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'QRカメラで読み取る' }))

    expect(await screen.findByLabelText('QRコードカメラ映像')).toBeInTheDocument()
  })

  it('カメラ非対応ブラウザではエラーメッセージを表示し、テキスト検索に戻れる', async () => {
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined })
    renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'QRカメラで読み取る' }))

    expect(await screen.findByText(/カメラ読み取りに対応していません/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'テキスト検索に戻る' }))
    expect(screen.getByLabelText('貸出対象に追加する備品')).toBeInTheDocument()
  })

  it('allowContinuousScan=trueなら連続読み取りトグルが表示される(既定)', async () => {
    mockGetUserMedia = () => new Promise(() => {})
    renderPicker()

    await userEvent.click(screen.getByRole('button', { name: 'QRカメラで読み取る' }))

    expect(await screen.findByText('連続読み取り')).toBeInTheDocument()
  })

  it('allowContinuousScan=falseなら連続読み取りトグルは表示されない', async () => {
    mockGetUserMedia = () => new Promise(() => {})
    renderPicker({ allowContinuousScan: false })

    await userEvent.click(screen.getByRole('button', { name: 'QRカメラで読み取る' }))

    await screen.findByLabelText('QRコードカメラ映像')
    expect(screen.queryByText('連続読み取り')).not.toBeInTheDocument()
  })

  it('disabledの場合は入力欄・カメラボタンが操作できず、理由が表示される', () => {
    renderPicker({ disabled: true, disabledReason: '先に貸出先ユーザーを選択してください。' })

    expect(screen.getByLabelText('貸出対象に追加する備品')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'QRカメラで読み取る' })).toBeDisabled()
    expect(screen.getByText('先に貸出先ユーザーを選択してください。')).toBeInTheDocument()
  })
})
