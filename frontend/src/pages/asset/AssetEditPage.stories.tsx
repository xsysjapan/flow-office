import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { Asset } from '../../api/types'
import { AssetEditPage } from './AssetEditPage'

const asset: Asset = {
  id: 'asset-1',
  asset_no: 'EQ-00121',
  name: 'ThinkPad X1',
  category: 'ノートPC',
  serial_number: 'SN-001',
  management_type: 'lending',
  lending_status: 'available',
  installation_status: null,
  lending_method: 'backoffice',
  default_location_text: null,
  qr_token: 'qr-token-1',
  current_loan_id: null,
  notes: '付属品: 充電器',
  created_at: '2026-08-01T00:00:00+09:00',
  updated_at: '2026-08-01T00:00:00+09:00',
}

function Decorator() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['assets', asset.id], asset)

  return (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/assets/${asset.id}/edit`]}>
        <Routes>
          <Route path="/assets/:id/edit" element={<AssetEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/Asset/AssetEditPage',
  component: AssetEditPage,
} satisfies Meta<typeof AssetEditPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator />,
}
