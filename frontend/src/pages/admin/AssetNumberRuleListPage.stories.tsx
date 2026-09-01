import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { AssetNumberRule } from '../../api/assetNumberRules'
import { AssetNumberRuleListPage } from './AssetNumberRuleListPage'

const rules: AssetNumberRule[] = [
  { category: 'ノートPC', prefix: 'NPC', digitCount: 5, nextNumber: 3, enabled: true, isDefault: false },
  { category: 'モニター', prefix: 'MON', digitCount: 4, nextNumber: 12, enabled: false, isDefault: false },
  { category: null, prefix: 'AST', digitCount: 5, nextNumber: 41, enabled: true, isDefault: true },
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
