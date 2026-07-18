import type { Page } from '@playwright/test'
import { mondayOf } from '../../src/utils/weekDates'

/**
 * UserPicker(氏名/メールアドレスで検索するコンボボックス)で対象社員を選択する。
 * ラベルに紐づくトリガーボタンを開き、検索語を入力して候補をクリックする。
 */
export async function pickUser(
  page: Page,
  label: string,
  name: string,
  email: string,
  options: { timeout?: number; exact?: boolean } = {},
): Promise<void> {
  await page.getByLabel(label, { exact: options.exact }).click(options)
  await page.getByPlaceholder('氏名またはメールアドレスで検索').fill(name, options)
  await page.getByRole('option', { name: `${name}(${email})` }).click(options)
}

/**
 * 週次勤怠画面(`/attendance/week`)を開き、`targetDateStr`("YYYY-MM-DD")を含む週まで
 * 「前週」「次週」ボタンで移動する。週次画面には任意の週へ直接ジャンプする手段が無いため。
 */
export async function goToAttendanceWeekContaining(page: Page, targetDateStr: string): Promise<void> {
  const targetMonday = mondayOf(new Date(`${targetDateStr}T00:00:00`))
  const currentMonday = mondayOf(new Date())
  const weeksAhead = Math.round((targetMonday.getTime() - currentMonday.getTime()) / (7 * 24 * 60 * 60 * 1000))

  await page.goto('/attendance/week')
  const buttonName = weeksAhead >= 0 ? '次週' : '前週'
  for (let i = 0; i < Math.abs(weeksAhead); i += 1) {
    await page.getByRole('button', { name: buttonName }).click()
  }
}
