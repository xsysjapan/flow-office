import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import type { Paginated, User } from "../../api/types";
import type { GroupType, ManagedGroup } from "../../api/userManagement";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import * as accessControlApi from "../../api/accessControl";
import type { AccessRole, RoleAssignment } from "../../api/accessControl";
import { GroupDetailPage } from "./GroupDetailPage";

const role: AccessRole = {
  id: 3,
  code: "group_manager",
  name: "グループ管理者",
  description: null,
  status: "active",
  is_system: false,
  permissions: [
    {
      id: 1,
      code: "attendance.view",
      resource: "attendance",
      action: "view",
      description: null,
      allowed_scope_types: ["global", "group"],
    },
  ],
  features: [],
};

const groupType: GroupType = {
  id: 1,
  code: "ORGANIZATION",
  name: "組織",
  display_order: 1,
  status: "active",
  is_system: true,
  membership_limit_type: "unlimited",
  max_memberships_per_user: null,
  primary_membership_required: false,
  max_primary_memberships: 1,
};

const parent: ManagedGroup = {
  id: "group-parent",
  group_type_id: 1,
  name: "本社",
  code: "HEAD_OFFICE",
  status: "active",
  parent_group_id: null,
  memberships_count: 0,
  type: groupType,
  features: [],
  memberships: [],
  role_assignments: [],
};

const group: ManagedGroup = {
  ...parent,
  id: "group-1",
  name: "総務部",
  code: "GENERAL_AFFAIRS",
  memberships_count: 1,
  memberships: [
    {
      id: 1,
      user_id: "user-1",
      group_id: "group-1",
      membership_kind: "member",
      is_primary: false,
      user: {
        id: "user-1",
        name: "山田太郎",
        email: "yamada@example.com",
      },
    },
  ],
};

const availableUser: User = {
  id: "user-2",
  name: "佐藤花子",
  email: "sato@example.com",
  department: null,
  job_title: null,
  employment_status: "active",
  last_login_at: null,
};

function renderPage({
  roleAssignments = [] as RoleAssignment[],
}: { roleAssignments?: RoleAssignment[] } = {}) {
  vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
    parent,
    group,
  ]);
  vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue([groupType]);
  vi.spyOn(userManagementApi, "fetchMembershipChangeSets").mockResolvedValue(
    [],
  );
  vi.spyOn(usersApi, "fetchUsers").mockResolvedValue({
    data: [availableUser],
    meta: { current_page: 1, last_page: 1, total: 1 },
    links: { next: null, prev: null },
  } satisfies Paginated<User>);
  vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([role]);
  vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue(
    roleAssignments,
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/admin/groups/group-1"]}>
        <Routes>
          <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("GroupDetailPage", () => {
  it("navigates to the new group's own detail page after creating it, matching the update flow", async () => {
    const newGroup: ManagedGroup = { ...parent, id: "group-new", name: "新規グループ" };
    const fetchGroupsSpy = vi
      .spyOn(userManagementApi, "fetchManagedGroups")
      .mockResolvedValue([parent, group]);
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue([
      groupType,
    ]);
    vi.spyOn(userManagementApi, "createGroup").mockResolvedValue({
      id: "group-new",
    });
    vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([role]);
    vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue([]);
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={["/admin/groups/new"]}>
          <Routes>
            <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText("グループを新規作成")).toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText("グループ種別"), "1");
    await userEvent.type(screen.getByLabelText("名称"), "新規グループ");
    // 作成後にグループ一覧が再取得された際、新規グループ自身が含まれるようにする
    fetchGroupsSpy.mockResolvedValue([parent, group, newGroup]);
    await userEvent.click(screen.getByRole("button", { name: "作成する" }));

    // 作成後は一覧ではなく作成されたグループ自身の詳細画面(このページ)へ遷移する
    // (更新時に同ページへ留まる挙動と揃える。SKILL.md §2.6)。
    expect(await screen.findByText("新規グループの詳細")).toBeInTheDocument();
  });

  it("assigns the selected initial role to the newly created group", async () => {
    const newGroup: ManagedGroup = {
      ...parent,
      id: "group-new",
      name: "新規グループ",
    };
    vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
      parent,
      group,
    ]);
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue([
      groupType,
    ]);
    vi.spyOn(userManagementApi, "createGroup").mockResolvedValue({
      id: "group-new",
    });
    vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([role]);
    vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue([]);
    const createAssignmentSpy = vi
      .spyOn(accessControlApi, "createRoleAssignment")
      .mockResolvedValue({ id: "assignment-new" });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={["/admin/groups/new"]}>
          <Routes>
            <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText("グループを新規作成")).toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText("グループ種別"), "1");
    await userEvent.type(screen.getByLabelText("名称"), "新規グループ");
    await userEvent.selectOptions(
      screen.getByLabelText("作成時に割り当てるRole(任意)"),
      "3",
    );
    vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
      parent,
      group,
      newGroup,
    ]);
    await userEvent.click(screen.getByRole("button", { name: "作成する" }));

    await waitFor(() =>
      expect(createAssignmentSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          subject_type: "group",
          subject_id: "group-new",
          role_id: 3,
          scope_type: "global",
        }),
        expect.anything(),
      ),
    );
  });

  it("updates individual fields including the parent group", async () => {
    vi.spyOn(userManagementApi, "updateGroup").mockResolvedValue(undefined);
    renderPage();

    expect(await screen.findByText("総務部の詳細")).toBeInTheDocument();
    expect(screen.queryByLabelText("コード")).not.toBeInTheDocument();
    expect(screen.queryByText("← グループ一覧へ戻る")).not.toBeInTheDocument();
    await userEvent.clear(screen.getByLabelText("名称"));
    await userEvent.type(screen.getByLabelText("名称"), "総務・人事部");
    await userEvent.selectOptions(
      screen.getByLabelText("親グループ"),
      "group-parent",
    );
    await userEvent.selectOptions(screen.getByLabelText("状態"), "inactive");
    await userEvent.click(screen.getByRole("button", { name: "変更を保存" }));

    await waitFor(() =>
      expect(userManagementApi.updateGroup).toHaveBeenCalledWith("group-1", {
        name: "総務・人事部",
        parent_group_id: "group-parent",
        status: "inactive",
      }),
    );
  });

  it("shows and removes an active role assignment for the group in the Role割当 section", async () => {
    vi.spyOn(accessControlApi, "removeRoleAssignment").mockResolvedValue(
      undefined,
    );
    renderPage({
      roleAssignments: [
        {
          id: "assignment-1",
          subject_type: "group",
          subject_id: "group-1",
          role_id: 3,
          scope_type: "global",
          scope_group_id: null,
          include_descendants: false,
          starts_at: null,
          ends_at: null,
          status: "active",
          role,
        },
      ],
    });

    expect(await screen.findByText("Role割当")).toBeInTheDocument();
    const roleCell = await screen.findByText("グループ管理者");
    const row = roleCell.closest("tr");
    if (!row) throw new Error("Role割当の行が見つかりません");

    await userEvent.click(within(row).getByRole("button", { name: "解除" }));
    await userEvent.click(screen.getByRole("button", { name: "解除する" }));

    await waitFor(() =>
      expect(accessControlApi.removeRoleAssignment).toHaveBeenCalledWith(
        "assignment-1",
        expect.anything(),
      ),
    );
  });

  it("adds and removes members in the group detail", async () => {
    vi.spyOn(userManagementApi, "applyMembershipChangeNow").mockResolvedValue(
      undefined,
    );
    renderPage();

    expect(await screen.findByText("所属メンバー")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "山田太郎" })).toHaveAttribute(
      "href",
      "/admin/users/user-1",
    );

    await userEvent.click(screen.getByRole("button", { name: "所属を追加" }));
    await userEvent.selectOptions(
      await screen.findByLabelText("対象ユーザー"),
      "user-2",
    );
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));
    await waitFor(() =>
      expect(userManagementApi.applyMembershipChangeNow).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: "user-2",
          items: [
            expect.objectContaining({
              operation: "add",
              target_group_id: "group-1",
            }),
          ],
        }),
        expect.anything(),
      ),
    );

    await userEvent.click(screen.getByRole("button", { name: "解除" }));
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));
    await waitFor(() =>
      expect(
        userManagementApi.applyMembershipChangeNow,
      ).toHaveBeenLastCalledWith(
        expect.objectContaining({
          user_id: "user-1",
          items: [
            expect.objectContaining({
              operation: "remove",
              target_group_id: "group-1",
            }),
          ],
        }),
        expect.anything(),
      ),
    );
  });
});
