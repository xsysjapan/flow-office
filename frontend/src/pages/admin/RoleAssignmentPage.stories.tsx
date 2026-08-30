import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter } from 'react-router-dom'
import type { AccessRole, Feature, FeatureSuspension, RoleAssignment } from '../../api/accessControl'
import type { ManagedGroup } from '../../api/userManagement'
import { RoleAssignmentPage } from './RoleAssignmentPage'

const feature: Feature = {
  id: 1,
  code: 'attendance',
  name: '勤怠管理',
  status: 'active',
  display_order: 1,
  is_selectable: true,
}

const role: AccessRole = {
  id: 1,
  code: 'attendance_manager',
  name: '勤怠管理者',
  description: '勤怠の承認・修正を行う',
  status: 'active',
  is_system: false,
  permissions: [
    {
      id: 1,
      code: 'attendance.approve',
      resource: 'attendance',
      action: 'approve',
      description: null,
      allowed_scope_types: ['global', 'group', 'self'],
    },
  ],
  features: [feature],
}

const managedGroup: ManagedGroup = {
  id: 'group-1',
  group_type_id: 1,
  name: '総務部',
  code: 'GENERAL_AFFAIRS',
  status: 'active',
  parent_group_id: null,
  memberships_count: 3,
  type: {
    id: 1,
    code: 'ORGANIZATION',
    name: '組織',
    display_order: 1,
    status: 'active',
    is_system: true,
    membership_limit_type: 'unlimited',
    max_memberships_per_user: null,
    primary_membership_required: false,
    max_primary_memberships: 1,
  },
  features: [feature],
  memberships: [],
  role_assignments: [],
}

const roleAssignment: RoleAssignment = {
  id: 'assignment-1',
  subject_type: 'user',
  subject_id: 'user-1',
  role_id: 1,
  scope_type: 'global',
  scope_group_id: null,
  include_descendants: false,
  starts_at: null,
  ends_at: null,
  status: 'active',
  role,
}

const groupRoleAssignment: RoleAssignment = {
  ...roleAssignment,
  id: 'assignment-2',
  subject_type: 'group',
  subject_id: 'group-1',
}

const suspension: FeatureSuspension = {
  id: 'suspension-1',
  user_id: 'user-1',
  feature_id: 1,
  reason: '一時的な利用停止',
  starts_at: null,
  ends_at: null,
  user: { id: 'user-1', name: '山田太郎' },
  feature,
}

function withSeeded(initialEntry: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  queryClient.setQueryData(['access', 'features'], [feature])
  queryClient.setQueryData(['access', 'roles'], [role])
  queryClient.setQueryData(['access', 'role-assignments'], [roleAssignment, groupRoleAssignment])
  queryClient.setQueryData(['access', 'feature-suspensions'], [suspension])
  queryClient.setQueryData(['user-management', 'groups'], [managedGroup])

  return function Decorator() {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <RoleAssignmentPage />
        </MemoryRouter>
      </QueryClientProvider>
    )
  }
}

const meta = {
  title: 'Pages/Admin/RoleAssignmentPage',
  component: RoleAssignmentPage,
} satisfies Meta<typeof RoleAssignmentPage>

export default meta
type Story = StoryObj<typeof meta>

export const NoSubjectSelected: Story = {
  render: withSeeded('/admin/access/assignments'),
}

export const UserSelected: Story = {
  render: withSeeded('/admin/access/assignments?subjectType=user&subjectId=user-1'),
}

export const GroupSelected: Story = {
  render: withSeeded('/admin/access/assignments?subjectType=group&subjectId=group-1'),
}
