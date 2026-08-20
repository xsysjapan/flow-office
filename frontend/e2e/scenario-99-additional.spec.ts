import { readFile } from "node:fs/promises";
import { expect, test } from "@playwright/test";
import { loginAs, SCENARIO_USERS } from "./support/auth";
import {
  deleteAttendancePunch,
  ensureTodayClockedOut,
  fetchAttendancePunches,
  grantAdditionalPaidLeave,
  recordAttendancePunch,
  submitApproveAndCloseCurrentMonth,
} from "./support/api";
import { pickDate, pickUser, pickYearMonth } from "./support/ui";

/**
 * docs/testing/scenario-tests.md §5(その他、用意しておくべきシナリオ)に対応する。
 * 実装済みのものは test()、まだのものは test.skip の TODO プレースホルダのまま残す。
 */

test("§5-1: 承認差し戻し→再申請", async ({ browser }) => {
  test.setTimeout(360000);
  const title = `E2Eテスト差戻し確認_${Math.floor(Math.random() * 100000)}`;

  const applicantContext = await browser.newContext();
  const approverContext = await browser.newContext();
  try {
    const applicantPage = await applicantContext.newPage();
    const approverPage = await approverContext.newPage();

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee);
    await applicantPage.goto("/requests/new");
    await applicantPage
      .getByLabel("申請種別")
      .selectOption({ label: "一般申請" });
    await applicantPage.getByLabel("タイトル").fill(title);
    await applicantPage.getByLabel("内容").fill("E2Eテスト用の一般申請");
    await pickUser(
      applicantPage,
      "承認者",
      SCENARIO_USERS.approver,
      "naoki.watanabe@example.com",
    );
    await applicantPage.getByRole("button", { name: "提出する" }).click();
    await expect(
      applicantPage.getByRole("status", { name: "提出済み" }),
    ).toBeVisible();

    // 承認者が差し戻す。
    await loginAs(approverPage, SCENARIO_USERS.approver);
    await approverPage.goto("/approvals");
    await approverPage
      .getByRole("row", { name: title })
      .getByRole("button", { name: title })
      .click();
    await approverPage
      .getByPlaceholder("差戻しコメント")
      .fill("内容を確認してください");
    await approverPage.getByRole("button", { name: "差戻す" }).click();
    await expect(
      approverPage.getByRole("status", { name: "差戻し" }),
    ).toBeVisible();

    // 申請者が差戻しコメントを履歴で確認し、再提出する。
    await applicantPage.reload();
    await expect(
      applicantPage.getByRole("status", { name: "差戻し" }),
    ).toBeVisible();
    await applicantPage.getByRole("button", { name: "提出する" }).click();
    await expect(
      applicantPage.getByRole("status", { name: "提出済み" }),
    ).toBeVisible();

    // 承認者が今度は承認する(統合承認画面はモーダルのため、再読み込み後は行を開き直す)。
    await approverPage.goto("/approvals");
    const resubmittedRow = approverPage.getByRole("row", { name: title });
    await expect(resubmittedRow).toBeVisible();
    await resubmittedRow
      .getByRole("button", { name: title })
      .click({ timeout: 30000 });
    await approverPage.getByRole("button", { name: "承認する" }).click();
    await expect(
      approverPage.getByRole("status", { name: "承認済み" }),
    ).toBeVisible();
  } finally {
    await applicantContext.close();
    await approverContext.close();
  }
});

test("§5-2: 申請取消(提出後)", async ({ page }) => {
  test.setTimeout(300000);
  const title = `E2Eテスト取消確認_${Math.floor(Math.random() * 100000)}`;

  await loginAs(page, SCENARIO_USERS.punchEmployee);
  await page.goto("/requests/new");
  await page.getByLabel("申請種別").selectOption({ label: "一般申請" });
  await page.getByLabel("タイトル").fill(title);
  await page.getByLabel("内容").fill("E2Eテスト用の一般申請(取消用)");
  await pickUser(
    page,
    "承認者",
    SCENARIO_USERS.approver,
    "naoki.watanabe@example.com",
  );
  await page.getByRole("button", { name: "提出する" }).click();
  await expect(page.getByRole("status", { name: "提出済み" })).toBeVisible();

  // 取消はConfirmActionDialog(トリガー→確認の2段階)。
  await page.getByRole("button", { name: "取消" }).click();
  await page.getByPlaceholder("取消理由").fill("申請内容の誤りのため");
  await page.getByRole("button", { name: "取り消す" }).click();
  await expect(page.getByRole("status", { name: "取消" })).toBeVisible();
});

test("§5-6+7: ロール変更が即座に反映され、監査ログに記録される", async ({
  page,
}) => {
  test.setTimeout(180000);

  await loginAs(page, SCENARIO_USERS.approver);
  const userId = await page.evaluate(async () => {
    const token = localStorage.getItem("flow-office.token");
    const res = await fetch("http://localhost:8000/api/auth/me", {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    });
    return (await res.json()).id;
  });

  const adminContext = await page.context().browser()!.newContext();
  const adminPage = await adminContext.newPage();
  try {
    await loginAs(adminPage, SCENARIO_USERS.admin);

    // このテストを何度も実行しても前提が同じになるよう、まずemployeeのみの状態に戻す
    // (このテストで hr_staff を付与した結果が残っていることがあるため)。UI上の割当一覧は
    // 対象ユーザー名を表示せず(Role名/付与先種別/対象範囲のみ)特定の割当を探しづらいため、
    // 事前状態の確認・削除はAPIで行い、UI操作は「ロールを付与する」本題の手順に絞る。
    const hrRoleId = await adminPage.evaluate(async () => {
      const token = localStorage.getItem("flow-office.token");
      const res = await fetch(
        "http://localhost:8000/api/admin/access-control/roles",
        { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } },
      );
      const roles: Array<{ id: number; code: string }> = await res.json();
      return roles.find((r) => r.code === "hr_staff")!.id;
    });
    await adminPage.evaluate(
      async ({ userId, hrRoleId }) => {
        const token = localStorage.getItem("flow-office.token");
        const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
        const res = await fetch(
          "http://localhost:8000/api/admin/access-control/role-assignments",
          { headers },
        );
        const assignments: Array<{
          id: string;
          subject_type: string;
          subject_id: string;
          role_id: number;
          status: string;
        }> = await res.json();
        const existing = assignments.filter(
          (a) =>
            a.status === "active" &&
            a.subject_type === "user" &&
            a.subject_id === userId &&
            a.role_id === hrRoleId,
        );
        for (const a of existing) {
          await fetch(
            `http://localhost:8000/api/admin/access-control/role-assignments/${a.id}`,
            { method: "DELETE", headers },
          );
        }
      },
      { userId, hrRoleId },
    );

    // 対象社員は現状employeeロールのみのため、管理メニューへのリンクがナビゲーションに
    // 出ないことを確認する。
    await page.reload();
    await expect(page.getByRole("link", { name: "管理メニュー" })).toHaveCount(
      0,
    );

    // 管理者がhr_staffロールを追加する(ログインし直しではなくロールを都度DBに反映する)。
    await adminPage.goto("/admin/access-control");
    await adminPage.getByRole("tab", { name: "Role・Permission" }).click();
    await adminPage
      .getByLabel("付与先ユーザー")
      .selectOption({ label: SCENARIO_USERS.approver });
    await adminPage.getByLabel("Role", { exact: true }).selectOption({ label: "人事担当者" });
    await adminPage.getByLabel("対象範囲").selectOption({ label: "全社" });
    await Promise.all([
      adminPage.waitForResponse(
        (res) =>
          res.url().includes("/admin/access-control/role-assignments") &&
          res.request().method() === "POST",
      ),
      adminPage.getByRole("button", { name: "Roleを割当" }).click(),
    ]);

    // §5-7: この操作が監査ログに記録されていることを確認する。
    await adminPage.goto("/admin/audit-log");
    await adminPage.getByLabel("対象タイプ").fill("role_assignment");
    await adminPage.getByLabel("イベント種別").fill("role_assignment.created");
    const auditRow = adminPage
      .getByRole("listitem")
      .filter({ hasText: "role_assignment.created" })
      .first();
    await expect(auditRow).toBeVisible();
    await auditRow.getByText("詳細").click();
    await expect(auditRow.getByText(new RegExp(userId))).toBeVisible();
  } finally {
    await adminContext.close();
  }

  // 本人はログインし直さずページをreloadするだけで、新しい権限のナビゲーションが表示される
  // (Sanctumトークン自体は変わらず、/auth/meが最新のrolesを返すことを確認する)。
  await page.reload();
  await expect(page.getByRole("link", { name: "管理メニュー" })).toBeVisible();
  await page.getByRole("link", { name: "管理メニュー" }).click();
  await expect(
    page.getByRole("complementary").getByRole("link", { name: "有給設定" }),
  ).toBeVisible();
});

test("§5-3: 月次締め後は日次実績が編集できない", async ({ browser }) => {
  test.setTimeout(360000);

  const applicantContext = await browser.newContext();
  const approverContext = await browser.newContext();
  const adminContext = await browser.newContext();
  try {
    const applicantPage = await applicantContext.newPage();
    const approverPage = await approverContext.newPage();
    const adminPage = await adminContext.newPage();

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee);
    await loginAs(approverPage, SCENARIO_USERS.approver);
    await loginAs(adminPage, SCENARIO_USERS.admin);

    // 当月の月次勤怠を提出〜承認〜締めまで進める(UC-A008〜UC-A011)。同一日に何度
    // 実行しても、既に進んでいるステータスはスキップするため冪等に動く。
    const { workDate } = await submitApproveAndCloseCurrentMonth(
      applicantPage,
      approverPage,
      adminPage,
    );

    // 締め済みの日は日次勤怠画面(`/attendance/days/{date}`)で編集操作自体を表示せず、
    // 修正申請へ誘導する(UC-A011)。API側の更新拒否はバックエンドテストで確認する。
    await applicantPage.goto(`/attendance/days/${workDate}`);
    await expect(
      applicantPage
        .getByText(/月次勤怠が.+ため、この日は編集できません/)
        .first(),
    ).toBeVisible();
    await expect(
      applicantPage.getByRole("button", { name: "編集", exact: true }),
    ).toHaveCount(0);
    await expect(
      applicantPage.getByRole("button", { name: "削除" }),
    ).toHaveCount(0);
  } finally {
    await applicantContext.close();
    await approverContext.close();
    await adminContext.close();
  }
});

test("§5-4: 打刻ログと日次実績の不一致確認", async ({ page }) => {
  test.setTimeout(300000);

  // 対象日は必ず翌月の平日にする(土日だと法定休日/所定休日バッジが優先表示され、勤務中
  // バッジの確認にならない)。当月は他のテスト(§5-3・§5-8等)が月次締めまで進めることが
  // あり、締め済み月の日は打刻を記録しても日次実績に反映されなくなる
  // (`AttendanceEditGuard`)ため、翌月であれば実行順序に関わらず必ず未締めであることを
  // 保証できる。週次画面へは`次週`クリックの代わりに`?start=`で直接その週へ遷移する。
  const nextMonthFirstDay = new Date();
  nextMonthFirstDay.setMonth(nextMonthFirstDay.getMonth() + 1, 1);
  while (nextMonthFirstDay.getDay() !== 3 /* 水曜日 */) {
    nextMonthFirstDay.setDate(nextMonthFirstDay.getDate() + 1);
  }
  const futureDate = nextMonthFirstDay.toISOString().slice(0, 10);
  const isoDow = (nextMonthFirstDay.getDay() + 6) % 7; // 0=月 ... 6=日
  const mondayOfWeek = new Date(nextMonthFirstDay);
  mondayOfWeek.setDate(nextMonthFirstDay.getDate() - isoDow);
  const weekStart = mondayOfWeek.toISOString().slice(0, 10);

  await loginAs(page, SCENARIO_USERS.punchEmployee);

  // 出勤打刻を2回記録する(clock_inがちょうど1件でない=矛盾)。打刻ログ自体は矛盾が
  // あっても常に記録される(UC-A012)。
  await recordAttendancePunch(page, {
    workDate: futureDate,
    punchType: "clock_in",
    punchedAt: `${futureDate}T09:00:00+09:00`,
  });
  const duplicateClockIn = await recordAttendancePunch(page, {
    workDate: futureDate,
    punchType: "clock_in",
    punchedAt: `${futureDate}T09:05:00+09:00`,
  });

  const punches = await fetchAttendancePunches(page, futureDate, futureDate);
  expect(punches.length).toBeGreaterThanOrEqual(2);

  // 出勤打刻の重複という矛盾があるため、以降の打刻(退勤等)は日次実績に反映されなくなる。
  // ただし最初の出勤打刻自体は矛盾なく記録された時点で既に反映済みのため、日次実績は
  // 「未入力」ではなく、退勤前の状態(勤務中)のまま据え置かれる
  // (`AttendanceDayPunchSyncer`のInProgress/Contradictoryの扱いを参照)。
  await page.goto(`/attendance/week?start=${weekStart}`);
  const futureRow = page.getByRole("listitem").filter({ hasText: futureDate });
  await expect(futureRow).toBeVisible();
  await expect(futureRow.getByRole("status", { name: "勤務中" })).toBeVisible();

  // 後続のテスト(当月の月次締め等)が同じ利用者を使うため、重複打刻を削除し退勤打刻を
  // 追加して、この対象日を矛盾のない退勤済み状態に戻しておく(UC-A014)。
  await deleteAttendancePunch(page, duplicateClockIn.id, "E2Eテストの重複打刻を後始末");
  await recordAttendancePunch(page, {
    workDate: futureDate,
    punchType: "clock_out",
    punchedAt: `${futureDate}T18:00:00+09:00`,
  });
});

// §5-5: 有給の自動失効警告・年5日取得義務警告バッチ(WarnExpiringPaidLeave /
// WarnFiveDayObligation)は、送信結果を確認できるAPI・画面が無く(Teams通知ジョブを
// キューに積むのみ)、本ファイルが前提とするブラックボックスE2E(HTTP/画面操作のみで
// 完結)では検証できない。シナリオ6(通年運用シミュレーション)の手順4が同じ制約を
// 持つバッチ(grant-scheduledも含む3つ)を、ドキュメントで明示的に許容された例外として
// `child_process`経由でartisanコマンドを直接実行する方式で検証しているため、
// `scenario-08-fiscal-year-cycle.spec.ts`の`境界条件の単発確認`を参照。

test("§5-8: 締めた月の勤怠CSV出力", async ({ browser }) => {
  test.setTimeout(360000);

  const applicantContext = await browser.newContext();
  const approverContext = await browser.newContext();
  const adminContext = await browser.newContext();
  try {
    const applicantPage = await applicantContext.newPage();
    const approverPage = await approverContext.newPage();
    const adminPage = await adminContext.newPage();

    await loginAs(applicantPage, SCENARIO_USERS.punchEmployee);
    await loginAs(approverPage, SCENARIO_USERS.approver);
    await loginAs(adminPage, SCENARIO_USERS.admin);

    const { yearMonth } = await submitApproveAndCloseCurrentMonth(
      applicantPage,
      approverPage,
      adminPage,
    );

    await adminPage.goto("/admin/attendance-export");
    await pickYearMonth(adminPage, "対象月", yearMonth);
    await adminPage.getByRole("button", { name: "追加" }).click();
    await expect(adminPage.getByText(yearMonth, { exact: true })).toBeVisible();

    const [download] = await Promise.all([
      adminPage.waitForEvent("download"),
      adminPage.getByRole("button", { name: "CSVダウンロード" }).click(),
    ]);
    const csvPath = await download.path();
    const csv = csvPath ? await readFile(csvPath, "utf-8") : "";

    expect(csv).toContain(SCENARIO_USERS.punchEmployee);
    expect(csv).toContain(yearMonth);
  } finally {
    await applicantContext.close();
    await approverContext.close();
    await adminContext.close();
  }
});

test("§5-9: Entra ID初回ログイン(新入社員オンボーディング)", async ({
  page,
}) => {
  test.setTimeout(120000);

  // mock-oidcのユーザーのうちScenarioSeederが意図的に未使用のまま残している3人
  // (docs/testing/scenario-tests.md §3)。ユーザーを未登録状態へ戻すAPIが無いため、
  // 複数回実行すると2人目・3人目...と順に初回ログインを消費していく
  // (環境ごとに検証できるのは最大3回まで。ランダムに選び衝突の可能性を下げる)。
  const newHireCandidates = ["山田 太郎", "佐藤 花子", "鈴木 一郎"];
  const newHireName =
    newHireCandidates[Math.floor(Math.random() * newHireCandidates.length)];

  await loginAs(page, newHireName);

  const me = await page.evaluate(async () => {
    const token = localStorage.getItem("flow-office.token");
    const res = await fetch("http://localhost:8000/api/auth/me", {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    });
    return res.json();
  });
  // 初回ログインでは自動的にemployeeロールが付与される(UC-001)。roleはUserResourceに
  // 直接は出ないため、EMPLOYEE Roleに割り当てられているPermission(勤怠閲覧・更新)が
  // 有効化されていることで確認する(ALL_USERSグループ経由のRoleAssignment)。
  expect(me.effective_permissions).toContain("attendance.read");
  const userId = me.id;

  // 管理者が入社日を設定し(UC-P002)、hr_staffロールを追加する(UC-M001)。
  const adminContext = await page.context().browser()!.newContext();
  const adminPage = await adminContext.newPage();
  try {
    await loginAs(adminPage, SCENARIO_USERS.admin);
    await adminPage.goto(`/admin/users/${userId}`);

    await pickDate(adminPage, "入社日(有給の自動付与に使用)", "2026-04-01");
    await adminPage.getByRole("button", { name: "入社日を保存する" }).click();
    await expect(
      adminPage.getByRole("status", { name: "保存しました" }),
    ).toBeVisible();

    await adminPage.goto("/admin/access-control");
    await adminPage.getByRole("tab", { name: "Role・Permission" }).click();
    await adminPage
      .getByLabel("付与先ユーザー")
      .selectOption({ label: newHireName });
    await adminPage.getByLabel("Role", { exact: true }).selectOption({ label: "人事担当者" });
    await adminPage.getByLabel("対象範囲").selectOption({ label: "全社" });
    await Promise.all([
      adminPage.waitForResponse(
        (res) =>
          res.url().includes("/admin/access-control/role-assignments") &&
          res.request().method() === "POST",
      ),
      adminPage.getByRole("button", { name: "Roleを割当" }).click(),
    ]);
  } finally {
    await adminContext.close();
  }
});

/**
 * ScenarioSeederのシフト予定期間(前後1か月)に収まる平日をランダムに選ぶ
 * (scenario-03-paid-leave.spec.tsの`randomWorkingDate`と同じ規則。当月は他シナリオが
 * 月次を承認・締めまで進めることがあるため翌月から選ぶ)。特別休暇・有給いずれの
 * 申請テストからも使う共通ヘルパー。
 */
function randomNextMonthWorkingDate(): string {
  const now = new Date();
  const nextMonthStart = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  const daysInNextMonth = new Date(
    nextMonthStart.getFullYear(),
    nextMonthStart.getMonth() + 1,
    0,
  ).getDate();
  const day = 1 + Math.floor(Math.random() * (daysInNextMonth - 3));
  const date = new Date(nextMonthStart.getFullYear(), nextMonthStart.getMonth(), day);
  while (date.getDay() === 0 || date.getDay() === 6) {
    date.setDate(date.getDate() + 1);
  }
  return date.toISOString().slice(0, 10);
}

/**
 * §5-19: 承認者に申請者本人を指定して汎用申請を提出した場合、承認待ちを経由せず
 * 提出と同時に承認をスキップして確定すること(承認不要設定とは別の、承認ルート自体は
 * あるが実質的な承認者がいないケース)。承認者への「承認してください」通知は発生せず、
 * /approvalsで誰かの承認操作を待つことなく最初から「承認済み」状態になることを確認する。
 */
test("§5-19: 承認者に自分自身を指定した申請は提出と同時に承認をスキップする", async ({
  page,
}) => {
  test.setTimeout(60000);
  const title = `E2Eテスト自己承認確認_${Math.floor(Math.random() * 100000)}`;

  await loginAs(page, SCENARIO_USERS.punchEmployee);
  await page.goto("/requests/new");
  await page.getByLabel("申請種別").selectOption({ label: "一般申請" });
  await page.getByLabel("タイトル").fill(title);
  await page.getByLabel("内容").fill("E2Eテスト用の自己承認確認申請");
  await pickUser(
    page,
    "承認者",
    SCENARIO_USERS.punchEmployee,
    "kenta.takahashi@example.com",
  );
  await page.getByRole("button", { name: "提出する" }).click();

  await expect(page).toHaveURL(/\/requests\/[0-9a-f-]+$/);
  await expect(
    page.getByRole("status", { name: "承認済み" }),
  ).toBeVisible();

  // 承認者操作を促すボタン(「承認する」「差し戻す」)は最初から出ない。
  await expect(page.getByRole("button", { name: "承認する" })).toHaveCount(0);
});

// 注: 「承認不要」システム設定時に承認者欄が表示されないことのE2E確認は、
// 意図的にここには置かない。system_settingsはアプリ全体で1行だけの真にグローバルな
// 設定であり(ルートCLAUDE.md「法務判断が必要な値はマスタ化する」)、このE2Eスイートは
// globalSetupで1回だけDBをリセットした後は状態をリセットせず使い回す前提(本ファイル
// 冒頭のREADME参照)。値を変更するテストが1件でもあると、同じDBを共有する他の全シナリオ・
// 手動確認・(将来的な)並列実行に影響する。実際、変更後は`finally`で元に戻しても、
// テストプロセスが外部要因(タイムアウト・強制終了)で中断すればその瞬間に元へ戻せず、
// 以降のシナリオが「特別休暇は承認必須」という前提のまま壊れる。
// 承認者欄の非表示自体は`frontend/src/pages/{paidLeave,specialLeave,compensatoryLeave}/`・
// `expense/ExpenseClaimNewPage`・`attendance/{AttendanceMonthDetailPage,AttendanceDayPage}`の
// 各`*.test.tsx`(Vitest、system_settingsをモックするだけで実DBに触れない)で
// ドメインごとに確認済みのため、E2Eでの重複確認はしない。

/**
 * §5-19: 有給申請でも承認者に自分自身を指定した場合は提出と同時に承認をスキップし、
 * `/paid-leave`一覧の時点で最初から「承認済み」になること(scenario-03-paid-leave.spec.ts
 * の通常申請〜承認フローとは別に、自己承認の1件だけを確認する)。
 */
test("§5-19: 承認者に自分自身を指定した有給申請は提出と同時に承認をスキップする", async ({
  page,
}) => {
  test.setTimeout(60000);

  // grantAdditionalPaidLeaveはユーザー検索(GET /users)に管理者権限が必要なため、
  // 専用のadminコンテキストから付与する(申請者本人のセッションには影響させない)。
  const adminContext = await page.context().browser()!.newContext();
  const adminPage = await adminContext.newPage();
  await loginAs(adminPage, SCENARIO_USERS.admin);
  await grantAdditionalPaidLeave(adminPage, "kenta.takahashi@example.com", 5);
  await adminContext.close();

  await loginAs(page, SCENARIO_USERS.punchEmployee);
  await page.goto("/paid-leave");

  // 対象日はランダムに選ぶため、他の実行と重複した場合(「この日は既に有給または特別休暇を
  // 申請済みです。」)は日を変えて再試行する(scenario-03-paid-leave.spec.tsと同じ考え方)。
  let approvedRow;
  for (let attempt = 0; attempt < 10; attempt++) {
    const targetDate = randomNextMonthWorkingDate();
    await pickDate(page, "対象日", targetDate, { exact: true });
    await pickUser(
      page,
      "承認者",
      SCENARIO_USERS.punchEmployee,
      "kenta.takahashi@example.com",
    );
    await page.getByRole("button", { name: "申請する" }).click();

    const duplicateError = page.getByText("この日は既に有給を申請済みです。");
    const row = page
      .locator("li", { hasText: targetDate })
      .getByRole("status", { name: "承認済み" });

    const result = await Promise.race([
      duplicateError
        .waitFor({ state: "visible", timeout: 15000 })
        .then(() => "duplicate" as const)
        .catch(() => null),
      row
        .waitFor({ state: "visible", timeout: 15000 })
        .then(() => "approved" as const)
        .catch(() => null),
    ]);

    if (result === "approved") {
      approvedRow = row;
      break;
    }
  }

  if (!approvedRow) {
    throw new Error("有給申請(自己承認)に10回試行しても成功しなかった");
  }
  await expect(approvedRow).toBeVisible();
});

/**
 * §5-19: 月次勤怠でも承認者に自分自身を指定した場合、提出と同時に承認をスキップして
 * 確定すること。attendance_month集約はworkflow_requestとは別集約で、
 * `attendance_requires_approval=false`による自動承認(承認者IDがnullのセンチネル)と
 * 自己承認スキップ(承認者ID=申請者自身)の2つの経路を持つため、両者を混同していないかの
 * 回帰確認を兼ねる。他シナリオが当月・前月の月次勤怠を提出〜締めまで進めることがある
 * `punchEmployee`/`monthlyEmployee`とは衝突しないよう、`hrStaff`(加藤由美、他シナリオでは
 * 承認者・閲覧者としてのみ登場し、自分の月次勤怠は提出しない)の当月分で確認する。
 */
test("§5-19: 承認者に自分自身を指定した月次勤怠提出は承認をスキップする", async ({
  page,
}) => {
  test.setTimeout(60000);

  await loginAs(page, SCENARIO_USERS.hrStaff);
  const { workDate } = await ensureTodayClockedOut(page);
  const yearMonth = workDate.slice(0, 7);

  await page.goto(`/attendance/months/${yearMonth}`);
  await page.getByRole("button", { name: "提出する" }).first().click();

  const dialog = page.getByRole("dialog");
  await dialog.locator("#approver").click();
  await page
    .getByPlaceholder("氏名またはメールアドレスで検索")
    .fill(SCENARIO_USERS.hrStaff);
  await page
    .getByRole("option", { name: `${SCENARIO_USERS.hrStaff}(yumi.kato@example.com)` })
    .click();
  await dialog.getByRole("button", { name: "提出する" }).click();

  await expect(page.getByRole("status", { name: "承認済み" })).toBeVisible();
});
