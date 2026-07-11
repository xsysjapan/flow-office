import { Calendar, Settings, Users, Workflow, type LucideIcon } from 'lucide-react'
import { ROLE, type RoleCode } from '../../utils/roles'

export interface AdminNavItem {
  to: string
  label: string
  description: string
}

export interface AdminNavGroup {
  label: string
  icon: LucideIcon
  items: AdminNavItem[]
  /** 未指定なら管理メニューにアクセスできる全ユーザーに表示する。 */
  roles?: RoleCode[]
}

export const adminNavGroups: AdminNavGroup[] = [
  {
    label: '人事・組織',
    icon: Users,
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [
      { to: '/admin/users', label: 'ユーザー・権限', description: '社員のアカウントと権限ロールを管理する' },
    ],
  },
  {
    label: '勤怠設定',
    icon: Calendar,
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [
      { to: '/admin/work-calendars', label: 'カレンダー', description: '休日・稼働日カレンダーを管理する' },
      { to: '/admin/work-styles', label: '勤務形態・シフト', description: '勤務形態とシフトパターンを管理する' },
      { to: '/admin/paid-leave', label: '有給ルール', description: '有給の付与・消化ルールを管理する' },
      {
        to: '/admin/attendance-export',
        label: '勤怠CSV出力',
        description: '給与計算連携用の勤怠CSVを出力する',
      },
    ],
  },
  {
    label: 'ワークフロー設定',
    icon: Workflow,
    roles: [ROLE.ADMIN],
    items: [{ to: '/admin/request-types', label: '申請種別', description: '申請フォームと承認ルートを管理する' }],
  },
  {
    label: 'システム',
    icon: Settings,
    roles: [ROLE.ADMIN],
    items: [
      { to: '/admin/audit-log', label: '監査ログ', description: '重要な操作の履歴を確認する' },
      { to: '/admin/system-settings', label: 'システム設定', description: 'システム全体の設定を管理する' },
    ],
  },
]
