<?php

namespace App\Domain\AccessControl;

/**
 * Feature・Permissionの定義を集約した唯一の情報源。ここに追加するだけで
 * `access-control:sync-catalog`(デプロイ時に自動実行、`AccessControlSeeder`から
 * 初期セットアップ時にも呼ばれる)がDBの`features`・`permissions`・
 * `permission_scope_types`へ反映し、管理画面(アクセス管理)の一覧に現れるようになる。
 * Role(EMPLOYEE等)への初期Permission割当や標準グループへのFeature割当は、
 * 既存の割当を上書きしてしまうため`AccessControlSeeder`側の初回セットアップ専用処理
 * として残し、ここには含めない。
 */
class AccessControlCatalog
{
    /** @var array<string, array{name: string, scopes: list<string>}> */
    public const PERMISSIONS = [
        'user.view' => ['name' => 'ユーザー閲覧', 'scopes' => ['global', 'group', 'self']],
        'user.create' => ['name' => 'ユーザー作成', 'scopes' => ['global', 'group']],
        'user.update' => ['name' => 'ユーザー更新', 'scopes' => ['global', 'group', 'self']],
        'user.disable' => ['name' => 'ユーザー無効化', 'scopes' => ['global', 'group']],
        'group.view' => ['name' => 'グループ閲覧', 'scopes' => ['global', 'group']],
        'group.create' => ['name' => 'グループ作成', 'scopes' => ['global', 'group']],
        'group.update' => ['name' => 'グループ更新', 'scopes' => ['global', 'group']],
        'group.disable' => ['name' => 'グループ無効化', 'scopes' => ['global', 'group']],
        'group.membership.update' => ['name' => '所属更新', 'scopes' => ['global', 'group']],
        'group.change.schedule' => ['name' => '所属変更予約', 'scopes' => ['global', 'group']],
        'group_type.view' => ['name' => 'GroupType閲覧', 'scopes' => ['global']],
        'group_type.create' => ['name' => 'GroupType作成', 'scopes' => ['global']],
        'group_type.update' => ['name' => 'GroupType更新', 'scopes' => ['global']],
        'role.view' => ['name' => 'Role閲覧', 'scopes' => ['global', 'group']],
        'role.create' => ['name' => 'Role作成', 'scopes' => ['global']],
        'role.update' => ['name' => 'Role更新', 'scopes' => ['global']],
        'role.assign' => ['name' => 'Role割当', 'scopes' => ['global', 'group']],
        'feature.view' => ['name' => 'Feature閲覧', 'scopes' => ['global', 'group']],
        'feature.assign' => ['name' => 'Feature割当', 'scopes' => ['global', 'group']],
        'external_identity.view' => ['name' => '外部ID閲覧', 'scopes' => ['global']],
        'external_identity.manage' => ['name' => '外部ID管理', 'scopes' => ['global']],
        'field_authority.view' => ['name' => '項目管理元閲覧', 'scopes' => ['global']],
        'field_authority.update' => ['name' => '項目管理元更新', 'scopes' => ['global']],
        'authentication_key.view' => ['name' => '認証キー閲覧', 'scopes' => ['global']],
        'authentication_key.manage' => ['name' => '認証キー管理', 'scopes' => ['global']],
        'external_hr.import' => ['name' => '外部HR取込', 'scopes' => ['global']],
        'backoffice_task.execute' => ['name' => 'バックオフィスタスク処理', 'scopes' => ['global']],
        'attendance.export' => ['name' => '勤怠出力', 'scopes' => ['global', 'group']],
        'attendance.manage' => ['name' => '勤怠マスタ管理', 'scopes' => ['global']],
        'leave.manage' => ['name' => '休暇マスタ・残数管理', 'scopes' => ['global']],
        'expense.export' => ['name' => '経費出力', 'scopes' => ['global']],
        'expense_preset.manage' => ['name' => '共有経費プリセット管理', 'scopes' => ['global']],
        'request_type.manage' => ['name' => '申請種別管理', 'scopes' => ['global']],
        'expense_category.manage' => ['name' => '経費区分管理', 'scopes' => ['global']],
        'attendance_reminder_exclusion.manage' => ['name' => '勤怠未提出督促除外管理', 'scopes' => ['global']],
        'device.manage' => ['name' => '共有端末管理', 'scopes' => ['global']],
        'external_integration_connection.manage' => ['name' => '外部連携設定管理', 'scopes' => ['global']],
        'audit_log.view' => ['name' => '監査ログ閲覧', 'scopes' => ['global']],
        'audit_log.export' => ['name' => '監査ログ出力', 'scopes' => ['global']],
        'attendance.read' => ['name' => '勤怠閲覧', 'scopes' => ['global', 'group', 'self']],
        'attendance.update' => ['name' => '勤怠更新', 'scopes' => ['global', 'group', 'self']],
        'attendance.month_reopen' => ['name' => '月次勤怠締め取消', 'scopes' => ['global']],
        'attendance.confirmation_revert' => ['name' => '月次勤怠確定取消', 'scopes' => ['global']],
        'approval.execute' => ['name' => '承認実行', 'scopes' => ['global', 'approval_task']],
        'approval.route.change' => ['name' => '承認ルート変更', 'scopes' => ['global', 'group']],
        'system_settings.read' => ['name' => 'システム設定閲覧', 'scopes' => ['global']],
        'system_settings.update' => ['name' => 'システム設定更新', 'scopes' => ['global']],
        'admin_command.view' => ['name' => '運用コマンド閲覧', 'scopes' => ['global']],
        'admin_command.execute' => ['name' => '運用コマンド実行', 'scopes' => ['global']],
        // 旧APIとの移行互換。新しい画面・APIは上記の操作単位Permissionを使用する。
        'user.manage' => ['name' => 'ユーザー管理（互換）', 'scopes' => ['global', 'group']],
    ];

    /** @var array<string, array{0: string, 1: int}> code => [name, display_order] */
    public const FEATURE_PARENTS = [
        'attendance' => ['勤怠', 10],
        'workflow' => ['申請', 20],
        'paid_leave' => ['休暇', 30],
        'backoffice' => ['経費・バックオフィス', 40],
        'administration' => ['管理', 50],
    ];

    /** @var array<string, array{0: string, 1: string, 2: int}> code => [name, parentCode, display_order] */
    public const FEATURE_CHILDREN = [
        'attendance.clock' => ['打刻', 'attendance', 11],
        'attendance.entry' => ['勤怠入力', 'attendance', 12],
        'attendance.timesheet' => ['勤務表・月次提出', 'attendance', 13],
        'workflow.requests' => ['申請', 'workflow', 21],
        'paid_leave.requests' => ['休暇申請', 'paid_leave', 31],
        'backoffice.expenses' => ['経費精算', 'backoffice', 41],
        'backoffice.tasks' => ['バックオフィスタスク', 'backoffice', 42],
        'administration.users' => ['ユーザー・グループ管理', 'administration', 51],
        'administration.settings' => ['システム設定', 'administration', 52],
    ];
}
