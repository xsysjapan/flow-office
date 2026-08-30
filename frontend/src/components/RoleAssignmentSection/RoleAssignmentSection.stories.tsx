import type { Meta, StoryObj } from "@storybook/react-vite";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import * as access from "../../hooks/useAccessControl";
import type { AccessRole, RoleAssignment } from "../../api/accessControl";
import type { ManagedGroup } from "../../api/userManagement";
import { RoleAssignmentSection } from "./RoleAssignmentSection";

const roles: AccessRole[] = [
  {
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
        allowed_scope_types: ["global", "group"],
      },
    ],
    features: [],
  },
];

const groups: ManagedGroup[] = [
  {
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
      max_primary_memberships: null,
    },
    features: [],
    memberships: [],
    role_assignments: [],
  },
];

const assignments: RoleAssignment[] = [
  {
    id: "assignment-1",
    subject_type: "group",
    subject_id: "group-1",
    role_id: 2,
    scope_type: "group",
    scope_group_id: "group-1",
    include_descendants: false,
    starts_at: null,
    ends_at: null,
    status: "active",
    role: roles[0],
  },
];

function Wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return (
    <QueryClientProvider client={queryClient}>
      <RoleAssignmentSectionWithHooks />
    </QueryClientProvider>
  );
}

function RoleAssignmentSectionWithHooks() {
  const createAssignment = access.useCreateRoleAssignment();
  const updateAssignment = access.useUpdateRoleAssignment();
  const removeAssignment = access.useRemoveRoleAssignment();
  return (
    <RoleAssignmentSection
      mode="pick-group"
      roles={roles}
      groups={groups}
      assignments={assignments}
      fixedRoleId={2}
      createAssignment={createAssignment}
      updateAssignment={updateAssignment}
      removeAssignment={removeAssignment}
    />
  );
}

const meta = {
  title: "Components/RoleAssignmentSection",
  component: Wrapper,
} satisfies Meta<typeof Wrapper>;

export default meta;
type Story = StoryObj<typeof meta>;

export const PickGroup: Story = {};
