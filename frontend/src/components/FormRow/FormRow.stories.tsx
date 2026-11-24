import type { Meta, StoryObj } from '@storybook/react-vite'
import { FormField } from '../FormField/FormField'
import { Input } from '../ui/input'
import { FormRow } from './FormRow'

const meta = {
  title: 'Components/FormRow',
  component: FormRow,
  tags: ['autodocs'],
} satisfies Meta<typeof FormRow>

export default meta
type Story = StoryObj<typeof meta>

export const Period: Story = {
  args: {
    children: (
      <>
        <FormField label="開始日" htmlFor="row-start">
          <Input id="row-start" type="date" />
        </FormField>
        <FormField label="終了日" htmlFor="row-end">
          <Input id="row-end" type="date" />
        </FormField>
      </>
    ),
  },
}

export const FourColumns: Story = {
  args: {
    columns: 4,
    children: ['月', '火', '水', '木'].map((d, i) => (
      <FormField key={d} label={d} htmlFor={`row-day-${i}`}>
        <Input id={`row-day-${i}`} />
      </FormField>
    )),
  },
}
