import type { Locator, Page } from "@playwright/test";
import { formatDate, mondayOf } from "../../src/utils/weekDates";

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
  await page.getByLabel(label, { exact: options.exact }).click(options);
  await page
    .getByPlaceholder("氏名またはメールアドレスで検索")
    .fill(name, options);
  await page.getByRole("option", { name: `${name}(${email})` }).click(options);
}

/** DatePickerで任意の日付を選択する。 */
export async function pickDate(
  page: Page,
  label: string,
  date: string,
  options: { exact?: boolean; root?: Page | Locator } = {},
): Promise<void> {
  const [year, month, day] = date.split("-").map(Number);
  const root = options.root ?? page;
  await root.getByLabel(label, { exact: options.exact }).click();

  const dialog = page.getByRole("dialog").last();
  const caption = dialog
    .locator("button")
    .filter({ hasText: /^\d{4}年\d{1,2}月$/ });
  await caption.click();
  const visibleYear = Number(
    (await dialog.getByText(/^\d{4}年$/).textContent())?.replace("年", ""),
  );
  const direction = year >= visibleYear ? "翌年へ" : "前年へ";
  for (
    let current = visibleYear;
    current !== year;
    current += year >= visibleYear ? 1 : -1
  ) {
    await dialog.getByRole("button", { name: direction }).click();
  }
  await dialog.getByRole("option", { name: `${year}年${month}月` }).click();
  await dialog
    .getByRole("button", { name: new RegExp(`^${year}年${month}月${day}日`) })
    .click();
}

/** YearMonthPickerで任意の年月を選択する。 */
export async function pickYearMonth(
  page: Page,
  label: string,
  yearMonth: string,
): Promise<void> {
  const [year, month] = yearMonth.split("-").map(Number);
  await page.getByLabel(label).click();
  const dialog = page.getByRole("dialog").last();
  const yearLabel = dialog.getByText(/^\d{4}年$/);
  let visibleYear = Number((await yearLabel.textContent())?.replace("年", ""));
  const direction = year >= visibleYear ? "翌年へ" : "前年へ";
  while (visibleYear !== year) {
    await dialog.getByRole("button", { name: direction }).click();
    visibleYear += year >= visibleYear ? 1 : -1;
  }
  await dialog.getByRole("option", { name: `${year}年${month}月` }).click();
}

/** TimePickerで任意の時刻を選択する。 */
export async function pickTime(
  page: Page,
  label: string,
  time: string,
  options: { exact?: boolean; root?: Page | Locator } = {},
): Promise<void> {
  const [hour, minute] = time.split(":");
  const root = options.root ?? page;
  await root.getByLabel(label, { exact: options.exact }).click();
  const dialog = page.getByRole("dialog").last();
  await dialog
    .getByRole("listbox", { name: "時" })
    .getByRole("option", { name: hour, exact: true })
    .click();
  await dialog
    .getByRole("listbox", { name: "分" })
    .getByRole("option", { name: minute, exact: true })
    .click();
}

/**
 * 週次勤怠画面(`/attendance/week`)を、`targetDateStr`("YYYY-MM-DD")を含む週で開く。
 * 画面が対応する`?start=`へ週初日を渡し、不要な週送りとAPI再取得を発生させない。
 */
export async function goToAttendanceWeekContaining(
  page: Page,
  targetDateStr: string,
): Promise<void> {
  const targetMonday = mondayOf(new Date(`${targetDateStr}T00:00:00`));
  await page.goto(`/attendance/week?start=${formatDate(targetMonday)}`);
}
