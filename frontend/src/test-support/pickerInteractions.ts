import { screen, within } from '@testing-library/react'
import type { UserEvent } from '@testing-library/user-event'

/**
 * Vitestテストから、DatePicker/TimePicker/DateTimePickerを操作するための共通ヘルパー。
 * これらのコンポーネントはnative`<input type="date"/"time">`と違い、トリガーボタンを
 * クリックしてカレンダー/リストを開き、そこから選ぶ形になるため、テストの操作方法を
 * ここに集約する。
 */

function ordinalSuffix(day: number): string {
  if (day % 10 === 1 && day !== 11) return 'st'
  if (day % 10 === 2 && day !== 12) return 'nd'
  if (day % 10 === 3 && day !== 13) return 'rd'
  return 'th'
}

/** react-day-pickerの日付ボタンの既定aria-label(date-fnsの'PPPP'相当)を組み立てる。 */
function dayButtonLabel(date: Date): string {
  const weekday = date.toLocaleDateString('en-US', { weekday: 'long' })
  const month = date.toLocaleDateString('en-US', { month: 'long' })
  const day = date.getDate()
  return `${weekday}, ${month} ${day}${ordinalSuffix(day)}, ${date.getFullYear()}`
}

async function navigateToMonth(user: UserEvent, targetYear: number, targetMonthIndex: number): Promise<void> {
  const grid = screen.getByRole('grid')
  const label = grid.getAttribute('aria-label')
  if (!label) throw new Error('カレンダーのaria-labelから年月を読み取れませんでした。')

  const current = new Date(`1 ${label}`)
  const diff = (targetYear - current.getFullYear()) * 12 + (targetMonthIndex - current.getMonth())
  if (diff === 0) return

  const button = screen.getByRole('button', { name: diff < 0 ? 'Go to the Previous Month' : 'Go to the Next Month' })
  for (let i = 0; i < Math.abs(diff); i++) {
    // eslint-disable-next-line no-await-in-loop
    await user.click(button)
  }
}

/** `DatePicker`のトリガーボタン(現在の表示名)をクリックし、指定した"YYYY-MM-DD"を選ぶ。 */
export async function pickDate(user: UserEvent, triggerName: string, isoDate: string): Promise<void> {
  await user.click(screen.getByRole('button', { name: triggerName }))
  const target = new Date(`${isoDate}T00:00:00`)
  await navigateToMonth(user, target.getFullYear(), target.getMonth())
  await user.click(await screen.findByRole('button', { name: dayButtonLabel(target) }))
}

/** `TimePicker`のトリガーボタン(現在の表示名)をクリックし、指定した"HH:mm"を選ぶ。 */
export async function pickTime(user: UserEvent, triggerName: string, hhmm: string): Promise<void> {
  await user.click(screen.getByRole('button', { name: triggerName }))
  const [hour, minute] = hhmm.split(':')
  const hourList = await screen.findByRole('listbox', { name: '時' })
  await user.click(within(hourList).getByRole('option', { name: hour }))
  const minuteList = screen.getByRole('listbox', { name: '分' })
  await user.click(within(minuteList).getByRole('option', { name: minute }))
}

/**
 * `DateTimePicker`(日付・時刻それぞれ独立したトリガーボタンを持つ)を操作し、
 * 指定した"YYYY-MM-DDTHH:mm"を選ぶ。
 *
 * 注意: `DateTimePicker`は日付を選ぶと同時に時刻側が未入力なら"00:00"で補うため、
 * 値が空の状態から呼ぶ場合、`timeTriggerName`には最初のプレースホルダーではなく
 * `"00:00"`を渡す(日付選択直後の時刻トリガーの表示名になるため)。
 */
export async function pickDateTime(
  user: UserEvent,
  dateTriggerName: string,
  timeTriggerName: string,
  isoDateTime: string,
): Promise<void> {
  const [datePart, timePart] = isoDateTime.split('T')
  await pickDate(user, dateTriggerName, datePart)
  await pickTime(user, timeTriggerName, timePart)
}
