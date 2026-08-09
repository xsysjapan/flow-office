import { expect, type Page, test } from "@playwright/test";
import { apiFetch, fetchUserIdByEmail } from "./support/api";
import { loginAs, SCENARIO_USERS } from "./support/auth";

const groupTypeCode = "E2E_TEAM";
const groupTypeName = "E2Eチーム種別";
const groupCode = "E2E_TEAM_ALPHA";
const groupName = "E2EチームAlpha";
const roleCode = "e2e_system_settings_reader";
const roleName = "E2Eシステム設定閲覧";

function card(page: Page, title: string) {
  return page
    .getByRole("heading", { name: title, exact: true })
    .locator(
      'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rounded-lg ")][1]',
    );
}

async function effectiveAccess(
  page: Page,
): Promise<{ features: string[]; permissions: string[] }> {
  return page.evaluate(async () => {
    const token = localStorage.getItem("flow-office.token");
    const response = await fetch("http://localhost:8000/api/access/me", {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    });
    if (!response.ok)
      throw new Error(`effective access request failed: ${response.status}`);
    return response.json();
  });
}

async function ensureAccessScenarioGroup(page: Page): Promise<string> {
  const groups = await apiFetch<Array<{ id: string; code: string; memberships: Array<{ user_id: string }> }>>(
    page,
    "/admin/user-management/groups",
  );
  const existing = groups.find((group) => group.code === groupCode);
  const userId = await fetchUserIdByEmail(page, "mai.ito@example.com");
  if (existing) {
    if (!existing.memberships.some((membership) => membership.user_id === userId)) {
      await apiFetch(page, "/admin/user-management/memberships", {
        method: "POST",
        body: { user_id: userId, group_id: existing.id, membership_kind: "member", is_primary: false },
      });
    }
    return existing.id;
  }

  await apiFetch(page, "/admin/user-management/group-types", {
    method: "POST",
    body: {
      code: groupTypeCode,
      name: groupTypeName,
      membership_limit_type: "unlimited",
      primary_membership_required: false,
    },
  });
  const types = await apiFetch<Array<{ id: number; code: string }>>(
    page,
    "/admin/user-management/group-types",
  );
  const created = await apiFetch<{ id: string }>(page, "/admin/user-management/groups", {
    method: "POST",
    body: {
      group_type_id: types.find((type) => type.code === groupTypeCode)!.id,
      name: groupName,
      code: groupCode,
    },
  });
  await apiFetch(page, "/admin/user-management/memberships", {
    method: "POST",
    body: { user_id: userId, group_id: created.id, membership_kind: "member", is_primary: false },
  });
  return created.id;
}

test("ユーザー管理を中心にGroupType・Group・所属・外部ID・所属変更を管理できる", async ({
  page,
}) => {
  test.setTimeout(90000);
  await loginAs(page, SCENARIO_USERS.admin);
  await page.goto("/admin/access-control");
  await expect(
    page.getByRole("heading", { name: "ユーザー・グループ・アクセス管理" }),
  ).toBeVisible({ timeout: 15000 });

  const groupCard = card(page, "グループ管理");
  await groupCard.getByPlaceholder("新規GroupTypeコード").fill(groupTypeCode);
  await groupCard.getByPlaceholder("GroupType名").fill(groupTypeName);
  await groupCard.getByRole("button", { name: "GroupType追加" }).click();
  await expect(
    groupCard.getByText(`${groupTypeName} (${groupTypeCode})`, {
      exact: false,
    }),
  ).toBeVisible();

  await groupCard
    .getByLabel("グループ種別")
    .selectOption({ label: groupTypeName });
  await groupCard.getByPlaceholder("グループ名").fill(groupName);
  await groupCard.getByPlaceholder("コード", { exact: true }).fill(groupCode);
  await groupCard.getByRole("button", { name: "追加", exact: true }).click();
  const groupRow = groupCard.getByRole("row").filter({ hasText: groupName });
  await expect(groupRow).toContainText(groupCode);

  const membershipCard = card(page, "所属・Feature割当");
  await membershipCard
    .getByLabel("ユーザー")
    .selectOption({ label: SCENARIO_USERS.monthlyEmployee });
  await membershipCard
    .getByLabel("グループ")
    .first()
    .selectOption({ label: groupName });
  await membershipCard.getByRole("button", { name: "所属を追加" }).click();
  await expect(groupRow).toContainText(SCENARIO_USERS.monthlyEmployee);

  const identityCard = card(page, "外部ID・項目管理責任");
  await identityCard
    .getByLabel("ユーザー")
    .selectOption({ label: SCENARIO_USERS.monthlyEmployee });
  await identityCard.getByPlaceholder("Provider").fill("E2E_HR");
  await identityCard.getByPlaceholder("Subject ID").fill("E2E-HR-MONTHLY-001");
  await identityCard.getByRole("button", { name: "リンク" }).click();
  await expect(
    identityCard.getByText(
      `${SCENARIO_USERS.monthlyEmployee}: E2E_HR / E2E-HR-MONTHLY-001`,
    ),
  ).toBeVisible();

  const changeCard = card(page, "将来日付の所属変更");
  const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const localTomorrow = new Date(
    tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60_000,
  )
    .toISOString()
    .slice(0, 16);
  await changeCard
    .getByLabel("ユーザー")
    .selectOption({ label: SCENARIO_USERS.punchEmployee });
  await changeCard.locator('input[type="datetime-local"]').fill(localTomorrow);
  await changeCard.getByLabel("グループ").selectOption({ label: groupName });
  await changeCard.getByPlaceholder("メモ").fill("E2E下書き確認");
  await changeCard.getByRole("button", { name: "明細に追加" }).click();
  await expect(changeCard.getByText("変更明細（1件）")).toBeVisible();
  await changeCard.getByRole("button", { name: "下書き保存" }).click();
  const draftRow = changeCard
    .getByRole("row")
    .filter({ hasText: SCENARIO_USERS.punchEmployee })
    .filter({ hasText: "draft" });
  await expect(draftRow).toBeVisible();
  await draftRow.getByRole("button", { name: "取消" }).click();
  await expect(
    changeCard
      .getByRole("row")
      .filter({ hasText: SCENARIO_USERS.punchEmployee })
      .filter({ hasText: "cancelled" }),
  ).toBeVisible();

  await page.goto("/admin/audit-log");
  await page.getByLabel("イベント種別").fill("group.created");
  await expect(page.getByText("group.created").first()).toBeVisible();
});

test("GroupへのFeature・Role付与と個別停止が有効アクセスへ即時反映される", async ({
  browser,
}) => {
  test.setTimeout(300000);
  const adminContext = await browser.newContext();
  const userContext = await browser.newContext();
  try {
    const adminPage = await adminContext.newPage();
    await loginAs(adminPage, SCENARIO_USERS.admin);
    await ensureAccessScenarioGroup(adminPage);
    const userPage = await userContext.newPage();
    await loginAs(userPage, SCENARIO_USERS.monthlyEmployee);
    await adminPage.goto("/admin/access-control");

    const membershipCard = card(adminPage, "所属・Feature割当");
    await membershipCard
      .getByLabel("グループ")
      .nth(1)
      .selectOption({ label: groupName });
    await membershipCard.getByLabel("Feature").selectOption({ label: "管理" });
    await membershipCard.getByRole("button", { name: "Featureを割当" }).click();

    const roleCard = card(adminPage, "Role・Permission");
    await roleCard.getByPlaceholder("新規Roleコード").fill(roleCode);
    await roleCard
      .getByRole("textbox", { name: "Role名", exact: true })
      .fill(roleName);
    await roleCard.getByRole("button", { name: "Role追加" }).click();
    await expect(
      roleCard.getByText(`${roleName} (${roleCode})`, { exact: false }),
    ).toBeVisible();

    await roleCard.locator("select").last().selectOption({ label: roleName });
    await roleCard
      .locator("fieldset")
      .filter({ hasText: "system_settings" })
      .getByRole("checkbox", { name: "read" })
      .check();
    await roleCard.getByRole("button", { name: "保存", exact: true }).click();

    await roleCard.locator("select").nth(2).selectOption("group");
    await roleCard
      .getByLabel("グループ", { exact: true })
      .selectOption({ label: groupName });
    await roleCard.locator("select").nth(4).selectOption({ label: roleName });
    await roleCard.locator("select").nth(5).selectOption("global");
    await roleCard.getByRole("button", { name: "Roleを割当" }).click();
    await expect(
      roleCard.getByText(new RegExp(`${roleName} / group / global`)),
    ).toBeVisible();

    await expect
      .poll(async () => effectiveAccess(userPage))
      .toMatchObject({
        features: expect.arrayContaining(["administration"]),
        permissions: expect.arrayContaining(["system_settings.read"]),
      });

    const suspensionCard = card(adminPage, "個別Feature停止");
    await suspensionCard
      .getByLabel("ユーザー")
      .selectOption({ label: SCENARIO_USERS.monthlyEmployee });
    await suspensionCard.getByLabel("Feature").selectOption({ label: "管理" });
    await suspensionCard.getByPlaceholder("停止理由").fill("E2E個別停止");
    await suspensionCard
      .getByRole("button", { name: "停止", exact: true })
      .click();
    const suspension = suspensionCard
      .locator("span")
      .filter({ hasText: "E2E個別停止" });
    await expect(suspension).toBeVisible({ timeout: 90000 });
    await expect
      .poll(async () => effectiveAccess(userPage))
      .not.toMatchObject({
        features: expect.arrayContaining(["administration"]),
      });

    await suspension.getByRole("button", { name: "解除" }).click();
    await expect
      .poll(async () => effectiveAccess(userPage))
      .toMatchObject({
        features: expect.arrayContaining(["administration"]),
      });
  } finally {
    await adminContext.close();
    await userContext.close();
  }
});

test("外部HR CSVの差分確認と取込を画面から実行できる", async ({ page }) => {
  test.setTimeout(90000);
  await loginAs(page, SCENARIO_USERS.admin);
  await page.goto("/admin/access-control");

  const identityCard = card(page, "外部ID・項目管理責任");
  for (const field of ["display_name", "email"]) {
    const fieldRow = identityCard
      .locator("div.rounded.border.p-2")
      .filter({ hasText: field });
    await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes(`/field-authorities/${field}`) &&
          response.ok(),
      ),
      fieldRow.locator("select").selectOption("EXTERNAL_HR"),
    ]);
  }

  const csvCard = card(page, "外部HR CSV差分取込");
  await csvCard.locator('input[type="file"]').setInputFiles({
    name: "e2e-external-hr.csv",
    mimeType: "text/csv",
    buffer: Buffer.from(
      "external_subject_id,display_name,email\nE2E-HR-NEW-001,E2E 外部社員,e2e-external@example.com\n",
    ),
  });
  await csvCard.getByRole("button", { name: "差分確認" }).click();
  await expect(csvCard.getByText("全1件 / 新規1件 / 変更1件")).toBeVisible({
    timeout: 20000,
  });
  await expect(
    csvCard.getByRole("row").filter({ hasText: "E2E-HR-NEW-001" }),
  ).toContainText("新規");
  await csvCard.getByRole("button", { name: "確認した差分を適用" }).click();
  await expect(
    identityCard.getByText("E2E 外部社員: EXTERNAL_HR / E2E-HR-NEW-001"),
  ).toBeVisible();

  for (const field of ["display_name", "email"]) {
    const fieldRow = identityCard
      .locator("div.rounded.border.p-2")
      .filter({ hasText: field });
    await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes(`/field-authorities/${field}`) &&
          response.ok(),
      ),
      fieldRow.locator("select").selectOption("LOCAL"),
    ]);
  }
});
