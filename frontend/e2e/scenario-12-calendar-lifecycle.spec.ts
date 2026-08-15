import { expect, test } from "@playwright/test";
import { loginAs, SCENARIO_USERS } from "./support/auth";
import { pickDate, pickTime } from "./support/ui";

/**
 * docs/testing/scenario-tests.md シナリオ12(会社カレンダーのライフサイクル)。
 * UC-C009(本体・年度作成・複製)・UC-C012(祝日iCalendar同期・一覧永続化)・
 * UC-C013(会社カレンダー基準の一括操作)を1本の通しシナリオとして確認する。
 *
 * 祝日iCalendarソースの実際の外部URLへの同期には依存せず、祝日の反映は日別編集画面から
 * 手動で1件設定して確認する(祝日iCalendarソース自体の登録・一覧永続化・直前同期取消は
 * 別途 `HolidayCalendarSourcesPage.test.tsx` の単体テストでカバーする)。
 *
 * scenario-00(実行時年+2の単一固定値)・scenario-08(実在の近い年度)と衝突しない
 * 未来年度をランダムに選ぶ。DatePickerの年送りは1年ずつのボタン操作のため、あまり遠い
 * 未来(例: 西暦8000年台)を選ぶと対象期間(開始)/(終了)の選択だけで一回あたり数分かかり
 * 現実的なテスト時間を超えてしまう。衝突を避けつつ実用上十分な範囲として、実行時年+40〜
 * +139の100年間からランダムに選ぶ。
 */
test("会社カレンダー作成〜デフォルト設定〜祝日設定〜公開〜一括適用〜取消〜年度複製", async ({
  page,
}) => {
  test.setTimeout(180000);

  const fiscalYear = new Date().getFullYear() + 40 + Math.floor(Math.random() * 100);
  const calendarName = `E2Eテスト用カレンダー(ライフサイクル)${fiscalYear}`;
  const workStyleCode = `e2e_lifecycle_${fiscalYear}`;
  const workDate = `${fiscalYear}-04-01`;
  const holidayDate = `${fiscalYear}-05-04`;

  await loginAs(page, SCENARIO_USERS.admin);

  // 一覧画面(/admin/work-calendars)へ戻った際に、このカレンダーの行を再度探すのに使う
  // (行全体が1つのLinkになっているため、getByRole("link", { name: calendarName })は
  // 部分一致でこの行のLinkにもヒットする)。
  const calendarRow = page.locator("li", {
    has: page.getByRole("link", { name: calendarName }),
  });

  // --- UC-C009 手順1〜2: 会社カレンダー本体作成〜カレンダー年度作成 ---
  // WorkCalendarCreatePageは作成後に作成したカレンダー自身の詳細画面へ遷移する
  // (SKILL.md §2.5)ため、一覧画面へ戻って行を探し直す必要はない。
  await page.goto("/admin/work-calendars");
  await page.getByRole("link", { name: "新規作成" }).click();
  await page.getByLabel("カレンダー名").fill(calendarName);
  await page.getByRole("button", { name: "作成する" }).click();

  await expect(
    page.getByRole("heading", { name: calendarName, level: 1 }),
  ).toBeVisible();

  // --- デフォルトに設定する(詳細画面自身のアクション) ---
  await expect(page.getByText("非デフォルト")).toBeVisible();
  await page.getByRole("button", { name: "デフォルトに設定する" }).click();
  await expect(page.getByText("デフォルト", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "デフォルトに設定する" }),
  ).toHaveCount(0);

  await expect(
    page.getByRole("heading", { name: "カレンダー年度" }),
  ).toBeVisible();

  await page.getByRole("button", { name: "新規作成" }).click();
  await page.getByLabel("年度", { exact: true }).fill(String(fiscalYear));
  await pickDate(page, "開始日", `${fiscalYear}-04-01`, { exact: true });
  await pickDate(page, "終了日", `${fiscalYear + 1}-03-31`, { exact: true });
  await page.getByRole("button", { name: "年度を作成する" }).click();

  const yearRow = page.locator("li", {
    has: page.getByRole("link", { name: `${fiscalYear}年度` }),
  });
  await expect(yearRow).toBeVisible();
  await expect(yearRow.getByRole("status", { name: "未公開" })).toBeVisible();

  // --- UC-C010: 日別編集で祝日を1件手動設定(勤務日を1件・祝日を1件) ---
  await yearRow.getByRole("link", { name: `${fiscalYear}年度` }).click();
  await expect(
    page.getByRole("heading", { name: "カレンダー年度の日別編集" }),
  ).toBeVisible();

  // 勤務日を1件設定する(fiscalYearはランダムな年度のため、月4/1が必ず平日になるとは
  // 限らない。曜日ごとの休日設定の初期値がどうであれ、明示的にworkingへ設定し直す)。
  await page.getByRole("button", { name: workDate, exact: false }).click();
  await page.getByLabel(`${workDate}の勤務区分`).selectOption({ value: "working" });
  await page.keyboard.press("Escape");

  // 祝日を1件手動設定する。
  await page.getByRole("button", { name: `${holidayDate}`, exact: false }).click();
  await page.getByLabel(`${holidayDate}の勤務区分`).selectOption({ value: "company_holiday" });
  await page.getByLabel(`${holidayDate}の休日`).click();
  await page.getByLabel(`${holidayDate}の休日名`).fill("E2Eテスト祝日");
  await page.keyboard.press("Escape");

  await page.getByRole("button", { name: "保存する" }).click();
  await expect(
    page.getByRole("button", { name: "保存する" }),
  ).not.toBeDisabled();

  // --- UC-C009 手順3: 公開する(年度自身のページから) ---
  await page.getByRole("button", { name: "公開する" }).click();
  await expect(page.getByRole("status", { name: "公開済み" })).toBeVisible();

  // --- UC-C002: このカレンダーを使う勤務形態を作成する(一括操作の前提) ---
  await page.goto("/admin/work-styles");
  await page.getByRole("button", { name: "新規作成" }).click();
  await page.getByLabel("コード").fill(workStyleCode);
  await page.getByLabel("名称").fill("E2Eテスト用勤務形態(ライフサイクル)");
  await page.getByLabel("労働時間制").selectOption({ value: "fixed" });
  await page.getByLabel("所定労働時間(分/日)").fill("480");
  await page.getByLabel("所定労働時間(分/週)").fill("2400");
  await pickTime(page, "標準開始時刻", "09:00");
  await pickTime(page, "標準終了時刻", "18:00");
  await page.getByLabel("カレンダー").selectOption({ label: calendarName });
  await page.getByRole("button", { name: "登録する" }).click();
  await expect(page.getByText(workStyleCode)).toBeVisible();

  // --- UC-C013: 会社カレンダー基準の一括操作(calendar_apply)をプレビュー→確定適用 ---
  await page.goto("/admin/calendar-bulk-operations");
  await page
    .getByLabel("理由")
    .fill(`E2Eテスト: ${calendarName}の適用`);
  await pickDate(page, "対象期間(開始)", workDate, { exact: true });
  await pickDate(page, "対象期間(終了)", workDate, { exact: true });
  await page
    .getByLabel("勤務形態", { exact: true })
    .selectOption({ label: "E2Eテスト用勤務形態(ライフサイクル)" });

  // 対象社員のUserPickerには付随ラベルが無いため(CalendarBulkOperationsPage.test.tsx
  // 参照)、combobox roleの末尾要素(操作種別・競合方針・勤務形態のnative selectに続く
  // UserPickerのトリガー)を直接操作する。
  await page.getByRole("combobox").last().click();
  await page
    .getByPlaceholder("氏名またはメールアドレスで検索")
    .fill(SCENARIO_USERS.punchEmployee);
  await page
    .getByRole("option", { name: `${SCENARIO_USERS.punchEmployee}(kenta.takahashi@example.com)` })
    .click();

  await page.getByRole("button", { name: "プレビューする" }).click();
  await expect(page.getByText("実行可能")).toBeVisible();

  await page
    .getByRole("button", { name: "この内容で確定適用する" })
    .click();

  const historyRow = page.locator("li", {
    hasText: `E2Eテスト: ${calendarName}の適用`,
  });
  await expect(historyRow.getByText("適用済み")).toBeVisible();

  // --- UC-C042: 一括操作の履歴から取消す(ConfirmActionDialog: トリガー→確認の2段階)。 ---
  await historyRow.getByRole("button", { name: "取消す" }).click();
  await page.getByRole("button", { name: "取り消す" }).click();
  await expect(historyRow.getByText("取消済み")).toBeVisible();
  await expect(historyRow.getByRole("button", { name: "取消す" })).toHaveCount(0);

  // --- UC-C009 手順4: 年度を複製して翌年度を作成する(年度自身のページから) ---
  await page.goto("/admin/work-calendars");
  await calendarRow.getByRole("link", { name: calendarName }).click();
  await expect(
    page.getByRole("heading", { name: calendarName, level: 1 }),
  ).toBeVisible();
  await yearRow.getByRole("link", { name: `${fiscalYear}年度` }).click();
  await expect(
    page.getByRole("heading", { name: "カレンダー年度の日別編集" }),
  ).toBeVisible();
  const duplicateYearButton = page.getByRole("button", { name: "複製して翌年度を作成" });
  await duplicateYearButton.click();
  // ミューテーション完了を待たずに一覧へ遷移すると、複製がまだサーバ側で終わっておらず
  // 翌年度の行が見つからないことがあるため、isLoadingが解けるまで待つ。
  await expect(duplicateYearButton).not.toBeDisabled();

  await page.goto("/admin/work-calendars");
  await calendarRow.getByRole("link", { name: calendarName }).click();
  const nextYearRow = page.locator("li", {
    has: page.getByRole("link", { name: `${fiscalYear + 1}年度` }),
  });
  await expect(nextYearRow).toBeVisible();
});
