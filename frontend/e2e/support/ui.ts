import type { Page } from '@playwright/test'

/**
 * UserPicker(氏名/メールアドレスで検索するコンボボックス)で対象社員を選択する。
 * ラベルに紐づくトリガーボタンを開き、検索語を入力して候補をクリックする。
 */
export async function pickUser(
  page: Page,
  label: string,
  name: string,
  email: string,
  options: { timeout?: number } = {},
): Promise<void> {
  await page.getByLabel(label).click(options)
  await page.getByPlaceholder('氏名またはメールアドレスで検索').fill(name, options)
  await page.getByRole('option', { name: `${name}(${email})` }).click(options)
}
