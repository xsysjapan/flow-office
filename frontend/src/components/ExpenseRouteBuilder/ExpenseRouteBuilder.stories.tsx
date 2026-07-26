import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import { ExpenseRouteBuilder } from './ExpenseRouteBuilder'
import type { ExpenseCategory } from '../../api/types'

const categories: ExpenseCategory[] = [
  {
    id: 1,
    code: 'transport',
    name: '交通費',
    description: null,
    evidence_type_default: 'fact_reference_available',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
  {
    id: 2,
    code: 'misc',
    name: 'その他',
    description: null,
    evidence_type_default: 'receipt_required',
    receipt_required_threshold: null,
    approval_skip_threshold: null,
    is_active: true,
  },
]

const meta = {
  title: 'Components/ExpenseRouteBuilder',
  component: ExpenseRouteBuilder,
  tags: ['autodocs'],
  parameters: {
    docs: {
      description: {
        component:
          'UC-X007: 1日の移動経路(自宅・会社・訪問先...)を訪問順の地点として入力し、区間ごとに交通手段・金額・経費区分を指定して明細に分解する。徒歩・私用の区間は「精算対象外」にすると生成対象から除外される。',
      },
    },
  },
  args: {
    categories,
    onGenerate: fn(),
  },
} satisfies Meta<typeof ExpenseRouteBuilder>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithDefaults: Story = {
  args: {
    defaultCategoryId: 1,
    defaultUsageDate: '2026-07-26',
  },
}
