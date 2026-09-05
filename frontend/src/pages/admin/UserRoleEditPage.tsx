import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { DatePicker } from "../../components/DatePicker/DatePicker";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormField } from "../../components/FormField/FormField";
import { LoadingState } from "../../components/LoadingState/LoadingState";
import { AttendanceSubmissionReminderExclusionPanel } from "../../components/AttendanceSubmissionReminderExclusionPanel/AttendanceSubmissionReminderExclusionPanel";
import { NativeSelect } from "../../components/ui/native-select";
import { Checkbox } from "../../components/ui/checkbox";
import { Input } from "../../components/ui/input";
import { RadioGroup, RadioGroupItem } from "../../components/ui/radio-group";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import type { UserProfileInput } from "../../api/users";
import type { ChangeItem } from "../../api/userManagement";
import {
  useAssignUserWorkStyleForMonth,
  useRemoveUserWorkStyleMonthlyAssignment,
  useUserWorkStyleMonthlyAssignments,
} from "../../hooks/useUserWorkStyleMonthlyAssignments";
import {
  useUpdatePaidLeaveAutoGrantEnabled,
  useUpdateSpecialLeaveAutoGrantEnabled,
  useUpdateUserHireDate,
  useUpdateUserTerminationDate,
  useUpdateUserUsageStartDate,
  useUpdateUser,
  useUser,
} from "../../hooks/useUsers";
import { useWorkStyles } from "../../hooks/useWorkStyles";
import {
  useApplyMembershipChangeNow,
  useCancelMembershipChange,
  useManagedGroups,
  useScheduleMembershipChange,
} from "../../hooks/useUserManagement";
import { formatDate } from "../../utils/weekDates";
import { ACCOUNT_STATUS_OPTIONS } from "../../utils/userLabels";
import {
  membershipChangeDescription,
  membershipChangeStatusLabel,
} from "../../utils/membershipChangeLabels";

type WorkStyleMode = "default" | "specify";
type MembershipOperation = "add" | "remove" | "replace" | "set_primary";
type MembershipTiming = "now" | "scheduled";

/**
 * UC-M001: ユーザーの人事情報と現在所属を編集する。
 * UC-P002: 有給の自動付与に使う入社日を設定する。
 * 勤怠提出フォロー等の各種フォロー通知の起算日となる利用開始日を設定する。
 * 指示書 13章: 会社のデフォルトを使用するか、別の働き方を指定するかを選択する。
 */
export function UserRoleEditPage() {
  const { id } = useParams<{ id: string }>();
  const userId = id ?? "";
  const {
    data: user,
    isLoading: isLoadingUser,
    error: userError,
  } = useUser(userId);
  const { data: workStyles } = useWorkStyles();
  const { data: workStyleHistory } = useUserWorkStyleMonthlyAssignments(userId);
  const { data: groups } = useManagedGroups();

  const updateHireDate = useUpdateUserHireDate();
  const updateTerminationDate = useUpdateUserTerminationDate();
  const updateUsageStartDate = useUpdateUserUsageStartDate();
  const updatePaidLeaveAutoGrantEnabled = useUpdatePaidLeaveAutoGrantEnabled();
  const updateSpecialLeaveAutoGrantEnabled = useUpdateSpecialLeaveAutoGrantEnabled();
  const updateUser = useUpdateUser();
  const assignWorkStyleForMonth = useAssignUserWorkStyleForMonth();
  const removeWorkStyleAssignment = useRemoveUserWorkStyleMonthlyAssignment();
  const applyMembershipChangeNow = useApplyMembershipChangeNow();
  const scheduleMembershipChange = useScheduleMembershipChange();
  const cancelMembershipChange = useCancelMembershipChange();

  const [hireDate, setHireDate] = useState("");
  const [terminationDate, setTerminationDate] = useState("");
  const [usageStartDate, setUsageStartDate] = useState("");
  const [paidLeaveAutoGrantEnabled, setPaidLeaveAutoGrantEnabled] =
    useState(true);
  const [specialLeaveAutoGrantEnabled, setSpecialLeaveAutoGrantEnabled] =
    useState(true);
  const [isInitialized, setIsInitialized] = useState(false);
  const [membershipChangeOpen, setMembershipChangeOpen] = useState(false);
  const [membershipChange, setMembershipChange] = useState({
    timing: "now" as MembershipTiming,
    effective_at: "",
    operation: "add" as MembershipOperation,
    from_group_id: "",
    to_group_id: "",
    note: "",
  });
  const [profile, setProfile] = useState({
    name: "",
    email: "",
    employee_number: "",
    department: "",
    job_title: "",
    employment_status: "active",
    account_status: "active",
  });

  const currentYearMonth = formatDate(new Date()).slice(0, 7);
  const currentAssignment = workStyleHistory?.find(
    (assignment) => assignment.year_month === currentYearMonth,
  );
  const defaultWorkStyle = workStyles?.find((style) => style.is_default);

  const [workStyleMode, setWorkStyleMode] = useState<WorkStyleMode>("default");
  const [selectedWorkStyleId, setSelectedWorkStyleId] = useState("");

  useEffect(() => {
    if (user && !isInitialized) {
      setHireDate(user.hire_date ?? "");
      setTerminationDate(user.termination_date ?? "");
      setUsageStartDate(user.usage_start_date ?? "");
      setPaidLeaveAutoGrantEnabled(user.paid_leave_auto_grant_enabled ?? true);
      setSpecialLeaveAutoGrantEnabled(
        user.special_leave_auto_grant_enabled ?? true,
      );
      setProfile({
        name: user.name,
        email: user.email ?? "",
        employee_number: user.employee_number ?? "",
        department: user.department ?? "",
        job_title: user.job_title ?? "",
        employment_status: user.employment_status,
        account_status: user.account_status ?? "active",
      });
      setIsInitialized(true);
    }
  }, [user, isInitialized]);

  useEffect(() => {
    if (currentAssignment) {
      setWorkStyleMode("specify");
      setSelectedWorkStyleId(currentAssignment.work_style_id);
    } else {
      setWorkStyleMode("default");
      setSelectedWorkStyleId("");
    }
  }, [currentAssignment]);

  if (isLoadingUser) return <LoadingState />;
  if (userError)
    return (
      <ErrorMessage
        error={userError}
        fallback="ユーザーの取得に失敗しました。"
      />
    );
  if (!user) return null;

  const handleSaveWorkStyle = () => {
    if (workStyleMode === "default") {
      if (currentAssignment) {
        removeWorkStyleAssignment.mutate({ id: currentAssignment.id, userId });
      }
      return;
    }
    if (!selectedWorkStyleId) return;
    assignWorkStyleForMonth.mutate({
      user_id: userId,
      year_month: currentYearMonth,
      work_style_id: selectedWorkStyleId,
    });
  };

  const isExternalHrField = (field: string) =>
    user.field_authorities?.some(
      (authority) =>
        authority.field_key === (field === "name" ? "display_name" : field) &&
        authority.authority_type === "EXTERNAL_HR",
    ) ?? false;

  const openMembershipChange = (
    operation: MembershipOperation,
    fromGroupId = "",
  ) => {
    setMembershipChange({
      timing: "now",
      effective_at: "",
      operation,
      from_group_id: fromGroupId,
      to_group_id: "",
      note: "",
    });
    setMembershipChangeOpen(true);
  };

  const buildMembershipChangeItems = (): ChangeItem[] => {
    const fromMembership = user.memberships?.find(
      (membership) => membership.group.id === membershipChange.from_group_id,
    );
    const toGroup = groups?.find(
      (group) => group.id === membershipChange.to_group_id,
    );

    if (membershipChange.operation === "add" && toGroup) {
      return [
        {
          operation: "add",
          group_type_id: toGroup.group_type_id,
          from_group_id: null,
          to_group_id: toGroup.id,
          target_group_id: toGroup.id,
          is_primary: false,
        },
      ];
    }
    if (membershipChange.operation === "remove" && fromMembership) {
      return [
        {
          operation: "remove",
          group_type_id: fromMembership.group.group_type_id,
          from_group_id: fromMembership.group.id,
          to_group_id: null,
          target_group_id: fromMembership.group.id,
          is_primary: false,
        },
      ];
    }
    if (membershipChange.operation === "replace" && fromMembership && toGroup) {
      return [
        {
          operation: "replace",
          group_type_id: fromMembership.group.group_type_id,
          from_group_id: fromMembership.group.id,
          to_group_id: toGroup.id,
          target_group_id: toGroup.id,
          is_primary: fromMembership.is_primary,
        },
      ];
    }
    if (membershipChange.operation === "set_primary" && fromMembership) {
      const sameTypePrimaryItems: ChangeItem[] = (user.memberships ?? [])
        .filter(
          (membership) =>
            membership.is_primary &&
            membership.group.group_type_id ===
              fromMembership.group.group_type_id &&
            membership.group.id !== fromMembership.group.id,
        )
        .map((membership) => ({
          operation: "set_primary",
          group_type_id: membership.group.group_type_id,
          from_group_id: membership.group.id,
          target_group_id: membership.group.id,
          is_primary: false,
        }));
      return [
        ...sameTypePrimaryItems,
        {
          operation: "set_primary",
          group_type_id: fromMembership.group.group_type_id,
          from_group_id: fromMembership.group.id,
          target_group_id: fromMembership.group.id,
          is_primary: true,
        },
      ];
    }
    return [];
  };

  const submitMembershipChange = () => {
    const items = buildMembershipChangeItems();
    if (items.length === 0) return;
    const input = {
      user_id: userId,
      effective_at:
        membershipChange.timing === "scheduled"
          ? new Date(membershipChange.effective_at).toISOString()
          : new Date().toISOString(),
      source_type: "manual",
      note: membershipChange.note,
      items,
    };
    const mutation =
      membershipChange.timing === "scheduled"
        ? scheduleMembershipChange
        : applyMembershipChangeNow;
    mutation.mutate(input, {
      onSuccess: () => setMembershipChangeOpen(false),
    });
  };

  return (
    <Card title={`${user.name}のユーザー管理`}>
      {updateUser.error && <ErrorMessage error={updateUser.error} />}

      <div className="mb-6 rounded border p-3">
        <h2 className="mb-3 font-medium">基本情報</h2>
        <div className="grid gap-3 md:grid-cols-2">
          <FormField
            label={`氏名${isExternalHrField("name") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-name"
          >
            <Input
              id="user-profile-name"
              disabled={isExternalHrField("name")}
              value={profile.name}
              onChange={(e) => setProfile({ ...profile, name: e.target.value })}
            />
          </FormField>
          <FormField
            label={`メール${isExternalHrField("email") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-email"
          >
            <Input
              id="user-profile-email"
              type="email"
              disabled={isExternalHrField("email")}
              value={profile.email}
              onChange={(e) =>
                setProfile({ ...profile, email: e.target.value })
              }
            />
          </FormField>
          <FormField
            label={`社員番号${isExternalHrField("employee_number") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-number"
          >
            <Input
              id="user-profile-number"
              disabled={isExternalHrField("employee_number")}
              value={profile.employee_number}
              onChange={(e) =>
                setProfile({ ...profile, employee_number: e.target.value })
              }
            />
          </FormField>
          <FormField
            label={`部署${isExternalHrField("department") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-department"
          >
            <Input
              id="user-profile-department"
              disabled={isExternalHrField("department")}
              value={profile.department}
              onChange={(e) =>
                setProfile({ ...profile, department: e.target.value })
              }
            />
          </FormField>
          <FormField
            label={`役職${isExternalHrField("job_title") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-job"
          >
            <Input
              id="user-profile-job"
              disabled={isExternalHrField("job_title")}
              value={profile.job_title}
              onChange={(e) =>
                setProfile({ ...profile, job_title: e.target.value })
              }
            />
          </FormField>
          <FormField
            label={`アカウント状態${isExternalHrField("account_status") ? "（外部HR管理）" : ""}`}
            htmlFor="user-profile-status"
          >
            <NativeSelect
              id="user-profile-status"
              disabled={isExternalHrField("account_status")}
              value={profile.account_status}
              onChange={(e) =>
                setProfile({ ...profile, account_status: e.target.value })
              }
            >
              {ACCOUNT_STATUS_OPTIONS.map((status) => (
                <option key={status.value} value={status.value}>
                  {status.label}
                </option>
              ))}
            </NativeSelect>
          </FormField>
        </div>
        <Button
          variant="secondary"
          isLoading={updateUser.isPending}
          disabled={!profile.name || !profile.email}
          onClick={() => {
            const input = Object.fromEntries(
              Object.entries({
                ...profile,
                employee_number: profile.employee_number || null,
                department: profile.department || null,
                job_title: profile.job_title || null,
              }).filter(([key]) => !isExternalHrField(key)),
            ) as Partial<UserProfileInput>;
            updateUser.mutate({ id: userId, input });
          }}
        >
          基本情報を保存する
        </Button>
        {(!profile.name || !profile.email) && (
          <p className="mt-1.5 text-xs text-muted-foreground">
            氏名とメールアドレスを入力してください。
          </p>
        )}
      </div>
      <div className="mb-6 rounded border p-3">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="font-medium">グループ所属</h2>
            <p className="text-sm text-muted-foreground">
              現在の所属と、予約済みの変更を確認できます。
            </p>
          </div>
          <Button
            variant="secondary"
            onClick={() => openMembershipChange("add")}
          >
            所属を追加
          </Button>
        </div>

        <h3 className="mb-2 text-sm font-semibold">現在の所属</h3>
        {(user.memberships ?? []).length === 0 ? (
          <EmptyState
            title="所属はありません。"
            description="「所属を追加」からグループへの所属を追加できます。"
          />
        ) : (
          <div className="space-y-2">
            {user.memberships?.map((membership) => (
              <div
                key={membership.id}
                className="flex flex-wrap items-center justify-between gap-3 rounded border p-3 text-sm"
              >
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{membership.group.name}</span>
                    {membership.is_primary && <Badge tone="info">主所属</Badge>}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {membership.group.group_type_name ??
                      membership.group.group_type}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {!membership.is_primary && (
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() =>
                        openMembershipChange("set_primary", membership.group.id)
                      }
                    >
                      主所属にする
                    </Button>
                  )}
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                      openMembershipChange("replace", membership.group.id)
                    }
                  >
                    グループを移動
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                      openMembershipChange("remove", membership.group.id)
                    }
                  >
                    解除
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="mt-5 border-t border-border pt-4">
          <h3 className="mb-1 text-sm font-semibold">変更履歴</h3>
          <p className="mb-3 text-xs text-muted-foreground">
            直近20件の即時変更と予約を表示します。
          </p>
          {cancelMembershipChange.error && (
            <ErrorMessage error={cancelMembershipChange.error} />
          )}
          {user.membership_change_sets?.map((set) => (
            <div
              key={set.id}
              className="flex flex-wrap items-start justify-between gap-3 border-t border-border py-2 text-sm"
            >
              <div>
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                  <span>{new Date(set.effective_at).toLocaleString()}</span>
                  <Badge tone={set.status === "applied" ? "success" : "info"}>
                    {membershipChangeStatusLabel(set.status)}
                  </Badge>
                </div>
                <div className="mt-1 text-sm font-medium text-foreground">
                  {set.items.map((item, index) => (
                    <div key={`${item.operation}-${index}`}>
                      {membershipChangeDescription(item, groups)}
                    </div>
                  ))}
                </div>
              </div>
              {["draft", "scheduled"].includes(set.status) && (
                <ConfirmActionDialog
                  triggerLabel="予約を取り消す"
                  triggerVariant="secondary"
                  title="所属変更予約を取り消す"
                  description="この所属変更予約を取り消します。"
                  confirmLabel="取り消す"
                  isPending={cancelMembershipChange.isPending}
                  error={cancelMembershipChange.error}
                  onConfirm={() => cancelMembershipChange.mutateAsync(set.id)}
                />
              )}
            </div>
          ))}
          {(user.membership_change_sets ?? []).length === 0 && (
            <EmptyState title="変更履歴はありません。" />
          )}
        </div>
      </div>

      <Dialog
        open={membershipChangeOpen}
        onOpenChange={setMembershipChangeOpen}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {membershipChange.operation === "add"
                ? "所属を追加"
                : membershipChange.operation === "remove"
                  ? "所属を解除"
                  : membershipChange.operation === "replace"
                    ? "グループを移動"
                    : "主所属を変更"}
            </DialogTitle>
            <DialogDescription>
              即時に反映するか、指定日時に反映するかを選択してください。
            </DialogDescription>
          </DialogHeader>

          {(scheduleMembershipChange.error ||
            applyMembershipChangeNow.error) && (
            <ErrorMessage
              error={
                scheduleMembershipChange.error ?? applyMembershipChangeNow.error
              }
            />
          )}

          <div className="grid gap-3">
            {membershipChange.from_group_id && (
              <div className="rounded bg-muted p-3 text-sm">
                対象:{" "}
                {user.memberships?.find(
                  (membership) =>
                    membership.group.id === membershipChange.from_group_id,
                )?.group.name ?? "-"}
              </div>
            )}
            {["add", "replace"].includes(membershipChange.operation) && (
              <FormField label="変更先グループ" htmlFor="membership-change-to">
                <NativeSelect
                  id="membership-change-to"
                  value={membershipChange.to_group_id}
                  onChange={(event) =>
                    setMembershipChange({
                      ...membershipChange,
                      to_group_id: event.target.value,
                    })
                  }
                >
                  <option value="">グループを選択</option>
                  {groups
                    ?.filter((group) => {
                      if (group.status !== "active") return false;
                      if (
                        user.memberships?.some(
                          (membership) => membership.group.id === group.id,
                        )
                      ) {
                        return false;
                      }
                      if (membershipChange.operation !== "replace") return true;
                      const fromMembership = user.memberships?.find(
                        (membership) =>
                          membership.group.id ===
                          membershipChange.from_group_id,
                      );
                      return (
                        !fromMembership ||
                        group.group_type_id ===
                          fromMembership.group.group_type_id
                      );
                    })
                    .map((group) => (
                      <option key={group.id} value={group.id}>
                        {group.name}（{group.type.name}）
                      </option>
                    ))}
                </NativeSelect>
              </FormField>
            )}
            {membershipChange.operation === "set_primary" && (
              <p className="rounded bg-muted p-3 text-sm text-muted-foreground">
                主所属は、同じグループ種別の中で代表として扱う所属です。現在の主所属がある場合は、このグループへ切り替えます。
              </p>
            )}
            <FormField
              label="変更タイミング"
              htmlFor="membership-change-timing"
            >
              <NativeSelect
                id="membership-change-timing"
                value={membershipChange.timing}
                onChange={(event) =>
                  setMembershipChange({
                    ...membershipChange,
                    timing: event.target.value as MembershipTiming,
                    effective_at: "",
                  })
                }
              >
                <option value="now">即時に反映</option>
                <option value="scheduled">日時を指定して予約</option>
              </NativeSelect>
            </FormField>
            {membershipChange.timing === "scheduled" && (
              <FormField
                label="適用日時"
                htmlFor="membership-change-effective-at"
              >
                <DateTimePicker
                  id="membership-change-effective-at"
                  value={membershipChange.effective_at}
                  onChange={(value) =>
                    setMembershipChange({
                      ...membershipChange,
                      effective_at: value ?? "",
                    })
                  }
                />
              </FormField>
            )}
            <FormField label="メモ" htmlFor="membership-change-note">
              <Input
                id="membership-change-note"
                value={membershipChange.note}
                onChange={(event) =>
                  setMembershipChange({
                    ...membershipChange,
                    note: event.target.value,
                  })
                }
              />
            </FormField>
          </div>

          <DialogFooter>
            <Button
              variant="secondary"
              onClick={() => setMembershipChangeOpen(false)}
            >
              キャンセル
            </Button>
            <div>
              <Button
                disabled={
                  (["add", "replace"].includes(membershipChange.operation) &&
                    !membershipChange.to_group_id) ||
                  (membershipChange.timing === "scheduled" &&
                    !membershipChange.effective_at)
                }
                isLoading={
                  scheduleMembershipChange.isPending ||
                  applyMembershipChangeNow.isPending
                }
                onClick={submitMembershipChange}
              >
                {membershipChange.timing === "scheduled"
                  ? "変更を予約"
                  : "変更を実行"}
              </Button>
              {["add", "replace"].includes(membershipChange.operation) &&
                !membershipChange.to_group_id && (
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    変更先グループを選択してください。
                  </p>
                )}
              {membershipChange.timing === "scheduled" &&
                !membershipChange.effective_at && (
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    適用日時を指定してください。
                  </p>
                )}
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <div className="mt-6 border-t border-border pt-4">
        {updateHireDate.error && <ErrorMessage error={updateHireDate.error} />}
        {updateTerminationDate.error && (
          <ErrorMessage error={updateTerminationDate.error} />
        )}
        {updateHireDate.isSuccess && <Badge tone="success">保存しました</Badge>}
        {updateTerminationDate.isSuccess && (
          <Badge tone="success">保存しました</Badge>
        )}

        <div className="grid gap-3 sm:grid-cols-2">
          <FormField
            label="入社日(有給の自動付与に使用)"
            htmlFor="user-role-edit-hire-date"
          >
            <DatePicker
              id="user-role-edit-hire-date"
              value={hireDate || undefined}
              onChange={(date) => setHireDate(date ?? "")}
            />
          </FormField>
          <FormField
            label="退社日(未設定なら在籍中)"
            htmlFor="user-role-edit-termination-date"
          >
            <DatePicker
              id="user-role-edit-termination-date"
              min={hireDate || undefined}
              value={terminationDate || undefined}
              onChange={(date) => setTerminationDate(date ?? "")}
            />
          </FormField>
        </div>

        <div className="flex flex-wrap items-start gap-2">
          <div>
            <Button
              variant="secondary"
              isLoading={updateHireDate.isPending}
              disabled={!hireDate}
              onClick={() => updateHireDate.mutate({ id: userId, hireDate })}
            >
              入社日を保存する
            </Button>
            {!hireDate && (
              <p className="mt-1.5 text-xs text-muted-foreground">
                入社日を入力してください。
              </p>
            )}
          </div>
          <Button
            variant="secondary"
            isLoading={updateTerminationDate.isPending}
            onClick={() =>
              updateTerminationDate.mutate({
                id: userId,
                terminationDate: terminationDate || null,
              })
            }
          >
            退社日を保存する
          </Button>
        </div>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {updateUsageStartDate.error && (
          <ErrorMessage error={updateUsageStartDate.error} />
        )}
        {updateUsageStartDate.isSuccess && (
          <Badge tone="success">保存しました</Badge>
        )}

        <FormField
          label="利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)"
          htmlFor="user-role-edit-usage-start-date"
        >
          <DatePicker
            id="user-role-edit-usage-start-date"
            value={usageStartDate || undefined}
            onChange={(date) => setUsageStartDate(date ?? "")}
          />
        </FormField>

        <div className="flex gap-2">
          <Button
            variant="secondary"
            isLoading={updateUsageStartDate.isPending}
            disabled={!usageStartDate}
            onClick={() =>
              updateUsageStartDate.mutate({ id: userId, usageStartDate })
            }
          >
            利用開始日を保存する
          </Button>
        </div>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {updatePaidLeaveAutoGrantEnabled.error && (
          <ErrorMessage error={updatePaidLeaveAutoGrantEnabled.error} />
        )}
        {updateSpecialLeaveAutoGrantEnabled.error && (
          <ErrorMessage error={updateSpecialLeaveAutoGrantEnabled.error} />
        )}
        {(updatePaidLeaveAutoGrantEnabled.isSuccess ||
          updateSpecialLeaveAutoGrantEnabled.isSuccess) && (
          <Badge tone="success">保存しました</Badge>
        )}

        <h3 className="mb-3 text-sm font-semibold text-foreground">
          休暇の自動付与
        </h3>

        <div className="mb-3 flex flex-col gap-2">
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-2 text-sm font-medium text-foreground">
              <Checkbox
                id="user-role-edit-paid-leave-auto-grant-enabled"
                checked={paidLeaveAutoGrantEnabled}
                onCheckedChange={(checked) =>
                  setPaidLeaveAutoGrantEnabled(checked === true)
                }
              />
              有給の自動付与を有効にする
            </label>
            {user.paid_leave_auto_grant_enabled === false && (
              <Badge tone="warning">自動付与:無効</Badge>
            )}
          </div>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-2 text-sm font-medium text-foreground">
              <Checkbox
                id="user-role-edit-special-leave-auto-grant-enabled"
                checked={specialLeaveAutoGrantEnabled}
                onCheckedChange={(checked) =>
                  setSpecialLeaveAutoGrantEnabled(checked === true)
                }
              />
              特別休暇の自動付与を有効にする
            </label>
            {user.special_leave_auto_grant_enabled === false && (
              <Badge tone="warning">自動付与:無効</Badge>
            )}
          </div>
        </div>

        <Button
          variant="secondary"
          isLoading={
            updatePaidLeaveAutoGrantEnabled.isPending ||
            updateSpecialLeaveAutoGrantEnabled.isPending
          }
          onClick={() => {
            updatePaidLeaveAutoGrantEnabled.mutate({
              id: userId,
              enabled: paidLeaveAutoGrantEnabled,
            });
            updateSpecialLeaveAutoGrantEnabled.mutate({
              id: userId,
              enabled: specialLeaveAutoGrantEnabled,
            });
          }}
        >
          自動付与設定を保存する
        </Button>
      </div>

      <div className="mt-6 border-t border-border pt-4">
        {assignWorkStyleForMonth.error && (
          <ErrorMessage error={assignWorkStyleForMonth.error} />
        )}
        {removeWorkStyleAssignment.error && (
          <ErrorMessage error={removeWorkStyleAssignment.error} />
        )}
        {(assignWorkStyleForMonth.isSuccess ||
          removeWorkStyleAssignment.isSuccess) && (
          <Badge tone="success">保存しました</Badge>
        )}

        <h3 className="mb-3 text-sm font-semibold text-foreground">
          働き方({currentYearMonth})
        </h3>

        <div className="mb-4 flex flex-col gap-2">
          <RadioGroup
            value={workStyleMode}
            onValueChange={(value) =>
              setWorkStyleMode(value as typeof workStyleMode)
            }
          >
            <label className="flex items-start gap-2 text-sm text-foreground">
              <RadioGroupItem value="default" className="mt-1" />
              <span>
                会社のデフォルトを使用
                {defaultWorkStyle && (
                  <span className="block text-xs text-muted-foreground">
                    {defaultWorkStyle.name}
                    {defaultWorkStyle.default_start_time &&
                    defaultWorkStyle.default_end_time
                      ? `(${defaultWorkStyle.default_start_time}〜${defaultWorkStyle.default_end_time})`
                      : ""}
                  </span>
                )}
              </span>
            </label>

            <label className="flex items-start gap-2 text-sm text-foreground">
              <RadioGroupItem value="specify" className="mt-1" />
              <span>別の働き方を指定</span>
            </label>
          </RadioGroup>

          {workStyleMode === "specify" && (
            <NativeSelect
              aria-label="指定する働き方"
              value={selectedWorkStyleId}
              onChange={(e) => setSelectedWorkStyleId(e.target.value)}
            >
              <option value="">選択してください</option>
              {workStyles?.map((style) => (
                <option key={style.id} value={style.id}>
                  {style.name}
                </option>
              ))}
            </NativeSelect>
          )}
        </div>

        <Button
          variant="secondary"
          isLoading={
            assignWorkStyleForMonth.isPending ||
            removeWorkStyleAssignment.isPending
          }
          disabled={workStyleMode === "specify" && !selectedWorkStyleId}
          onClick={handleSaveWorkStyle}
        >
          働き方を保存する
        </Button>
        {workStyleMode === "specify" && !selectedWorkStyleId && (
          <p className="mt-1.5 text-xs text-muted-foreground">
            指定する働き方を選択してください。
          </p>
        )}

        {(workStyleHistory ?? []).length > 0 && (
          <div className="mt-4">
            <h4 className="mb-2 text-xs font-semibold text-muted-foreground">
              適用履歴
            </h4>
            <ul className="divide-y divide-border text-sm">
              {workStyleHistory?.map((assignment) => (
                <li key={assignment.id} className="py-1 text-foreground">
                  {assignment.year_month}:{" "}
                  {assignment.work_style?.name ?? assignment.work_style_id}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>

      <div className="mt-6 border-t border-border pt-4">
        <AttendanceSubmissionReminderExclusionPanel userId={userId} />
      </div>
    </Card>
  );
}
