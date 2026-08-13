import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import * as userWorkStyleMonthlyAssignmentsApi from "../../api/userWorkStyleMonthlyAssignments";
import * as usersApi from "../../api/users";
import * as userManagementApi from "../../api/userManagement";
import * as workStylesApi from "../../api/workStyles";
import type { ManagedGroup } from "../../api/userManagement";
import type {
  User,
  UserWorkStyleMonthlyAssignment,
  WorkStyle,
} from "../../api/types";
import { pickDate, pickDateTime } from "../../test-support/pickerInteractions";
import { formatDate } from "../../utils/weekDates";
import { UserRoleEditPage } from "./UserRoleEditPage";

const targetUser: User = {
  id: "user-1",
  name: "山田太郎",
  email: "yamada@example.com",
  department: "総務部",
  job_title: "主任",
  employment_status: "active",
  last_login_at: null,
};

const managedGroup: ManagedGroup = {
  id: "group-1",
  group_type_id: 1,
  name: "総務部",
  code: "GENERAL_AFFAIRS",
  status: "active",
  parent_group_id: null,
  memberships_count: 0,
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
  features: [],
  memberships: [],
  role_assignments: [],
};

const defaultWorkStyle: WorkStyle = {
  id: "work-style-1",
  code: "standard",
  name: "通常勤務",
  work_time_system: "fixed",
  prescribed_daily_minutes: 480,
  deemed_daily_minutes: null,
  prescribed_weekly_minutes: 2400,
  default_start_time: "09:00",
  default_end_time: "18:00",
  default_break_minutes: 60,
  rounding_unit_minutes: null,
  rounding_mode: null,
  default_break_start_time: "12:00",
  default_break_end_time: "13:00",
  auto_break_enabled: false,
  company_calendar_id: "calendar-1",
  is_shift_based: false,
  is_default: true,
  system_generated: true,
  legal_holiday_rule: "weekly",
  four_week_period_start_date: null,
  max_consecutive_work_days: null,
  settlement_start_day: null,
  core_time_enabled: false,
  core_time_start: null,
  core_time_end: null,
  flexible_time_start: null,
  flexible_time_end: null,
  applied_employee_count: null,
  active_shift_pattern_count: null,
  configuration_warnings: [],
  updated_at: null,
};

const flexWorkStyle: WorkStyle = {
  ...defaultWorkStyle,
  id: "work-style-2",
  code: "flex",
  name: "フレックスタイム制",
  is_default: false,
};

function renderPage(
  user: User,
  {
    workStyles = [defaultWorkStyle, flexWorkStyle],
    workStyleHistory = [],
  }: {
    workStyles?: WorkStyle[];
    workStyleHistory?: UserWorkStyleMonthlyAssignment[];
  } = {},
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  vi.spyOn(usersApi, "fetchUser").mockResolvedValue(user);
  vi.spyOn(userManagementApi, "fetchManagedGroups").mockResolvedValue([
    managedGroup,
  ]);
  vi.spyOn(workStylesApi, "fetchWorkStyles").mockResolvedValue(workStyles);
  vi.spyOn(
    userWorkStyleMonthlyAssignmentsApi,
    "fetchUserWorkStyleMonthlyAssignments",
  ).mockResolvedValue(workStyleHistory);

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/admin/users/${user.id}`]}>
        <Routes>
          <Route path="/admin/users/:id" element={<UserRoleEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("UserRoleEditPage", () => {
  it("does not expose system access management controls", async () => {
    renderPage(targetUser);

    await screen.findByText("山田太郎のユーザー管理");
    expect(screen.queryByText("実効アクセスと付与元")).not.toBeInTheDocument();
    expect(screen.queryByText("直接ロール")).not.toBeInTheDocument();
    expect(screen.queryByText("外部ID・管理元")).not.toBeInTheDocument();
    expect(screen.queryByText("認証キー")).not.toBeInTheDocument();
  });

  it("adds the user to a group", async () => {
    vi.spyOn(userManagementApi, "applyMembershipChangeNow").mockResolvedValue(
      undefined,
    );
    renderPage(targetUser);

    await userEvent.click(
      await screen.findByRole("button", { name: "所属を追加" }),
    );
    await userEvent.selectOptions(
      await screen.findByLabelText("変更先グループ"),
      "group-1",
    );
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));

    await waitFor(() =>
      expect(userManagementApi.applyMembershipChangeNow).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: "user-1",
          items: [
            {
              operation: "add",
              group_type_id: 1,
              from_group_id: null,
              to_group_id: "group-1",
              target_group_id: "group-1",
              is_primary: false,
            },
          ],
        }),
        expect.anything(),
      ),
    );
  });

  it("removes the user from a group at the selected timing", async () => {
    vi.spyOn(userManagementApi, "applyMembershipChangeNow").mockResolvedValue(
      undefined,
    );
    renderPage({
      ...targetUser,
      memberships: [
        {
          id: 10,
          membership_kind: "member",
          is_primary: false,
          group: {
            id: "group-1",
            code: "GENERAL_AFFAIRS",
            name: "総務部",
            group_type: "ORGANIZATION",
            group_type_id: 1,
          },
        },
      ],
    });

    await userEvent.click(await screen.findByRole("button", { name: "解除" }));
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));

    await waitFor(() =>
      expect(userManagementApi.applyMembershipChangeNow).toHaveBeenCalledWith(
        expect.objectContaining({
          user_id: "user-1",
          items: [
            {
              operation: "remove",
              group_type_id: 1,
              from_group_id: "group-1",
              to_group_id: null,
              target_group_id: "group-1",
              is_primary: false,
            },
          ],
        }),
        expect.anything(),
      ),
    );
  });

  it("switches the primary membership with an understandable row action", async () => {
    vi.spyOn(userManagementApi, "applyMembershipChangeNow").mockResolvedValue(
      undefined,
    );
    renderPage({
      ...targetUser,
      memberships: [
        {
          id: 10,
          membership_kind: "member",
          is_primary: false,
          group: {
            id: "group-1",
            code: "GENERAL_AFFAIRS",
            name: "総務部",
            group_type: "ORGANIZATION",
            group_type_name: "組織",
            group_type_id: 1,
          },
        },
        {
          id: 11,
          membership_kind: "primary",
          is_primary: true,
          group: {
            id: "group-primary",
            code: "HEAD_OFFICE",
            name: "本社",
            group_type: "ORGANIZATION",
            group_type_name: "組織",
            group_type_id: 1,
          },
        },
      ],
    });

    await userEvent.click(
      await screen.findByRole("button", { name: "主所属にする" }),
    );
    expect(
      screen.getByText(/同じグループ種別の中で代表として扱う所属/),
    ).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "変更を実行" }));

    await waitFor(() =>
      expect(userManagementApi.applyMembershipChangeNow).toHaveBeenCalledWith(
        expect.objectContaining({
          items: [
            expect.objectContaining({
              operation: "set_primary",
              target_group_id: "group-primary",
              is_primary: false,
            }),
            expect.objectContaining({
              operation: "set_primary",
              target_group_id: "group-1",
              is_primary: true,
            }),
          ],
        }),
        expect.anything(),
      ),
    );
  });

  it("schedules a membership change from the user detail page", async () => {
    vi.spyOn(userManagementApi, "scheduleMembershipChange").mockResolvedValue({
      id: "change-1",
    });
    renderPage(targetUser);
    const user = userEvent.setup();
    await screen.findByText("山田太郎のユーザー管理");

    await user.click(screen.getByRole("button", { name: "所属を追加" }));
    await user.selectOptions(
      await screen.findByLabelText("変更先グループ"),
      "group-1",
    );
    await user.selectOptions(
      screen.getByLabelText("変更タイミング"),
      "scheduled",
    );
    await pickDateTime(user, "適用日時", "00:00", "2027-01-15T09:30");
    await user.click(screen.getByRole("button", { name: "変更を予約" }));

    await waitFor(() =>
      expect(userManagementApi.scheduleMembershipChange).toHaveBeenCalledWith(
        {
          user_id: "user-1",
          effective_at: new Date("2027-01-15T09:30").toISOString(),
          source_type: "manual",
          note: "",
          items: [
            {
              operation: "add",
              group_type_id: 1,
              from_group_id: null,
              to_group_id: "group-1",
              target_group_id: "group-1",
              is_primary: false,
            },
          ],
        },
        expect.anything(),
      ),
    );
  });

  it("shows understandable membership history and cancels a reservation", async () => {
    vi.spyOn(userManagementApi, "cancelMembershipChange").mockResolvedValue(
      undefined,
    );
    renderPage({
      ...targetUser,
      membership_change_sets: [
        {
          id: "change-1",
          effective_at: "2027-01-15T00:30:00.000Z",
          status: "scheduled",
          items: [
            {
              operation: "add",
              group_type_id: 1,
              to_group_id: "group-1",
              target_group_id: "group-1",
              is_primary: false,
            },
          ],
        },
      ],
    });

    expect(await screen.findByText("追加: 総務部")).toBeInTheDocument();
    expect(screen.getByText("予約済み")).toBeInTheDocument();
    expect(screen.queryByText("applied")).not.toBeInTheDocument();
    await userEvent.click(
      screen.getByRole("button", { name: "予約を取り消す" }),
    );
    await userEvent.click(screen.getByRole("button", { name: "取り消す" }));

    await waitFor(() =>
      expect(userManagementApi.cancelMembershipChange).toHaveBeenCalledWith(
        "change-1",
        expect.anything(),
      ),
    );
  });

  it("prefills the hire date when the user already has one", async () => {
    renderPage({ ...targetUser, hire_date: "2024-04-01" });

    expect(
      await screen.findByRole("button", {
        name: "入社日(有給の自動付与に使用)",
      }),
    ).toHaveTextContent("2024-04-01");
  });

  it("saves the entered hire date", async () => {
    vi.spyOn(usersApi, "updateUserHireDate").mockResolvedValue({
      ...targetUser,
      hire_date: "2024-04-01",
    });

    renderPage(targetUser);
    await screen.findByLabelText("入社日(有給の自動付与に使用)");

    await pickDate(
      userEvent.setup(),
      "入社日(有給の自動付与に使用)",
      "2024-04-01",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "入社日を保存する" }),
    );

    await waitFor(() =>
      expect(usersApi.updateUserHireDate).toHaveBeenCalledWith(
        "user-1",
        "2024-04-01",
      ),
    );
  });

  it("prefills and saves the termination date", async () => {
    vi.spyOn(usersApi, "updateUserTerminationDate").mockResolvedValue({
      ...targetUser,
      termination_date: "2026-03-31",
    });
    renderPage({ ...targetUser, termination_date: "2026-03-31" });

    await userEvent.click(
      await screen.findByRole("button", { name: "退社日(未設定なら在籍中)" }),
    );
    await userEvent.click(
      await screen.findByRole("button", { name: "クリア" }),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "退社日を保存する" }),
    );

    await waitFor(() =>
      expect(usersApi.updateUserTerminationDate).toHaveBeenCalledWith(
        "user-1",
        null,
      ),
    );
  });

  it("prefills the usage start date when the user already has one", async () => {
    renderPage({ ...targetUser, usage_start_date: "2026-07-01" });

    expect(
      await screen.findByRole("button", {
        name: "利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)",
      }),
    ).toHaveTextContent("2026-07-01");
  });

  it("saves the entered usage start date", async () => {
    vi.spyOn(usersApi, "updateUserUsageStartDate").mockResolvedValue({
      ...targetUser,
      usage_start_date: "2026-07-01",
    });

    renderPage(targetUser);
    await screen.findByLabelText(
      "利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)",
    );

    await pickDate(
      userEvent.setup(),
      "利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)",
      "2026-07-01",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "利用開始日を保存する" }),
    );

    await waitFor(() =>
      expect(usersApi.updateUserUsageStartDate).toHaveBeenCalledWith(
        "user-1",
        "2026-07-01",
      ),
    );
  });

  it("defaults to using the company default work style when no monthly assignment exists", async () => {
    renderPage(targetUser);

    expect(
      await screen.findByText(
        "働き方(" + formatDate(new Date()).slice(0, 7) + ")",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("radio", { name: /会社のデフォルトを使用/ }),
    ).toBeChecked();
    expect(screen.getByText(/通常勤務/)).toBeInTheDocument();
  });

  it("assigns a specific work style for the current month", async () => {
    vi.spyOn(
      userWorkStyleMonthlyAssignmentsApi,
      "assignUserWorkStyleForMonth",
    ).mockResolvedValue({
      id: "assignment-10",
      user_id: "user-1",
      year_month: formatDate(new Date()).slice(0, 7),
      work_style_id: "work-style-2",
      work_style: {
        id: "work-style-2",
        code: "flex",
        name: "フレックスタイム制",
      },
      assigned_by_user_id: "admin-1",
    });
    renderPage(targetUser);

    await userEvent.click(
      await screen.findByRole("radio", { name: "別の働き方を指定" }),
    );
    await userEvent.selectOptions(
      screen.getByLabelText("指定する働き方"),
      "フレックスタイム制",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "働き方を保存する" }),
    );

    await waitFor(() =>
      expect(
        userWorkStyleMonthlyAssignmentsApi.assignUserWorkStyleForMonth,
      ).toHaveBeenCalledWith({
        user_id: "user-1",
        year_month: formatDate(new Date()).slice(0, 7),
        work_style_id: "work-style-2",
      }),
    );
  });

  it("reverts to the company default by removing the current months assignment", async () => {
    const currentYearMonth = formatDate(new Date()).slice(0, 7);
    vi.spyOn(
      userWorkStyleMonthlyAssignmentsApi,
      "removeUserWorkStyleMonthlyAssignment",
    ).mockResolvedValue(undefined);
    renderPage(targetUser, {
      workStyleHistory: [
        {
          id: "assignment-42",
          user_id: "user-1",
          year_month: currentYearMonth,
          work_style_id: "work-style-2",
          work_style: {
            id: "work-style-2",
            code: "flex",
            name: "フレックスタイム制",
          },
          assigned_by_user_id: "admin-1",
        },
      ],
    });

    expect(
      await screen.findByRole("radio", { name: "別の働き方を指定" }),
    ).toBeChecked();

    await userEvent.click(
      screen.getByRole("radio", { name: /会社のデフォルトを使用/ }),
    );
    await userEvent.click(
      screen.getByRole("button", { name: "働き方を保存する" }),
    );

    await waitFor(() =>
      expect(
        userWorkStyleMonthlyAssignmentsApi.removeUserWorkStyleMonthlyAssignment,
      ).toHaveBeenCalledWith("assignment-42"),
    );
  });
});
