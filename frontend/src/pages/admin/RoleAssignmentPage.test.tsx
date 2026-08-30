import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import * as userManagementApi from "../../api/userManagement";
import * as usersApi from "../../api/users";
import type {
  AccessRole,
  Feature,
  FeatureSuspension,
  RoleAssignment,
} from "../../api/accessControl";
import type { ManagedGroup } from "../../api/userManagement";
import type { Paginated, User } from "../../api/types";
import { RoleAssignmentPage } from "./RoleAssignmentPage";

const targetUser: User = {
  id: "user-1",
  name: "山田太郎",
  email: "yamada@example.com",
  department: null,
  job_title: null,
  employment_status: "active",
  last_login_at: null,
};

const feature: Feature = {
  id: 1,
  code: "attendance",
  name: "勤怠管理",
  status: "active",
  display_order: 1,
  is_selectable: true,
};

const role: AccessRole = {
  id: 1,
  code: "attendance_manager",
  name: "勤怠管理者",
  description: null,
  status: "active",
  is_system: false,
  permissions: [
    {
      id: 1,
      code: "attendance.approve",
      resource: "attendance",
      action: "approve",
      description: null,
      allowed_scope_types: ["global", "group", "self"],
    },
  ],
  features: [feature],
};

const managedGroup: ManagedGroup = {
  id: "group-1",
  group_type_id: 1,
  name: "総務部",
  code: "GENERAL_AFFAIRS",
  status: "active",
  parent_group_id: null,
  memberships_count: 3,
  type: {
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
  },
  features: [feature],
  memberships: [],
  role_assignments: [],
};

const roleAssignment: RoleAssignment = {
  id: "assignment-1",
  subject_type: "user",
  subject_id: "user-1",
  role_id: 1,
  scope_type: "global",
  scope_group_id: null,
  include_descendants: false,
  starts_at: null,
  ends_at: null,
  status: "active",
  role,
};

const suspension: FeatureSuspension = {
  id: "suspension-1",
  user_id: "user-1",
  feature_id: 1,
  reason: "一時的な利用停止",
  starts_at: null,
  ends_at: null,
  user: { id: "user-1", name: "山田太郎" },
  feature,
};

function renderPage(
  initialPath = "/admin/access/assignments",
  {
    assignments = [],
    suspensions = [],
  }: {
    assignments?: RoleAssignment[];
    suspensions?: FeatureSuspension[];
  } = {},
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
    managedGroup,
  ]);
  vi.spyOn(accessControlApi, "fetchFeatures").mockResolvedValue([feature]);
  vi.spyOn(accessControlApi, "fetchAccessRoles").mockResolvedValue([role]);
  vi.spyOn(accessControlApi, "fetchRoleAssignments").mockResolvedValue(
    assignments,
  );
  vi.spyOn(accessControlApi, "fetchFeatureSuspensions").mockResolvedValue(
    suspensions,
  );

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <RoleAssignmentPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("RoleAssignmentPage", () => {
  it("shows an empty state when no subject is selected", async () => {
    renderPage();

    expect(
      await screen.findByText("ユーザーまたはグループを選択してください。"),
    ).toBeInTheDocument();
  });

  it("shows a user's role assignments and suspension section when a user is selected", async () => {
    renderPage(
      "/admin/access/assignments?subjectType=user&subjectId=user-1",
      { assignments: [roleAssignment], suspensions: [suspension] },
    );

    expect(await screen.findByText("勤怠管理者")).toBeInTheDocument();
    expect(screen.getByText("個別Feature停止")).toBeInTheDocument();
    expect(
      screen.getByText("勤怠管理 (一時的な利用停止)"),
    ).toBeInTheDocument();
  });

  it("shows a group's role assignments and read-only feature list, without a suspension section", async () => {
    const groupAssignment: RoleAssignment = {
      ...roleAssignment,
      id: "assignment-2",
      subject_type: "group",
      subject_id: "group-1",
    };
    renderPage(
      "/admin/access/assignments?subjectType=group&subjectId=group-1",
      { assignments: [groupAssignment] },
    );

    expect(
      await screen.findByRole("heading", { name: "総務部" }),
    ).toBeInTheDocument();
    expect(screen.getByText("勤怠管理者")).toBeInTheDocument();
    expect(screen.getByText("有効なFeature(参照専用)")).toBeInTheDocument();
    expect(screen.getByText("勤怠管理")).toBeInTheDocument();
    expect(screen.queryByText("個別Feature停止")).not.toBeInTheDocument();
  });

  it("creates a role assignment for the selected user with the entered values", async () => {
    const paginatedUsers: Paginated<User> = {
      data: [targetUser],
      meta: { current_page: 1, last_page: 1, total: 1 },
      links: { next: null, prev: null },
    };
    vi.spyOn(usersApi, "searchUsers").mockResolvedValue(paginatedUsers);
    vi.spyOn(accessControlApi, "createRoleAssignment").mockResolvedValue({
      id: "assignment-new",
    });
    renderPage("/admin/access/assignments?subjectType=user");

    await screen.findByLabelText("対象ユーザー");
    await userEvent.click(document.getElementById("subject-user")!);
    await userEvent.type(
      await screen.findByPlaceholderText("氏名またはメールアドレスで検索"),
      "山田",
    );
    await userEvent.click(
      await screen.findByRole("option", {
        name: "山田太郎(yamada@example.com)",
      }),
    );

    await userEvent.click(
      await screen.findByRole("button", { name: "Roleを追加" }),
    );
    await userEvent.selectOptions(
      await screen.findByLabelText("Role"),
      "1",
    );
    await userEvent.selectOptions(
      screen.getByLabelText("対象範囲"),
      "global",
    );
    await userEvent.click(screen.getByRole("button", { name: "追加" }));

    await waitFor(() =>
      expect(accessControlApi.createRoleAssignment).toHaveBeenCalledWith(
        expect.objectContaining({
          subject_type: "user",
          subject_id: "user-1",
          role_id: 1,
          scope_type: "global",
          scope_group_id: null,
          include_descendants: false,
        }),
        expect.anything(),
      ),
    );
  });
});
