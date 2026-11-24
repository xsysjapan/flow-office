import type { Meta, StoryObj } from '@storybook/react-vite'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { FormSection } from './FormSection'

const meta = {
  title: 'Components/FormSection',
  component: FormSection,
  tags: ['autodocs'],
} satisfies Meta<typeof FormSection>

export default meta
type Story = StoryObj<typeof meta>

const field = (
  <FormField label="名称" htmlFor="section-name">
    <Input id="section-name" />
  </FormField>
)

export const Default: Story = {
  args: { title: '基本設定', children: field },
}

export const WithDescription: Story = {
  args: { title: '基本設定', description: '年度ごとの設定です。', children: field },
}

export const Heading2: Story = {
  args: { title: '基本設定', headingLevel: 2, children: field },
}
