import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import { AssetRegisterPage } from './AssetRegisterPage'

function Decorator() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/assets/new']}>
        <AssetRegisterPage />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/Asset/AssetRegisterPage',
  component: AssetRegisterPage,
} satisfies Meta<typeof AssetRegisterPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => <Decorator />,
}
