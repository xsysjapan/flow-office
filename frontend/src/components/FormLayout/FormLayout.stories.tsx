import type { Meta, StoryObj } from '@storybook/react-vite'
import { Button } from '../Button/Button'
import { ErrorMessage } from '../ErrorMessage/ErrorMessage'
import { FormActions } from '../FormActions/FormActions'
import { FormField } from '../FormField/FormField'
import { FormRow } from '../FormRow/FormRow'
import { FormSection } from '../FormSection/FormSection'
import { Input } from '../ui/input'
import { FormLayout } from './FormLayout'

const meta = {
  title: 'Components/FormLayout',
  component: FormLayout,
  tags: ['autodocs'],
} satisfies Meta<typeof FormLayout>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    children: (
      <FormField label="名称" htmlFor="layout-name">
        <Input id="layout-name" />
      </FormField>
    ),
  },
}

/** 標準の組み合わせ(セクション・期間・説明・補足・エラー・ボタン行)。 */
export const Composition: Story = {
  args: {
    children: (
      <>
        <FormSection title="基本設定" description="年度ごとの設定です。">
          <FormField label="名称" htmlFor="comp-name" required description="一覧に表示される名前です。">
            <Input id="comp-name" />
          </FormField>
          <FormRow>
            <FormField label="開始日" htmlFor="comp-start">
              <Input id="comp-start" type="date" />
            </FormField>
            <FormField label="終了日" htmlFor="comp-end">
              <Input id="comp-end" type="date" />
            </FormField>
          </FormRow>
        </FormSection>
        <p className="mb-2 text-xs text-muted-foreground">保存すると、次の年度から反映されます。</p>
        <div className="mb-2">
          <ErrorMessage error={new Error('保存に失敗しました。')} />
        </div>
        <FormActions>
          <Button variant="secondary">キャンセル</Button>
          <Button variant="primary">保存</Button>
        </FormActions>
      </>
    ),
  },
}
