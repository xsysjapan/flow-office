import type { Meta, StoryObj } from '@storybook/react-vite'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fn } from 'storybook/test'
import { AuthContext, type AuthContextValue } from '../../auth/AuthContext'
import type { User } from '../../api/types'
import { AppLayout } from './AppLayout'

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
  logout: fn(),
}

const meta = {
  title: 'Components/AppLayout',
  component: AppLayout,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <AuthContext.Provider value={mockAuthValue}>
        <MemoryRouter initialEntries={['/']}>
          <Routes>
            <Route path="/" element={<Story />}>
              <Route index element={<p>今日の勤怠画面がここに表示されます。</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    ),
  ],
} satisfies Meta<typeof AppLayout>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}
