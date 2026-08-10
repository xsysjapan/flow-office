import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import type { Paginated, User } from "../../api/types";
import type { GroupType, ManagedGroup } from "../../api/userManagement";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import { GroupDetailPage } from "./GroupDetailPage";

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

function renderPage() {
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
