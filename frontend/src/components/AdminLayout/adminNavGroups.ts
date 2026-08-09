import {
  Calendar,
  Settings,
  Users,
  Workflow,
  type LucideIcon,
} from "lucide-react";
import { ROLE, type RoleCode } from "../../utils/roles";
import type { User } from "../../api/types";

export interface AdminNavItem {
  to: string;
  label: string;
  description: string;
  feature?: string;
  permission?: string;
  permissions?: string[];
}

export interface AdminNavGroup {
  label: string;
  icon: LucideIcon;
  items: AdminNavItem[];
  /** 未指定なら管理メニューにアクセスできる全ユーザーに表示する。 */
  roles?: RoleCode[];
}

export const adminNavGroups: AdminNavGroup[] = [
  {
    label: "人事・組織",
    icon: Users,
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [
      {
        to: "/admin/users",
        label: "ユーザー・権限",
        description: "社員のアカウントと権限ロールを管理する",
        feature: "administration.users",
        permission: "user.view",
      },
      {
        to: "/admin/access-control",
        label: "ユーザー・グループ・アクセス管理",
        description:
          "ユーザーと所属を中心に、利用機能、Role、Permissionを管理する",
        feature: "administration.users",
        permissions: [
          "user.view",
          "group.view",
          "group.change.schedule",
          "feature.view",
          "role.view",
        ],
      },
    ],
  },
  {
    label: "勤怠設定",
    icon: Calendar,
    roles: [ROLE.ADMIN, ROLE.HR_STAFF],
    items: [
      {
        to: "/admin/work-calendars",
        label: "カレンダー",
        description: "休日・稼働日カレンダーを管理する",
        feature: "attendance.entry",
      },
      {
        to: "/admin/work-styles",
        label: "勤務形態",
        description: "勤務形態(所定労働時間・労働時間制)を管理する",
        feature: "attendance.entry",
      },
      {
        to: "/admin/shifts",
        label: "シフト",
        description: "シフトパターン・ローテーション・シフト生成を管理する",
        feature: "attendance.entry",
      },
      {
        to: "/admin/paid-leave",
        label: "有給ルール",
        description: "有給の付与・消化ルールを管理する",
        feature: "paid_leave.requests",
      },
      {
        to: "/admin/paid-leave/history",
        label: "有給履歴",
        description: "対象社員の有給履歴を確認する",
        feature: "paid_leave.requests",
      },
      {
        to: "/admin/special-leave",
        label: "特別休暇設定",
        description: "特別休暇の種類・付与ルールを管理する",
        feature: "paid_leave.requests",
      },
      {
        to: "/admin/special-leave/history",
        label: "特別休暇履歴",
        description: "対象社員の特別休暇履歴を確認する",
        feature: "paid_leave.requests",
      },
      {
        to: "/admin/attendance",
        label: "勤怠参照",
        description: "対象社員の月次・週次・日次の勤怠を確認する",
        feature: "attendance.entry",
        permission: "attendance.read",
      },
      {
        to: "/admin/attendance-export",
        label: "勤怠CSV出力",
        description: "給与計算連携用の勤怠CSVを出力する",
        feature: "attendance.timesheet",
        permission: "attendance.read",
      },
    ],
  },
  {
    label: "ワークフロー設定",
    icon: Workflow,
    roles: [ROLE.ADMIN],
    items: [
      {
        to: "/admin/request-types",
        label: "申請種別",
        description: "申請フォームと承認ルートを管理する",
        feature: "workflow.requests",
      },
    ],
  },
  {
    label: "経費精算設定",
    icon: Workflow,
    roles: [ROLE.ADMIN, ROLE.ACCOUNTING_STAFF],
    items: [
      {
        to: "/admin/expense-categories",
        label: "経費区分",
        description: "経費区分ごとの証憑要件・承認省略ルールを管理する",
        feature: "backoffice.expenses",
      },
    ],
  },
  {
    label: "システム",
    icon: Settings,
    roles: [ROLE.ADMIN],
    items: [
      {
        to: "/admin/devices",
        label: "端末管理",
        description: "打刻レコーダー等の共有端末を登録・管理する",
        feature: "administration.settings",
      },
      {
        to: "/admin/audit-log",
        label: "監査ログ",
        description: "重要な操作の履歴を確認する",
        feature: "administration.settings",
      },
      {
        to: "/admin/system-settings",
        label: "システム設定",
        description: "システム全体の設定を管理する",
        feature: "administration.settings",
        permission: "system_settings.read",
      },
    ],
  },
];

export function canAccessAdminItem(
  user: User | null | undefined,
  item: AdminNavItem,
  roles?: RoleCode[],
): boolean {
  if (item.feature || item.permission || item.permissions) {
    if (
      user?.effective_features === undefined &&
      user?.effective_permissions === undefined
    ) {
      return !roles || roles.some((role) => user?.roles?.includes(role));
    }
    return (
      (!item.feature ||
        Boolean(user?.effective_features?.includes(item.feature))) &&
      (!item.permission ||
        Boolean(user?.effective_permissions?.includes(item.permission))) &&
      (!item.permissions ||
        item.permissions.every((permission) =>
          user?.effective_permissions?.includes(permission),
        ))
    );
  }
  return !roles || roles.some((role) => user?.roles?.includes(role));
}
