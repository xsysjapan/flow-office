import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { AssetRegisterPage } from './AssetRegisterPage'

function Decorator(props: { rules?: AssetNumberRule[]; categories?: string[] }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['asset-number-rules'], props.rules ?? [])
  queryClient.setQueryData(['asset-number-rules', 'categories'], props.categories ?? [])
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

/** ルール無し(手入力)。 */
export const Default: Story = {
  render: () => <Decorator categories={['ノートPC', 'デスクトップPC', 'モニター']} />,
}

/** カテゴリ一致ルールで自動採番される状態。カテゴリ欄に「ノートPC」と入力すると
 *  管理番号欄が「保存時に自動採番されます」に切り替わる。 */
export const CategoryRuleAutoNumbering: Story = {
  render: () => (
    <Decorator
      rules={[{ category: 'ノートPC', prefix: 'NPC', digitCount: 5, nextNumber: 3, enabled: true, isDefault: false }]}
      categories={['ノートPC']}
    />
  ),
}

/** カテゴリ一致ルールが無く、デフォルトルールで自動採番される状態。 */
export const DefaultRuleAutoNumbering: Story = {
  render: () => (
    <Decorator rules={[{ category: null, prefix: 'AST', digitCount: 5, nextNumber: 10, enabled: true, isDefault: true }]} />
  ),
}
