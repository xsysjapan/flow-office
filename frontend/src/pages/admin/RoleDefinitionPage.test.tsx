import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "../../api/client";
import * as accessControlApi from "../../api/accessControl";
import type {
  AccessRole,
  Feature,
  Permission,
  RoleAssignment,
} from "../../api/accessControl";
import * as userManagementApi from "../../api/userManagement";
import type { ManagedGroup } from "../../api/userManagement";
import { RoleDefinitionPage } from "./RoleDefinitionPage";
import { notify } from "../../lib/notify";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

const groupType: ManagedGroup["type"] = {
  id: 1,
  code: "ORGANIZATION",
  name: "組織",
  display_order: 1,
  status: "active",
  is_system: true,
  membership_limit_type: "unlimited",
  max_memberships_per_user: null,
  requires_primary_membership: false,
  max_primary_memberships: null,
};

const managedGroup: ManagedGroup = {
  id: "group-1",
  group_type_id: 1,
  name: "総務部",
  code: "GENERAL_AFFAIRS",
  status: "active",
  parent_group_id: null,
  memberships_count: 0,
  type: groupType,
  features: [],
  memberships: [],
  role_assignments: [],
};

const permissions: Permission[] = [
  {
    id: 1,
    code: "attendance.view",
    resource: "attendance",
    action: "view",
    description: "勤怠を閲覧する",
    allowed_scope_types: ["global"],
  },
];

const features: Feature[] = [
  {
    id: 1,
    code: "attendance",
    name: "勤怠",
    status: "active",
    display_order: 1,
    is_selectable: true,
    children: [
      {
        id: 11,
        code: "attendance.approve",
        name: "勤怠承認",
        status: "active",
        display_order: 1,
        is_selectable: true,
      },
    ],
  },
];

function makeRoles(): AccessRole[] {
  return [
    {
      id: 1,
      code: "employee",
      name: "一般社員",
      description: null,
      status: "active",
      is_system: true,
      permissions: [],
      features: [],
    },
    {
      id: 2,
      code: "backoffice_staff",
      name: "バックオフィス担当者",
      description: null,
      status: "active",
      is_system: false,
      permissions,
      features: [features[0]],
    },
  ];
}

function renderPage({
  roles = makeRoles(),
  roleAssignments = [] as RoleAssignment[],
  groups = [managedGroup] as ManagedGroup[],
  initialPath = "/admin/access/roles",
}: {
  roles?: AccessRole[];
  roleAssignments?: RoleAssignment[];
  groups?: ManagedGroup[];
  initialPath?: string;
} = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue(roles);
  vi.spyOn(accessControlApi, "fetchPermissions").mockResolvedValue(permissions);
  vi.spyOn(accessControlApi, "fetchFeatures").mockResolvedValue(features);
  vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue(
    roleAssignments,
  );
  vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue(groups);

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <RoleDefinitionPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("RoleDefinitionPage", () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear();
  });

  it("shows the role list", async () => {
    renderPage();

    expect(await screen.findByText("一般社員")).toBeInTheDocument();
    expect(screen.getByText("バックオフィス担当者")).toBeInTheDocument();
  });

  it("shows the detail (permission/feature editors) when a role row is clicked", async () => {
    renderPage();

    await userEvent.click(
      await screen.findByRole("row", {
        name: /バックオフィス担当者の詳細を開く/,
      }),
    );

    expect(
      await screen.findByRole("heading", { name: "バックオフィス担当者" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Permission")).toBeInTheDocument();
    expect(screen.getByText("Feature構成")).toBeInTheDocument();
  });

  it("has one save button that is disabled until something changes", async () => {
    renderPage({ initialPath: "/admin/access/roles?roleId=2" });
    await screen.findByDisplayValue("バックオフィス担当者");

    const buttons = screen.getAllByRole("button", { name: "保存" });
    expect(buttons).toHaveLength(1);
    expect(buttons[0]).toBeDisabled();
    for (const old of ["基本情報を保存", "Permissionを保存", "Feature構成を保存"]) {
      expect(screen.queryByRole("button", { name: old })).toBeNull();
    }

    await userEvent.click(screen.getByRole("checkbox", { name: "勤怠承認" }));
    expect(screen.getByRole("button", { name: "保存" })).toBeEnabled();
    await userEvent.click(screen.getByRole("checkbox", { name: "勤怠承認" }));
    expect(screen.getByRole("button", { name: "保存" })).toBeDisabled();
  });

  it("sends only the changed keys in one request", async () => {
    const spy = vi
      .spyOn(accessControlApi, "updateRoleDefinition")
      .mockResolvedValue(makeRoles()[1]);
    spy.mockClear();
    renderPage({ initialPath: "/admin/access/roles?roleId=2" });
    await screen.findByDisplayValue("バックオフィス担当者");

    await userEvent.type(screen.getByLabelText("説明"), "備考");
    await userEvent.click(screen.getByRole("checkbox", { name: "勤怠承認" }));
    await userEvent.click(screen.getByRole("button", { name: "保存" }));

    await waitFor(() => expect(spy).toHaveBeenCalledTimes(1));
    expect(spy).toHaveBeenCalledWith(2, {
      description: "備考",
      feature_ids: expect.arrayContaining([1, 11]),
    });
    expect(Object.keys(spy.mock.calls[0][1]).sort()).toEqual([
      "description",
      "feature_ids",
    ]);
    await waitFor(() =>
      expect(notify.success).toHaveBeenCalledWith("ロールの定義を保存しました"),
    );
  });

  it("shows keyed 422 errors under each field and others above the button", async () => {
    vi.spyOn(accessControlApi, "updateRoleDefinition").mockRejectedValue(
      new ApiError(422, "入力内容に誤りがあります。", {
        name: ["ロール名は既に使われています。"],
        feature_ids: ["Feature構成が正しくありません。"],
        other: ["その他のエラーです。"],
      }),
    );
    renderPage({ initialPath: "/admin/access/roles?roleId=2" });
    await screen.findByDisplayValue("バックオフィス担当者");

    await userEvent.type(screen.getByLabelText("ロール名"), "2");
    await userEvent.click(screen.getByRole("button", { name: "保存" }));

    const nameError = await screen.findByText("ロール名は既に使われています。");
    expect(nameError.previousElementSibling).toBe(
      screen.getByLabelText("ロール名"),
    );
    expect(
      screen.getByText("Feature構成が正しくありません。"),
    ).toBeInTheDocument();
    expect(screen.getByText("その他のエラーです。")).toBeInTheDocument();
    expect(screen.getAllByText("入力内容に誤りがあります。")).toHaveLength(1);
  });

  it("disables the status of a system role", async () => {
    renderPage({ initialPath: "/admin/access/roles?roleId=1" });
    await screen.findByDisplayValue("一般社員");

    expect(screen.getByLabelText("状態")).toBeDisabled();
    expect(
      screen.getByText("システム標準ロールのため状態は変更できません。"),
    ).toBeInTheDocument();
  });

  it("shows only active group assignments for the selected role in the 割当グループ section", async () => {
    renderPage({
      initialPath: "/admin/access/roles?roleId=2",
      roleAssignments: [
        {
          id: "assignment-1",
          subject_type: "group",
          subject_id: "group-1",
          role_id: 2,
          scope_type: "group",
          scope_group_id: "group-1",
          includes_descendants: false,
          starts_at: null,
          ends_at: null,
          status: "active",
        },
        {
          id: "assignment-2",
          subject_type: "user",
          subject_id: "user-1",
          role_id: 2,
          scope_type: "self",
          scope_group_id: null,
          includes_descendants: false,
          starts_at: null,
          ends_at: null,
          status: "active",
        },
      ],
    });

    expect(await screen.findByText("割当グループ")).toBeInTheDocument();
    expect(await screen.findByText("総務部")).toBeInTheDocument();
  });

  it("does not render the literal 0 for a non-system role's status note (is_system as 0/1)", async () => {
    renderPage({
      roles: [
        {
          ...makeRoles()[1],
          // バックエンドがboolean相当のフィールドを0/1で返してくることがある既知の挙動を再現する。
          is_system: 0 as unknown as boolean,
        },
      ],
      initialPath: "/admin/access/roles?roleId=2",
    });

    expect(
      await screen.findByRole("heading", { name: "バックオフィス担当者" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("システム標準ロールのため状態は変更できません。"),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText("状態")).not.toBeDisabled();
  });
});
