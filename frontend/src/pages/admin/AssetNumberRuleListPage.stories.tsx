import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { AssetNumberRuleListPage } from './AssetNumberRuleListPage'

const rules: AssetNumberRule[] = [
  { category: 'ノートPC', prefix: 'NPC', digit_count: 5, next_number: 3, is_active: true, is_default: false },
  { category: 'モニター', prefix: 'MON', digit_count: 4, next_number: 12, is_active: false, is_default: false },
  { category: null, prefix: 'AST', digit_count: 5, next_number: 41, is_active: true, is_default: true },
]

function Decorator(props: { rules: AssetNumberRule[] }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['asset-number-rules'], props.rules)
  return (
    <QueryClientProvider client={queryClient}>
      <AssetNumberRuleListPage />
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Pages/Admin/AssetNumberRuleListPage',
  component: AssetNumberRuleListPage,
} satisfies Meta<typeof AssetNumberRuleListPage>

export default meta
type Story = StoryObj<typeof meta>

export const WithRules: Story = {
  render: () => <Decorator rules={rules} />,
}

export const NoRules: Story = {
  render: () => <Decorator rules={[]} />,
}
