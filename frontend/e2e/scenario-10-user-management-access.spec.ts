import { expect, type Page, test } from "@playwright/test";
import { apiFetch, fetchUserIdByEmail } from "./support/api";
import { loginAs, SCENARIO_USERS } from "./support/auth";
import { pickDate } from "./support/ui";

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
  const groups = await apiFetch<
    Array<{ id: string; code: string; memberships: Array<{ user_id: string }> }>
  >(page, "/admin/user-management/groups");
  const existing = groups.find((group) => group.code === groupCode);
  const userId = await fetchUserIdByEmail(page, "mai.ito@example.com");
  if (existing) {
    if (
      !existing.memberships.some((membership) => membership.user_id === userId)
    ) {
      await apiFetch(page, "/admin/user-management/memberships", {
        method: "POST",
        body: {
          user_id: userId,
          group_id: existing.id,
          membership_kind: "member",
          is_primary: false,
        },
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
  const created = await apiFetch<{ id: string }>(
    page,
    "/admin/user-management/groups",
    {
      method: "POST",
      body: {
        group_type_id: types.find((type) => type.code === groupTypeCode)!.id,
        name: groupName,
        code: groupCode,
      },
    },
  );
  await apiFetch(page, "/admin/user-management/memberships", {
    method: "POST",
    body: {
      user_id: userId,
      group_id: created.id,
      membership_kind: "member",
      is_primary: false,
    },
  });
  return created.id;
}

test("モバイル管理メニューとアクセス設定をキーボードで操作できる", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, SCENARIO_USERS.admin);
  await page.goto("/admin");

  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  const navigation = page.getByRole("dialog", { name: "管理メニュー" });
  await expect(
    navigation.getByRole("link", { name: "グループ種別" }),
  ).toBeVisible();
  await navigation.getByRole("link", { name: "アクセス管理" }).click();
  const featureTab = page.getByRole("tab", { name: "Feature" });
  await featureTab.focus();
  await page.keyboard.press("ArrowRight");
  await expect(
    page.getByRole("tab", { name: "Role・Permission" }),
  ).toHaveAttribute("data-state", "active");
  await expect(
    page.getByRole("heading", { name: "Role・Permission" }),
  ).toBeVisible();
});

test("人事担当者には人事機能だけを表示しアクセス管理への直URLも拒否する", async ({
  page,
}) => {
  await loginAs(page, SCENARIO_USERS.hrStaff);
  await page.goto("/admin");

  const navigation = page.getByRole("complementary");
  await expect(
    navigation.getByRole("link", { name: "グループ" }),
  ).toBeVisible();
  await expect(
    navigation.getByRole("link", { name: "人事データ連携" }),
  ).toBeVisible();
  await expect(
    navigation.getByRole("link", { name: "所属変更" }),
  ).toBeVisible();
  await expect(
    navigation.getByRole("link", { name: "アクセス管理" }),
  ).toHaveCount(0);
  await expect(
    navigation.getByRole("link", { name: "ID・管理元設定" }),
  ).toHaveCount(0);

  await navigation.getByRole("link", { name: "ユーザー" }).click();
  await expect(
    page.getByRole("columnheader", { name: "グループ（所属）" }),
  ).toBeVisible();
  await expect(page.getByRole("columnheader", { name: "権限" })).toHaveCount(0);
  await expect(page.getByRole("columnheader", { name: "Feature" })).toHaveCount(
    0,
  );

  await navigation.getByRole("link", { name: "グループ" }).click();
  await expect(
    page.getByRole("columnheader", { name: "メンバー" }),
  ).toBeVisible();
  await expect(page.getByRole("columnheader", { name: "Feature" })).toHaveCount(
    0,
  );
  await expect(
    page.getByRole("columnheader", { name: "Role・管理スコープ" }),
  ).toHaveCount(0);

  await page.goto("/admin/access-control");
  await expect(page).not.toHaveURL(/\/admin\/access-control/);
  await expect(page.getByRole("heading", { name: "アクセス管理" })).toHaveCount(
    0,
  );
});

test("人事データ連携を狭い画面でも横崩れせず操作できる", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAs(page, SCENARIO_USERS.hrStaff);
  await page.goto("/admin/hr-import");

  await expect(
    page.getByRole("heading", { name: "人事データ連携" }),
  ).toBeVisible();
  await expect(page.getByLabel("対象ユーザー")).toHaveCount(0);
  await expect(page.getByLabel("外部HR CSVファイル")).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});

test("ユーザー管理を中心にグループ種別・グループ・所属・外部ID・所属変更を管理できる", async ({
  page,
}) => {
  test.setTimeout(300000);
  await loginAs(page, SCENARIO_USERS.admin);
  await page.goto("/admin/group-types");
  await expect(
    page.getByRole("heading", { name: "グループ種別管理" }).first(),
  ).toBeVisible();

  const groupTypeCard = card(page, "グループ種別管理");
  await groupTypeCard
    .getByPlaceholder("新規グループ種別コード")
    .fill(groupTypeCode);
  await groupTypeCard.getByPlaceholder("グループ種別名").fill(groupTypeName);
  await groupTypeCard
    .getByRole("button", { name: "グループ種別を追加" })
    .click();
  await expect(
    groupTypeCard.getByText(`${groupTypeName} (${groupTypeCode})`, {
      exact: false,
    }),
  ).toBeVisible();

  await page.goto("/admin/groups");
  await expect(
    page.getByRole("heading", { name: "グループ管理" }).first(),
  ).toBeVisible();
  await page.getByRole("link", { name: "新規グループ" }).click();
  await page
    .getByLabel("グループ種別", { exact: true })
    .selectOption({ label: groupTypeName });
  await page.getByLabel("名称", { exact: true }).fill(groupName);
  await page.getByRole("button", { name: "作成する" }).click();
  const groupCard = card(page, "グループ一覧");
  const groupRow = groupCard.getByRole("row").filter({ hasText: groupName });
  await expect(groupRow).not.toContainText(groupCode);
  await expect(groupRow.getByText("0人", { exact: true })).toBeVisible();
  await expect(groupRow.getByRole("link", { name: /所属を管理/ })).toHaveCount(
    0,
  );
  await expect(
    groupRow.getByRole("combobox", { name: `${groupName}の親グループ` }),
  ).toHaveCount(0);

  await groupRow.getByRole("link", { name: groupName }).click();
  await expect(
    page.getByRole("heading", { name: `${groupName}の詳細` }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "所属メンバー" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "所属を追加" }).click();
  await page
    .getByLabel("対象ユーザー")
    .selectOption({ label: SCENARIO_USERS.monthlyEmployee });
  await page.getByRole("button", { name: "変更を実行" }).click();
  await expect(
    page.getByRole("link", { name: SCENARIO_USERS.monthlyEmployee }),
  ).toBeVisible();

  const monthlyUserId = await fetchUserIdByEmail(page, "mai.ito@example.com");
  await page.goto(`/admin/users/${monthlyUserId}`);
  const membershipSection = page
    .getByRole("heading", { name: "グループ所属" })
    .locator(
      'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rounded ")][1]',
    );
  await expect(
    membershipSection.getByText(groupName, { exact: true }),
  ).toBeVisible();
  const membershipEntry = membershipSection
    .getByText(groupName, { exact: true })
    .locator(
      'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rounded ")][1]',
    );
  await membershipEntry.getByRole("button", { name: "解除" }).click();
  await page.getByRole("button", { name: "変更を実行" }).click();
  await expect(
    membershipSection.getByText(groupName, { exact: true }),
  ).toHaveCount(0);
  await membershipSection.getByRole("button", { name: "所属を追加" }).click();
  await page
    .getByLabel("変更先グループ")
    .selectOption({ label: `${groupName}（${groupTypeName}）` });
  await page.getByRole("button", { name: "変更を実行" }).click();
  await expect(
    membershipSection.getByText(groupName, { exact: true }),
  ).toBeVisible();

  const userDetailTomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const userDetailDate = new Date(
    userDetailTomorrow.getTime() -
      userDetailTomorrow.getTimezoneOffset() * 60_000,
  )
    .toISOString()
    .slice(0, 10);
  const scheduledMembershipEntry = membershipSection
    .getByText(groupName, { exact: true })
    .locator(
      'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rounded ")][1]',
    );
  await scheduledMembershipEntry.getByRole("button", { name: "解除" }).click();
  const membershipDialog = page.getByRole("dialog");
  await membershipDialog.getByLabel("変更タイミング").selectOption("scheduled");
  await pickDate(page, "適用日時", userDetailDate, {
    root: membershipDialog,
  });
  await membershipDialog.getByRole("button", { name: "変更を予約" }).click();
  await expect(
    membershipSection.getByRole("status", { name: "予約済み" }),
  ).toBeVisible();

  await page.goto("/admin/identity-settings");
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

  await page.goto("/admin/membership-changes");
  const changeCard = card(page, "所属変更一覧");
  await changeCard.getByRole("button", { name: "変更予約作成" }).click();
  const changeDialog = page.getByRole("dialog", { name: "変更予約作成" });
  await expect(changeDialog).toBeVisible();
  const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const localTomorrow = new Date(
    tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60_000,
  )
    .toISOString()
    .slice(0, 16);
  await changeDialog
    .getByLabel("対象ユーザー")
    .selectOption({ label: SCENARIO_USERS.punchEmployee });
  await pickDate(page, "所属変更の適用日時(日付)", localTomorrow.slice(0, 10), {
    root: changeDialog,
  });
  await changeDialog
    .getByLabel("変更先グループ")
    .selectOption({ label: groupName });
  await changeDialog.getByLabel("メモ").fill("E2E下書き確認");
  await changeDialog.getByRole("button", { name: "明細に追加" }).click();
  await expect(changeDialog.getByText("変更明細（1件）")).toBeVisible();
  await changeDialog.getByRole("button", { name: "下書き保存" }).click();
  await expect(changeDialog).not.toBeVisible();
  const draftRow = changeCard
    .getByRole("row")
    .filter({ hasText: SCENARIO_USERS.punchEmployee })
    .filter({ hasText: "下書き" });
  await expect(draftRow).toBeVisible();
  await draftRow.getByRole("button", { name: "変更" }).click();
  const editDialog = page.getByRole("dialog", { name: "所属変更予約を変更" });
  await expect(editDialog).toBeVisible();
  await editDialog.getByLabel("メモ").fill("E2E下書き変更確認");
  await editDialog.getByRole("button", { name: "変更を保存" }).click();
  await expect(editDialog).not.toBeVisible();
  await draftRow.getByRole("button", { name: "取消" }).click();
  await page.getByRole("button", { name: "取り消す" }).click();
  await expect(
    changeCard
      .getByRole("row")
      .filter({ hasText: SCENARIO_USERS.punchEmployee })
      .filter({ hasText: "取消済み" }),
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

    const featureCard = card(adminPage, "Feature設定");
    await featureCard
      .getByLabel("対象グループ")
      .selectOption({ label: groupName });
    await featureCard
      .getByLabel("Feature", { exact: true })
      .selectOption({ label: "管理" });
    const assignFeatureButton = featureCard.getByRole("button", {
      name: "Featureを割当",
    });
    await Promise.all([
      adminPage.waitForResponse(
        (response) =>
          response.url().includes("/access-control/groups/") &&
          response.url().endsWith("/features") &&
          response.request().method() === "POST" &&
          response.ok(),
      ),
      assignFeatureButton.click(),
    ]);
    await expect(assignFeatureButton).toBeEnabled({ timeout: 90000 });

    await adminPage.getByRole("tab", { name: "Role・Permission" }).click();
    const roleCard = card(adminPage, "Role・Permission");
    await roleCard.getByPlaceholder("新規Roleコード").fill(roleCode);
    await roleCard
      .getByRole("textbox", { name: "Role名", exact: true })
      .fill(roleName);
    const addRoleButton = roleCard.getByRole("button", { name: "Role追加" });
    await Promise.all([
      adminPage.waitForResponse(
        (response) =>
          response.url().endsWith("/access-control/roles") &&
          response.request().method() === "POST" &&
          response.ok(),
      ),
      addRoleButton.click(),
    ]);
    await expect(addRoleButton).toBeEnabled({ timeout: 90000 });
    await expect(
      roleCard.getByText(`${roleName} (${roleCode})`, { exact: false }),
    ).toBeVisible();

    await roleCard
      .getByLabel("Permissionを編集するRole")
      .selectOption({ label: roleName });
    await roleCard
      .locator("fieldset")
      .filter({ hasText: "system_settings" })
      .getByRole("checkbox", { name: "read" })
      .check();
    const savePermissionsButton = roleCard.getByRole("button", {
      name: "保存",
      exact: true,
    });
    await Promise.all([
      adminPage.waitForResponse(
        (response) =>
          /\/access-control\/roles\/\d+\/permissions$/.test(response.url()) &&
          response.request().method() === "PUT" &&
          response.ok(),
      ),
      savePermissionsButton.click(),
    ]);
    await expect(savePermissionsButton).toBeEnabled({ timeout: 90000 });

    await roleCard.getByLabel("付与先種別").selectOption("group");
    await roleCard
      .getByLabel("付与先グループ")
      .selectOption({ label: groupName });
    await roleCard
      .getByLabel("Role", { exact: true })
      .selectOption({ label: roleName });
    await roleCard.getByLabel("対象範囲").selectOption("global");
    const assignRoleButton = roleCard.getByRole("button", {
      name: "Roleを割当",
    });
    await Promise.all([
      adminPage.waitForResponse(
        (response) =>
          response.url().endsWith("/role-assignments") &&
          response.request().method() === "POST" &&
          response.ok(),
      ),
      assignRoleButton.click(),
    ]);
    await expect(assignRoleButton).toBeEnabled({ timeout: 90000 });
    await expect(
      roleCard.getByText(new RegExp(`${roleName} / group / global`)),
    ).toBeVisible();

    await expect
      .poll(async () => effectiveAccess(userPage))
      .toMatchObject({
        features: expect.arrayContaining(["administration"]),
        permissions: expect.arrayContaining(["system_settings.read"]),
      });

    await adminPage.getByRole("tab", { name: "個別停止" }).click();
    const suspensionCard = card(adminPage, "個別Feature停止");
    await suspensionCard
      .getByLabel("対象ユーザー")
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
    await adminPage.getByRole("button", { name: "解除する" }).click();
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
  test.setTimeout(180000);
  await loginAs(page, SCENARIO_USERS.admin);
  await page.goto("/admin/identity-settings");

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

  await page.goto("/admin/hr-import");
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
  await Promise.all([
    page.waitForResponse(
      (response) =>
        response.url().endsWith("/external-hr/import") && response.ok(),
    ),
    csvCard.getByRole("button", { name: "確認した差分を適用" }).click(),
  ]);
  await page.goto("/admin/identity-settings");
  const refreshedIdentityCard = card(page, "外部ID・項目管理責任");
  await expect(
    refreshedIdentityCard.getByText(
      "E2E 外部社員: EXTERNAL_HR / E2E-HR-NEW-001",
    ),
  ).toBeVisible();

  for (const field of ["display_name", "email"]) {
    const fieldRow = refreshedIdentityCard
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
