import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'
import type { ExpenseRouteTemplate } from '../../api/types'
import { ExpenseTemplateBulkGenerator } from './ExpenseTemplateBulkGenerator'

const templates: ExpenseRouteTemplate[] = [
  {
    id: 1,
    scope: 'personal',
    employee_id: 'emp-1',
    name: '自宅⇔会社',
    origin: '自宅',
    destination: '会社',
    transport_type: '電車',
    amount: 500,
    category_id: 1,
    is_active: true,
  },
  {
    id: 2,
    scope: 'company',
    employee_id: null,
    name: '会社⇔本社',
    origin: '会社',
    destination: '本社',
    transport_type: 'バス',
    amount: 300,
    category_id: 1,
    is_active: true,
  },
  {
    id: 3,
    scope: 'personal',
    employee_id: 'emp-1',
    name: '廃止済みルート',
    origin: '自宅',
    destination: '旧オフィス',
    transport_type: '電車',
    amount: 400,
    category_id: 1,
    is_active: false,
  },
]

const meta = {
  title: 'Components/ExpenseTemplateBulkGenerator',
  component: ExpenseTemplateBulkGenerator,
} satisfies Meta<typeof ExpenseTemplateBulkGenerator>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    templates,
    onGenerate: fn(),
  },
}

export const NoTemplates: Story = {
  args: {
    templates: [],
    onGenerate: fn(),
  },
}
