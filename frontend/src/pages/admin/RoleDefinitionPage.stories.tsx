import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { Meta, StoryObj } from "@storybook/react-vite";
import { MemoryRouter } from "react-router-dom";
import type {
  AccessRole,
  Feature,
  Permission,
  RoleAssignment,
} from "../../api/accessControl";
import { RoleDefinitionPage } from "./RoleDefinitionPage";

const permissions: Permission[] = [
  {
    id: 1,
    code: "attendance.view",
    resource: "attendance",
    action: "view",
    description: "勤怠を閲覧する",
    allowed_scope_types: ["global", "group", "self"],
  },
  {
    id: 2,
    code: "attendance.edit",
    resource: "attendance",
    action: "edit",
    description: "勤怠を編集する",
    allowed_scope_types: ["global", "group"],
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

const roles: AccessRole[] = [
  {
    id: 1,
    code: "employee",
    name: "一般社員",
    description: "全社員に付与される標準ロール",
    status: "active",
    is_system: true,
    permissions: [permissions[0]],
    features: [features[0]],
  },
  {
    id: 2,
    code: "backoffice_staff",
    name: "バックオフィス担当者",
    description: null,
    status: "active",
    is_system: false,
    permissions,
    features,
  },
];

const roleAssignments: RoleAssignment[] = [
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
  },
];

function withSeeded() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { staleTime: Infinity, retry: false } },
  });
  queryClient.setQueryData(["access", "roles"], roles);
  queryClient.setQueryData(["access", "permissions"], permissions);
  queryClient.setQueryData(["access", "features"], features);
  queryClient.setQueryData(["access", "role-assignments"], roleAssignments);

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={["/admin/access/roles?roleId=2"]}>
          <RoleDefinitionPage />
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

const meta = {
  title: "Pages/Admin/RoleDefinitionPage",
  component: RoleDefinitionPage,
} satisfies Meta<typeof RoleDefinitionPage>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {
  render: withSeeded(),
};
