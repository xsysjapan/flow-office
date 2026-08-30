import {
  Calendar,
  Settings,
  Users,
  Workflow,
  type LucideIcon,
} from "lucide-react";
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
}

export const adminNavGroups: AdminNavGroup[] = [
  {
    label: "人事・組織",
    icon: Users,
    items: [
      {
        to: "/admin/users",
        label: "ユーザー",
        description: "社員・協力会社社員のアカウントと基本情報を管理する",
        feature: "administration.users",
        permission: "user.view",
      },
      {
        to: "/admin/groups",
        label: "グループ",
        description: "組織グループと所属メンバーを管理する",
        feature: "administration.users",
        permission: "group.view",
      },
      {
        to: "/admin/membership-changes",
        label: "所属変更",
        description: "所属変更の予約と適用状況を管理する",
        feature: "administration.users",
        permission: "group.change.schedule",
      },
      {
        to: "/admin/hr-import",
        label: "人事データ連携",
        description: "外部HR CSVの差分確認と取込を管理する",
        feature: "administration.users",
        permission: "external_hr.import",
      },
    ],
  },
  {
    label: "勤怠設定",
    icon: Calendar,
    items: [
      {
        to: "/admin/work-calendars",
        label: "会社カレンダー",
        description: "休日・稼働日カレンダーを管理する",
        feature: "attendance.entry",
        permission: "attendance.manage",
      },
      {
        to: "/admin/work-styles",
        label: "勤務形態",
        description: "勤務形態(所定労働時間・労働時間制)を管理する",
        feature: "attendance.entry",
        permission: "attendance.manage",
      },
      {
        to: "/admin/calendar-bulk-operations",
        label: "一括操作",
        description: "複数従業員の予定を一括で適用・取消する",
        feature: "attendance.entry",
        permission: "attendance.manage",
      },
      {
        to: "/admin/shifts",
        label: "シフト",
        description: "シフトパターン・ローテーション・シフト生成を管理する",
        feature: "attendance.entry",
        permission: "attendance.manage",
      },
      {
        to: "/admin/paid-leave",
        label: "有給設定",
        description: "有給の付与・消化ルールを管理する",
        feature: "paid_leave.requests",
        permission: "leave.manage",
      },
      {
        to: "/admin/special-leave",
        label: "特別休暇設定",
        description: "特別休暇の種類・付与ルールを管理する",
        feature: "paid_leave.requests",
        permission: "leave.manage",
      },
      {
        to: "/admin/compensatory-leave",
        label: "代休設定",
        description: "休日出勤の実績日を指定して代休を手動付与する",
        feature: "paid_leave.requests",
        permission: "leave.manage",
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
        label: "勤怠データ出力",
        description: "給与計算連携用のCSV、確認・提出用のExcelを出力する",
        feature: "attendance.timesheet",
        permission: "attendance.export",
      },
    ],
  },
  {
    label: "ワークフロー設定",
    icon: Workflow,
    items: [
      {
        to: "/admin/request-types",
        label: "申請種別",
        description: "申請フォームと承認ルートを管理する",
        feature: "workflow.requests",
        permission: "request_type.manage",
      },
    ],
  },
  {
    label: "経費精算設定",
    icon: Workflow,
    items: [
      {
        to: "/admin/expense-categories",
        label: "経費区分",
        description: "経費区分ごとの証憑要件・承認省略ルールを管理する",
        feature: "backoffice.expenses",
        permission: "expense_category.manage",
      },
    ],
  },
  {
    label: "システム",
    icon: Settings,
    items: [
      {
        to: "/admin/access/assignments",
        label: "ロール割当",
        description: "ユーザー・グループへのRole割当とFeatureの個別停止を管理する",
        feature: "administration.users",
        permissions: ["feature.view", "role.view"],
      },
      {
        to: "/admin/access/roles",
        label: "ロール定義",
        description: "RoleごとのPermission・Feature構成を管理する",
        feature: "administration.users",
        permissions: ["feature.view", "role.view"],
      },
      {
        to: "/admin/identity-settings",
        label: "ID・管理元設定",
        description: "外部ID連携とユーザー項目の管理元を設定する",
        feature: "administration.users",
        permissions: [
          "external_identity.view",
          "external_identity.manage",
          "field_authority.view",
          "field_authority.update",
          "authentication_key.view",
          "authentication_key.manage",
        ],
      },
      {
        to: "/admin/group-types",
        label: "グループ種別",
        description: "グループの分類と所属制約を管理する",
        feature: "administration.settings",
        permissions: [
          "group_type.view",
          "group_type.create",
          "group_type.update",
        ],
      },
      {
        to: "/admin/devices",
        label: "端末管理",
        description: "打刻レコーダー等の共有端末を登録・管理する",
        feature: "administration.settings",
        permission: "device.manage",
      },
      {
        to: "/admin/audit-log",
        label: "監査ログ",
        description: "重要な操作の履歴を確認する",
        feature: "administration.settings",
        permission: "audit_log.view",
      },
      {
        to: "/admin/system-settings",
        label: "システム設定",
        description: "システム全体の設定を管理する",
        feature: "administration.settings",
        permission: "system_settings.read",
      },
      {
        to: "/admin/commands",
        label: "運用コマンド",
        description: "アプリケーション固有の保守処理を実行し、履歴を確認する",
        feature: "administration.settings",
        permission: "admin_command.view",
      },
      {
        to: "/admin/external-integration-connections",
        label: "外部連携設定",
        description: "freee・マネーフォワード等の外部会計サービスとの接続情報を管理する",
        feature: "administration.settings",
        permission: "external_integration_connection.manage",
      },
    ],
  },
];

export function canAccessAdminItem(
  user: User | null | undefined,
  item: AdminNavItem,
): boolean {
  if (item.feature || item.permission || item.permissions) {
    if (
      user?.effective_features === undefined &&
      user?.effective_permissions === undefined
    ) {
      return false;
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
  return true;
}
