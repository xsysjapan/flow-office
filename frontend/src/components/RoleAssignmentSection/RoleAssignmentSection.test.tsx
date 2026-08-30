import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import * as accessControlApi from "../../api/accessControl";
import type { AccessRole, RoleAssignment } from "../../api/accessControl";
import type { ManagedGroup } from "../../api/userManagement";
import * as access from "../../hooks/useAccessControl";
import { RoleAssignmentSection } from "./RoleAssignmentSection";

const role: AccessRole = {
  id: 2,
  code: "backoffice_staff",
  name: "バックオフィス担当者",
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
      allowed_scope_types: ["global"],
    },
  ],
  features: [],
};

const group: ManagedGroup = {
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
    max_primary_memberships: null,
  },
  features: [],
  memberships: [],
  role_assignments: [],
};

function Harness({
  assignments = [] as RoleAssignment[],
  mode = "pick-group" as "pick-group" | "pick-role",
}: {
  assignments?: RoleAssignment[];
  mode?: "pick-group" | "pick-role";
} = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  function Inner() {
    const createAssignment = access.useCreateRoleAssignment();
    const updateAssignment = access.useUpdateRoleAssignment();
    const removeAssignment = access.useRemoveRoleAssignment();
    return (
      <RoleAssignmentSection
        mode={mode}
        roles={[role]}
        groups={[group]}
        assignments={assignments}
        fixedRoleId={mode === "pick-group" ? role.id : undefined}
        fixedGroupId={mode === "pick-role" ? group.id : undefined}
        createAssignment={createAssignment}
        updateAssignment={updateAssignment}
        removeAssignment={removeAssignment}
      />
    );
  }
  return render(
    <QueryClientProvider client={queryClient}>
      <Inner />
    </QueryClientProvider>,
  );
}

describe("RoleAssignmentSection", () => {
  it("shows an empty state when there are no assignments", () => {
    Harness();
    expect(
      screen.getByText("有効なRole割当はまだありません。"),
    ).toBeInTheDocument();
  });

  it("creates a group assignment fixed to the given role (pick-group mode)", async () => {
    vi.spyOn(accessControlApi, "createRoleAssignment").mockResolvedValue({
      id: "new-assignment",
    });
    Harness();

    await userEvent.click(
      screen.getByRole("button", { name: "Roleを割り当てる" }),
    );
    await userEvent.selectOptions(
      screen.getByLabelText("対象グループ"),
      "group-1",
    );
    await userEvent.selectOptions(screen.getByLabelText("対象範囲"), "global");
    await userEvent.click(
      screen.getByRole("button", { name: "割り当てる" }),
    );

    await waitFor(() =>
      expect(accessControlApi.createRoleAssignment).toHaveBeenCalledWith(
        expect.objectContaining({
          subject_type: "group",
          subject_id: "group-1",
          role_id: 2,
          scope_type: "global",
        }),
        expect.anything(),
      ),
    );
  });

  it("creates a role assignment fixed to the given group (pick-role mode)", async () => {
    vi.spyOn(accessControlApi, "createRoleAssignment").mockResolvedValue({
      id: "new-assignment",
    });
    Harness({ mode: "pick-role" });

    await userEvent.click(
      screen.getByRole("button", { name: "Roleを割り当てる" }),
    );
    await userEvent.selectOptions(screen.getByLabelText("Role"), "2");
    await userEvent.selectOptions(screen.getByLabelText("対象範囲"), "global");
    await userEvent.click(
      screen.getByRole("button", { name: "割り当てる" }),
    );

    await waitFor(() =>
      expect(accessControlApi.createRoleAssignment).toHaveBeenCalledWith(
        expect.objectContaining({
          subject_type: "group",
          subject_id: "group-1",
          role_id: 2,
          scope_type: "global",
        }),
        expect.anything(),
      ),
    );
  });

  it("removes an assignment via the confirmation dialog", async () => {
    vi.spyOn(accessControlApi, "removeRoleAssignment").mockResolvedValue(
      undefined,
    );
    Harness({
      assignments: [
        {
          id: "assignment-1",
          subject_type: "group",
          subject_id: "group-1",
          role_id: 2,
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

    await userEvent.click(screen.getByRole("button", { name: "解除" }));
    await userEvent.click(
      screen.getByRole("button", { name: "解除する" }),
    );

    await waitFor(() =>
      expect(accessControlApi.removeRoleAssignment).toHaveBeenCalledWith(
        "assignment-1",
        expect.anything(),
      ),
    );
  });
});
