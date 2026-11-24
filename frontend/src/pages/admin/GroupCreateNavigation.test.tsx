import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import { GroupDetailPage } from "./GroupDetailPage";
import { UserManagementAccessPage } from "./UserManagementAccessPage";

vi.mock("../../lib/notify", () => ({ notify: { success: vi.fn() } }));

function LocationProbe() {
  const location = useLocation();
  return <div data-testid="pathname">{location.pathname}</div>;
}

const groupType = {
  id: 1,
  code: "ORGANIZATION",
  name: "組織",
  display_order: 0,
  status: "active",
  is_system: true,
  membership_limit_type: "unlimited",
  max_memberships_per_user: null,
  requires_primary_membership: false,
  max_primary_memberships: null,
};

describe("グループ一覧から作成画面への遷移", () => {
  it("一覧の「新規作成」を押すと作成画面に移り、名称の入力欄が出る", async () => {
    vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
      {
        id: "group-1",
        group_type_id: 1,
        name: "総務部",
        code: "GA",
        status: "active",
        parent_group_id: null,
        memberships_count: 0,
        type: groupType,
        features: [],
        memberships: [],
        role_assignments: [],
      },
    ] as never);
    vi.spyOn(userManagementApi, "fetchGroupTypes").mockResolvedValue([
      groupType,
    ] as never);
    vi.spyOn(userManagementApi, "fetchMembershipChangeSets").mockResolvedValue(
      [],
    );
    vi.spyOn(usersApi, "fetchUsers").mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      links: { next: null, prev: null },
    });
    vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([]);
    vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue([]);

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={["/admin/groups"]}>
          <Routes>
            <Route path="/admin/groups" element={<UserManagementAccessPage />} />
            <Route path="/admin/groups/:id" element={<GroupDetailPage />} />
          </Routes>
          <LocationProbe />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByText("総務部");
    const header = within(screen.getByRole("region", { name: "グループ" }));
    await userEvent.click(header.getByRole("link", { name: "新規作成" }));

    expect(await screen.findByLabelText("名称")).toBeInTheDocument();
    expect(screen.getByTestId("pathname")).toHaveTextContent(
      "/admin/groups/new",
    );
  });
});
