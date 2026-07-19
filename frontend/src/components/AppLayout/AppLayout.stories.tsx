import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import type { User } from '../../api/types'
import { AppLayout } from './AppLayout'

const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
queryClient.setQueryData(['special-leave', 'types'], [])

const mockUser: User = {
  id: 1,
  name: '山田 太郎',
  email: 'yamada@example.com',
  department: '開発部',
  job_title: 'エンジニア',
  employment_status: 'active',
  last_login_at: null,
}

const mockAuthValue: AuthContextValue = {
  user: mockUser,
  status: 'authenticated',
  login: fn(),
  completeLogin: fn(),
  applySession: fn(),
  logout: fn(),
}

const adminAuthValue: AuthContextValue = {
  ...mockAuthValue,
  user: { ...mockUser, roles: ['admin'] },
}

const meta = {
  title: 'Components/AppLayout',
  component: AppLayout,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={mockAuthValue}>
          <MemoryRouter initialEntries={['/']}>
            <Routes>
              <Route path="/" element={<Story />}>
                <Route index element={<p>今日の勤怠画面がここに表示されます。</p>} />
              </Route>
            </Routes>
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>
    ),
  ],
} satisfies Meta<typeof AppLayout>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const AsAdmin: Story = {
  decorators: [(Story) => <AuthContext.Provider value={adminAuthValue}>{<Story />}</AuthContext.Provider>],
}
