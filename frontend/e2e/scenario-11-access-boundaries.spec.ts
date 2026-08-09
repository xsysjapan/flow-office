import { expect, type Browser, type Page, test } from "@playwright/test";
import { apiFetch, fetchUserIdByEmail } from "./support/api";
import { loginAs, SCENARIO_USERS } from "./support/auth";

type Feature = { id: number; code: string; children?: Feature[] };
type Permission = { id: number; code: string };
type Role = {
  id: number;
  code: string;
  name: string;
  permissions: Permission[];
};
type Group = {
  id: string;
  code: string;
  memberships: Array<{ user_id: string }>;
};

const emails = {
  monthly: "mai.ito@example.com",
  punch: "kenta.takahashi@example.com",
  accounting: "makoto.kobayashi@example.com",
  hr: "yumi.kato@example.com",
};

function flattenFeatures(features: Feature[]): Feature[] {
  return features.flatMap((feature) => [
    feature,
    ...flattenFeatures(feature.children ?? []),
  ]);
}

async function rawRequest(
  page: Page,
  path: string,
  init?: { method?: string; body?: unknown },
): Promise<{ status: number; body: any }> {
  return page.evaluate(
    async ({ path, method, body }) => {
      const token = localStorage.getItem("flow-office.token");
      const response = await fetch(`http://localhost:8000/api${path}`, {
        method: method ?? "GET",
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
          ...(body === undefined ? {} : { "Content-Type": "application/json" }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      const text = await response.text();
      let decoded: any = text;
      try {
        decoded = text ? JSON.parse(text) : null;
      } catch {
        // Keep a non-JSON error response as text.
      }
      return { status: response.status, body: decoded };
    },
    { path, method: init?.method, body: init?.body },
  );
}

async function createGroupType(
  page: Page,
  code: string,
  options?: { maxMemberships?: number },
): Promise<number> {
  await apiFetch(page, "/admin/user-management/group-types", {
    method: "POST",
    body: {
      code,
      name: code,
      membership_limit_type: options?.maxMemberships ? "limited" : "unlimited",
      max_memberships_per_user: options?.maxMemberships ?? null,
      primary_membership_required: false,
    },
  });
  const types = await apiFetch<Array<{ id: number; code: string }>>(
    page,
    "/admin/user-management/group-types",
  );
  return types.find((type) => type.code === code)!.id;
}

async function createGroup(
  page: Page,
  typeId: number,
  code: string,
  parentGroupId?: string,
): Promise<string> {
  const created = await apiFetch<{ id: string }>(
    page,
    "/admin/user-management/groups",
    {
      method: "POST",
      body: {
        group_type_id: typeId,
        code,
        name: code,
        parent_group_id: parentGroupId ?? null,
      },
    },
  );
  return created.id;
}

async function addMembership(page: Page, userId: string, groupId: string) {
  await apiFetch(page, "/admin/user-management/memberships", {
    method: "POST",
    body: {
      user_id: userId,
      group_id: groupId,
      membership_kind: "member",
      is_primary: false,
    },
  });
}

async function createRole(
  page: Page,
  code: string,
  permissionCodes: string[],
): Promise<Role> {
  await apiFetch(page, "/admin/access-control/roles", {
    method: "POST",
    body: { code, name: code },
  });
  const [roles, permissions] = await Promise.all([
    apiFetch<Role[]>(page, "/admin/access-control/roles"),
    apiFetch<Permission[]>(page, "/admin/access-control/permissions"),
  ]);
  const role = roles.find((candidate) => candidate.code === code)!;
  await apiFetch(page, `/admin/access-control/roles/${role.id}/permissions`, {
    method: "PUT",
    body: {
      permission_ids: permissions
        .filter((permission) => permissionCodes.includes(permission.code))
        .map((permission) => permission.id),
    },
  });
  return role;
}

async function assignFeatures(page: Page, groupId: string, codes: string[]) {
  const features = flattenFeatures(
    await apiFetch<Feature[]>(page, "/admin/access-control/features"),
  );
  for (const code of codes) {
    await apiFetch(page, `/admin/access-control/groups/${groupId}/features`, {
      method: "POST",
      body: {
        feature_id: features.find((feature) => feature.code === code)!.id,
      },
    });
  }
}

async function loginTwoUsers(browser: Browser) {
  const adminContext = await browser.newContext();
  const userContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  const userPage = await userContext.newPage();
  await loginAs(adminPage, SCENARIO_USERS.admin);
  await loginAs(userPage, SCENARIO_USERS.monthlyEmployee);
  return { adminContext, userContext, adminPage, userPage };
}

async function reloginAs(page: Page, displayName: string) {
  await page.evaluate(() => localStorage.removeItem("flow-office.token"));
  await loginAs(page, displayName);
}

test("個別Feature停止はメニュー・直URL・APIの全境界へ即時反映される", async ({
  browser,
}) => {
  test.setTimeout(180000);
  const session = await loginTwoUsers(browser);
  try {
    const userId = await fetchUserIdByEmail(session.adminPage, emails.monthly);
    const typeId = await createGroupType(session.adminPage, "E2E_DENIAL");
    const groupId = await createGroup(
      session.adminPage,
      typeId,
      "E2E_DENIAL_GROUP",
    );
    await addMembership(session.adminPage, userId, groupId);
    await assignFeatures(session.adminPage, groupId, [
      "administration",
      "administration.settings",
    ]);
    const role = await createRole(session.adminPage, "e2e_denial_reader", [
      "system_settings.read",
    ]);
    await apiFetch(
      session.adminPage,
      "/admin/access-control/role-assignments",
      {
        method: "POST",
        body: {
          subject_type: "group",
          subject_id: groupId,
          role_id: role.id,
          scope_type: "global",
          scope_group_id: null,
          include_descendants: false,
        },
      },
    );

    await reloginAs(session.userPage, SCENARIO_USERS.monthlyEmployee);
    await session.userPage.goto("/admin/system-settings");
    await expect(
      session.userPage.getByRole("heading", { name: "システム設定" }),
    ).toBeVisible();
    await expect(
      session.userPage.getByRole("link", { name: "システム設定" }),
    ).toBeVisible();

    const features = flattenFeatures(
      await apiFetch<Feature[]>(
        session.adminPage,
        "/admin/access-control/features",
      ),
    );
    await apiFetch(
      session.adminPage,
      "/admin/access-control/feature-suspensions",
      {
        method: "POST",
        body: {
          user_id: userId,
          feature_id: features.find(
            (feature) => feature.code === "administration",
          )!.id,
          reason: "E2E boundary suspension",
        },
      },
    );

    await reloginAs(session.userPage, SCENARIO_USERS.monthlyEmployee);
    await expect(
      session.userPage.getByRole("link", { name: "システム設定" }),
    ).toHaveCount(0);
    await session.userPage.goto("/admin/system-settings");
    await expect(session.userPage).toHaveURL(/\/$/);
    expect(
      (await rawRequest(session.userPage, "/admin/system-settings")).status,
    ).toBe(403);
  } finally {
    await session.adminContext.close();
    await session.userContext.close();
  }
});

test("直接Role付与の有効期間・Groupスコープ・Role複製を確認できる", async ({
  browser,
}) => {
  test.setTimeout(180000);
  const session = await loginTwoUsers(browser);
  try {
    const userId = await fetchUserIdByEmail(session.adminPage, emails.monthly);
    const typeId = await createGroupType(session.adminPage, "E2E_SCOPE");
    const parentId = await createGroup(
      session.adminPage,
      typeId,
      "E2E_SCOPE_PARENT",
    );
    await createGroup(session.adminPage, typeId, "E2E_SCOPE_CHILD", parentId);
    const source = await createRole(session.adminPage, "e2e_timed_role", [
      "user.view",
    ]);
    const future = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();
    const assignment = await apiFetch<{ id: string }>(
      session.adminPage,
      "/admin/access-control/role-assignments",
      {
        method: "POST",
        body: {
          subject_type: "user",
          subject_id: userId,
          role_id: source.id,
          scope_type: "group",
          scope_group_id: parentId,
          include_descendants: true,
          starts_at: future,
          ends_at: null,
        },
      },
    );
    expect(
      (
        await apiFetch<{ permissions: string[] }>(
          session.userPage,
          "/access/me",
        )
      ).permissions,
    ).not.toContain("user.view");

    await apiFetch(
      session.adminPage,
      `/admin/access-control/role-assignments/${assignment.id}`,
      {
        method: "PATCH",
        body: {
          scope_type: "group",
          scope_group_id: parentId,
          include_descendants: true,
          starts_at: new Date(Date.now() - 60_000).toISOString(),
          ends_at: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
        },
      },
    );
    const access = await apiFetch<{
      permissions: string[];
      explanation: { permissions: Array<{ code: string; sources: any[] }> };
    }>(session.userPage, "/access/me");
    expect(access.permissions).toContain("user.view");
    expect(
      access.explanation.permissions.find((item) => item.code === "user.view")
        ?.sources,
    ).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          type: "direct",
          scope_type: "group",
          scope_group_id: parentId,
          include_descendants: true,
        }),
      ]),
    );

    const outsideId = await createGroup(
      session.adminPage,
      typeId,
      "E2E_SCOPE_OUTSIDE",
    );
    const actorId = await fetchUserIdByEmail(session.adminPage, emails.hr);
    const descendantUserId = await fetchUserIdByEmail(
      session.adminPage,
      emails.punch,
    );
    const outsideUserId = await fetchUserIdByEmail(
      session.adminPage,
      emails.accounting,
    );
    const childGroups = await apiFetch<Array<{ id: string; code: string }>>(
      session.adminPage,
      "/admin/user-management/groups",
    );
    const childId = childGroups.find(
      (group) => group.code === "E2E_SCOPE_CHILD",
    )!.id;
    await addMembership(session.adminPage, actorId, parentId);
    await addMembership(session.adminPage, descendantUserId, childId);
    await addMembership(session.adminPage, outsideUserId, outsideId);
    await assignFeatures(session.adminPage, parentId, ["administration.users"]);
    const managedGroups = await apiFetch<
      Array<{ id: string; memberships: Array<{ user_id: string }> }>
    >(session.adminPage, "/admin/user-management/groups");
    const actorGroupIds = managedGroups
      .filter((group) =>
        group.memberships.some((membership) => membership.user_id === actorId),
      )
      .map((group) => group.id);
    const inheritedGlobalAssignments = await apiFetch<
      Array<{
        id: string;
        subject_type: string;
        subject_id: string;
        scope_type: string;
        role?: { permissions?: Permission[] };
      }>
    >(session.adminPage, "/admin/access-control/role-assignments");
    for (const assignment of inheritedGlobalAssignments.filter(
      (candidate) =>
        ((candidate.subject_type === "user" &&
          candidate.subject_id === actorId) ||
          (candidate.subject_type === "group" &&
            actorGroupIds.includes(candidate.subject_id))) &&
        candidate.scope_type === "global" &&
        candidate.role?.permissions?.some(
          (permission) => permission.code === "user.view",
        ),
    )) {
      await apiFetch(
        session.adminPage,
        `/admin/access-control/role-assignments/${assignment.id}`,
        {
          method: "DELETE",
        },
      );
    }
    await apiFetch(
      session.adminPage,
      "/admin/access-control/role-assignments",
      {
        method: "POST",
        body: {
          subject_type: "user",
          subject_id: actorId,
          role_id: source.id,
          scope_type: "group",
          scope_group_id: parentId,
          include_descendants: true,
        },
      },
    );
    const scopedContext = await browser.newContext();
    try {
      const scopedPage = await scopedContext.newPage();
      await loginAs(scopedPage, SCENARIO_USERS.hrStaff);
      expect(
        (await rawRequest(scopedPage, `/users/${descendantUserId}`)).status,
      ).toBe(200);
      expect(
        (await rawRequest(scopedPage, `/users/${outsideUserId}`)).status,
      ).toBe(403);
    } finally {
      await scopedContext.close();
    }

    await session.adminPage.goto("/admin/access-control");
    await session.adminPage
      .getByRole("button", { name: "アクセス設定" })
      .click();
    const roleCard = session.adminPage
      .getByRole("heading", { name: "Role・Permission" })
      .locator('xpath=ancestor::div[contains(@class, "rounded-lg")][1]');
    await roleCard
      .getByLabel("編集するRole")
      .selectOption({ label: source.name });
    await roleCard
      .getByPlaceholder("新規Roleコード")
      .fill("e2e_timed_role_clone");
    await roleCard.getByPlaceholder("Role名").first().fill("E2E Role Clone");
    await roleCard.getByRole("button", { name: "選択Roleを複製" }).click();
    await expect(
      roleCard.getByText("E2E Role Clone (e2e_timed_role_clone)"),
    ).toBeVisible();
    const roles = await apiFetch<Role[]>(
      session.adminPage,
      "/admin/access-control/roles",
    );
    expect(
      roles
        .find((role) => role.code === "e2e_timed_role_clone")
        ?.permissions.map((permission) => permission.code),
    ).toContain("user.view");
  } finally {
    await session.adminContext.close();
    await session.userContext.close();
  }
});

test("複数所属変更を原子的に適用し、競合時は全件を失敗理由付きでロールバックする", async ({
  page,
}) => {
  test.setTimeout(180000);
  await loginAs(page, SCENARIO_USERS.admin);
  const userId = await fetchUserIdByEmail(page, emails.punch);
  const typeId = await createGroupType(page, "E2E_ATOMIC_OK");
  const firstId = await createGroup(page, typeId, "E2E_ATOMIC_FIRST");
  const secondId = await createGroup(page, typeId, "E2E_ATOMIC_SECOND");
  const applied = await apiFetch<{ id: string }>(
    page,
    "/admin/user-management/membership-change-sets",
    {
      method: "POST",
      body: {
        user_id: userId,
        effective_at: new Date(Date.now() - 60_000).toISOString(),
        source_type: "manual",
        note: "E2E two item apply",
        items: [firstId, secondId].map((groupId) => ({
          operation: "add",
          group_type_id: typeId,
          target_group_id: groupId,
          is_primary: false,
        })),
      },
    },
  );
  await apiFetch(
    page,
    `/admin/user-management/membership-change-sets/${applied.id}/apply`,
    { method: "POST" },
  );
  let groups = await apiFetch<Group[]>(page, "/admin/user-management/groups");
  expect(
    groups
      .filter((group) => [firstId, secondId].includes(group.id))
      .every((group) =>
        group.memberships.some((member) => member.user_id === userId),
      ),
  ).toBe(true);

  const limitedTypeId = await createGroupType(page, "E2E_ATOMIC_FAIL", {
    maxMemberships: 1,
  });
  const occupiedId = await createGroup(
    page,
    limitedTypeId,
    "E2E_ATOMIC_OCCUPIED",
  );
  const rejectedId = await createGroup(
    page,
    limitedTypeId,
    "E2E_ATOMIC_REJECTED",
  );
  const failed = await apiFetch<{ id: string }>(
    page,
    "/admin/user-management/membership-change-sets",
    {
      method: "POST",
      body: {
        user_id: userId,
        effective_at: new Date(Date.now() - 60_000).toISOString(),
        source_type: "manual",
        note: "E2E conflict",
        items: [
          {
            operation: "add",
            group_type_id: limitedTypeId,
            target_group_id: rejectedId,
            is_primary: false,
          },
        ],
      },
    },
  );
  await addMembership(page, userId, occupiedId);
  const batch = await page.request.post(
    "http://localhost:8000/api/dev/apply-membership-changes",
    {
      headers: { Accept: "application/json" },
    },
  );
  expect(batch.ok()).toBe(true);
  const changeSets = await apiFetch<
    Array<{ id: string; status: string; failure_reason: string | null }>
  >(page, "/admin/user-management/membership-change-sets");
  expect(changeSets.find((set) => set.id === failed.id)).toEqual(
    expect.objectContaining({
      status: "failed",
      failure_reason: expect.stringContaining("所属数の上限"),
    }),
  );
  groups = await apiFetch<Group[]>(page, "/admin/user-management/groups");
  expect(
    groups
      .find((group) => group.id === occupiedId)
      ?.memberships.some((member) => member.user_id === userId),
  ).toBe(true);
  expect(
    groups
      .find((group) => group.id === rejectedId)
      ?.memberships.some((member) => member.user_id === userId),
  ).toBe(false);
});

test("複数GroupのFeature・Permissionを合成し、付与元と主要監査イベントを表示する", async ({
  browser,
}) => {
  test.setTimeout(180000);
  const session = await loginTwoUsers(browser);
  try {
    const userId = await fetchUserIdByEmail(session.adminPage, emails.monthly);
    const typeId = await createGroupType(session.adminPage, "E2E_UNION");
    const groupA = await createGroup(session.adminPage, typeId, "E2E_UNION_A");
    const groupB = await createGroup(session.adminPage, typeId, "E2E_UNION_B");
    await addMembership(session.adminPage, userId, groupA);
    await addMembership(session.adminPage, userId, groupB);
    await assignFeatures(session.adminPage, groupA, ["workflow.requests"]);
    await assignFeatures(session.adminPage, groupB, ["backoffice.expenses"]);
    const roleA = await createRole(session.adminPage, "e2e_union_user_view", [
      "user.view",
    ]);
    const roleB = await createRole(session.adminPage, "e2e_union_settings", [
      "system_settings.read",
    ]);
    for (const [groupId, roleId] of [
      [groupA, roleA.id],
      [groupB, roleB.id],
    ] as const) {
      await apiFetch(
        session.adminPage,
        "/admin/access-control/role-assignments",
        {
          method: "POST",
          body: {
            subject_type: "group",
            subject_id: groupId,
            role_id: roleId,
            scope_type: "global",
            scope_group_id: null,
            include_descendants: false,
          },
        },
      );
    }
    const features = flattenFeatures(
      await apiFetch<Feature[]>(
        session.adminPage,
        "/admin/access-control/features",
      ),
    );
    await apiFetch(
      session.adminPage,
      "/admin/access-control/feature-suspensions",
      {
        method: "POST",
        body: {
          user_id: userId,
          feature_id: features.find(
            (feature) => feature.code === "workflow.requests",
          )!.id,
          reason: "E2E audit suspension",
        },
      },
    );
    const access = await apiFetch<{
      features: string[];
      permissions: string[];
      explanation: { permissions: Array<{ code: string; sources: any[] }> };
    }>(session.userPage, "/access/me");
    expect(access.features).toContain("backoffice.expenses");
    expect(access.features).not.toContain("workflow.requests");
    expect(access.permissions).toEqual(
      expect.arrayContaining(["user.view", "system_settings.read"]),
    );
    expect(
      access.explanation.permissions.find((item) => item.code === "user.view")
        ?.sources,
    ).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ type: "group", group_id: groupA }),
      ]),
    );

    for (const eventType of [
      "role.created",
      "role.permissions_changed",
      "feature.assigned_to_group",
      "role_assignment.created",
      "user.feature_suspended",
    ]) {
      await session.adminPage.goto(`/admin/audit-log?event_type=${eventType}`);
      await expect(
        session.adminPage.getByText(eventType).first(),
      ).toBeVisible();
    }
  } finally {
    await session.adminContext.close();
    await session.userContext.close();
  }
});

test("外部HR管理項目はローカル更新を拒否し、最終同期と無効化後の履歴を保持する", async ({
  page,
}) => {
  test.setTimeout(180000);
  await loginAs(page, SCENARIO_USERS.admin);
  for (const fieldKey of ["display_name", "email"]) {
    await apiFetch(
      page,
      `/admin/user-management/field-authorities/${fieldKey}`,
      {
        method: "PUT",
        body: { authority_type: "EXTERNAL_HR", provider: "EXTERNAL_HR" },
      },
    );
  }
  await apiFetch(page, "/admin/user-management/external-hr/import", {
    method: "POST",
    body: {
      rows: [
        {
          user_id: crypto.randomUUID(),
          external_subject_id: "E2E-HR-BOUNDARY-001",
          changes: {
            display_name: "E2E 外部HR境界",
            email: "e2e-hr-boundary@example.com",
          },
          group_code: null,
          effective_at: new Date().toISOString(),
        },
      ],
    },
  });
  const importedId = await fetchUserIdByEmail(
    page,
    "e2e-hr-boundary@example.com",
  );
  expect(
    (
      await rawRequest(page, `/users/${importedId}`, {
        method: "PATCH",
        body: { name: "ローカル変更不可" },
      })
    ).status,
  ).toBe(422);
  const imported = await apiFetch<{
    external_identities: Array<{ last_synced_at: string | null }>;
  }>(page, `/users/${importedId}`);
  expect(imported.external_identities[0]?.last_synced_at).toBeTruthy();
  await page.goto(`/admin/users/${importedId}`);
  await expect(
    page.getByText(/EXTERNAL_HR: E2E-HR-BOUNDARY-001/),
  ).toBeVisible();
  await expect(page.getByText(/最終同期/)).not.toContainText("最終同期 -");

  const punchUserId = await fetchUserIdByEmail(page, emails.punch);
  const before = await apiFetch<{ data: unknown[] }>(
    page,
    `/attendance/months/user/${punchUserId}`,
  );
  await apiFetch(page, `/users/${punchUserId}`, {
    method: "PATCH",
    body: { account_status: "disabled" },
  });
  const disabledUser = await apiFetch<{ account_status: string }>(
    page,
    `/users/${punchUserId}`,
  );
  const after = await apiFetch<{ data: unknown[] }>(
    page,
    `/attendance/months/user/${punchUserId}`,
  );
  expect(disabledUser.account_status).toBe("disabled");
  expect(after.data).toEqual(before.data);
  await apiFetch(page, `/users/${punchUserId}`, {
    method: "PATCH",
    body: { account_status: "retired" },
  });
  const retiredUser = await apiFetch<{ account_status: string }>(
    page,
    `/users/${punchUserId}`,
  );
  const afterRetirement = await apiFetch<{ data: unknown[] }>(
    page,
    `/attendance/months/user/${punchUserId}`,
  );
  expect(retiredUser.account_status).toBe("retired");
  expect(afterRetirement.data).toEqual(before.data);
});
