import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn, userEvent, within } from 'storybook/test'
import { ApiError } from '../../api/client'
import { Input } from '../ui/input'
import { BulkActionDialog, type BulkActionResult } from './BulkActionDialog'

async function execute() {
  // ダイアログはportalに描画されるのでdocument.bodyから探す
  await userEvent.click(await within(document.body).findByRole('button', { name: '3件の担当者を変更' }))
}

const meta = {
  title: 'Components/BulkActionDialog',
  component: BulkActionDialog,
  args: {
    open: true,
    onOpenChange: fn(),
    title: '担当者を変更しますか?',
    summary: '選択した3件(未割り当て1件・割り当て済み2件)',
    description: 'ステータスは変わりません。新しい担当者と前の担当者に通知されます。',
    confirmLabel: '3件の担当者を変更',
    onExecute: (): Promise<BulkActionResult> => Promise.resolve({ succeededIds: ['1', '2', '3'], failures: [] }),
    onSucceeded: fn(),
    successMessage: (count: number) => `${count}件のタスクの担当者を山田 太郎さんに変更しました`,
  },
} satisfies Meta<typeof BulkActionDialog>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithInput: Story = {
  args: { children: <Input aria-label="変更先" placeholder="変更先の担当者" /> },
}

export const ConfirmDisabled: Story = { args: { confirmDisabled: true } }

/** 実行すると一部失敗の内訳が出る(「閉じる」だけになる) */
export const PartialFailureOnExecute: Story = {
  args: {
    onExecute: () =>
      Promise.resolve({
        succeededIds: ['1', '2'],
        failures: [{ id: '3', label: '名刺申請(佐藤)', message: '完了・取消済みのタスクは担当者を変更できません。' }],
      }),
  },
  play: execute,
}

/** 実行すると例外でダイアログ内にエラーが出る */
export const ErrorOnExecute: Story = {
  args: {
    onExecute: () => Promise.reject(new ApiError(422, '完了にできないタスクが含まれています。')),
  },
  play: execute,
}
