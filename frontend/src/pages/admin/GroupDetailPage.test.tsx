import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { Paginated, User } from "../../api/types";
import type { GroupType, ManagedGroup } from "../../api/userManagement";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import * as accessControlApi from "../../api/accessControl";
import type { AccessRole, RoleAssignment } from "../../api/accessControl";
import { GroupDetailPage } from "./GroupDetailPage";
import { notify } from "../../lib/notify";
import { ApiError } from "../../api/client";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

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
  requires_primary_membership: false,
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
  path = "/admin/groups/group-1",
}: { roleAssignments?: RoleAssignment[]; path?: string } = {}) {
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
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("GroupDetailPage", () => {
  beforeEach(() => {
    vi.mocked(notify.success).mockClear();
  });

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

    expect(await screen.findByRole("heading", { name: "基本情報" })).toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText("グループ種別"), "1");
    await userEvent.type(screen.getByLabelText("名称"), "新規グループ");
    // 作成後にグループ一覧が再取得された際、新規グループ自身が含まれるようにする
    fetchGroupsSpy.mockResolvedValue([parent, group, newGroup]);
    await userEvent.click(screen.getByRole("button", { name: "作成" }));

    // 作成後は一覧ではなく作成されたグループ自身の詳細画面(このページ)へ遷移する
    // (更新時に同ページへ留まる挙動と揃える。SKILL.md §2.6)。
    expect(await screen.findByRole("heading", { name: "新規グループ" })).toBeInTheDocument();
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

    expect(await screen.findByRole("heading", { name: "基本情報" })).toBeInTheDocument();
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
    await userEvent.click(screen.getByRole("button", { name: "作成" }));

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

    expect(await screen.findByRole("heading", { name: "総務部" })).toBeInTheDocument();
    expect(screen.queryByLabelText("コード")).not.toBeInTheDocument();
    expect(
      await screen.findByRole("link", { name: "グループに戻る" }),
    ).toHaveAttribute("href", "/admin/groups");
    await userEvent.clear(screen.getByLabelText("名称"));
    await userEvent.type(screen.getByLabelText("名称"), "総務・人事部");
    await userEvent.selectOptions(
      screen.getByLabelText("親グループ"),
      "group-parent",
    );
    await userEvent.selectOptions(screen.getByLabelText("状態"), "inactive");
    await userEvent.click(screen.getByRole("button", { name: "保存" }));

    await waitFor(() =>
      expect(userManagementApi.updateGroup).toHaveBeenCalledWith("group-1", {
        name: "総務・人事部",
        parent_group_id: "group-parent",
        status: "inactive",
      }),
    );
    await waitFor(() =>
      expect(notify.success).toHaveBeenCalledWith("グループを保存しました"),
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
          includes_descendants: false,
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
    // 区分は表示名にし、内部コード(member)は出さない。
    expect(screen.getByText(/yamada@example.com \/ 所属$/)).toBeInTheDocument();
    expect(screen.queryByText(/member/)).not.toBeInTheDocument();

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

  it("shows 422 field errors under each field of the update form exactly once", async () => {
    vi.spyOn(userManagementApi, "updateGroup").mockRejectedValue(
      new ApiError(422, "名称は必須です。", {
        name: ["名称は必須です。"],
        parent_group_id: ["親グループが正しくありません。"],
        status: ["状態が正しくありません。"],
        other: ["未知のエラーです。"],
      }),
    );
    renderPage();
    await screen.findByRole("heading", { name: "総務部" });
    await userEvent.type(screen.getByLabelText("名称"), "2");
    await userEvent.click(screen.getByRole("button", { name: "保存" }));

    expect(await screen.findByText("名称は必須です。")).toBeInTheDocument();
    expect(screen.getByText("親グループが正しくありません。")).toBeInTheDocument();
    expect(screen.getByText("状態が正しくありません。")).toBeInTheDocument();
    expect(screen.getByText("未知のエラーです。")).toBeInTheDocument();
    expect(screen.getByText("入力内容を確認してください。")).toBeInTheDocument();
  });

  it("shows 422 field errors under each field of the create form exactly once", async () => {
    vi.spyOn(userManagementApi, "createGroup").mockRejectedValue(
      new ApiError(422, "グループ種別は必須です。", {
        group_type_id: ["グループ種別は必須です。"],
        name: ["名称は必須です。"],
        parent_group_id: ["親グループが正しくありません。"],
        other: ["未知のエラーです。"],
      }),
    );
    renderPage({ path: "/admin/groups/new" });
    await screen.findByRole("heading", { name: "基本情報" });
    await userEvent.selectOptions(screen.getByLabelText("グループ種別"), "1");
    await userEvent.type(screen.getByLabelText("名称"), "新規");
    await userEvent.click(screen.getByRole("button", { name: "作成" }));

    expect(await screen.findByText("グループ種別は必須です。")).toBeInTheDocument();
    expect(screen.getByText("名称は必須です。")).toBeInTheDocument();
    expect(screen.getByText("親グループが正しくありません。")).toBeInTheDocument();
    expect(screen.getByText("未知のエラーです。")).toBeInTheDocument();
    expect(screen.getByText("入力内容を確認してください。")).toBeInTheDocument();
  });

  it("shows 422 field errors of the membership dialog (add, immediate) exactly once", async () => {
    vi.spyOn(userManagementApi, "applyMembershipChangeNow").mockRejectedValue(
      new ApiError(422, "ユーザーが正しくありません。", {
        user_id: ["ユーザーが正しくありません。"],
        note: ["メモが長すぎます。"],
        "items.0.is_primary": ["所属区分が正しくありません。"],
        other: ["未知のエラーです。"],
      }),
    );
    renderPage();
    await userEvent.click(await screen.findByRole("button", { name: "所属を追加" }));
    await userEvent.selectOptions(await screen.findByLabelText("対象ユーザー"), "user-2");
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));

    expect(await screen.findByText("ユーザーが正しくありません。")).toBeInTheDocument();
    expect(screen.getByText("メモが長すぎます。")).toBeInTheDocument();
    expect(screen.getByText("所属区分が正しくありません。")).toBeInTheDocument();
    expect(screen.getByText("未知のエラーです。")).toBeInTheDocument();
    expect(screen.getByText("入力内容を確認してください。")).toBeInTheDocument();
  });
});
