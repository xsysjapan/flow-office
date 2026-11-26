import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { BackLink } from "../../components/BackLink/BackLink";
import { Badge } from "../../components/Badge/Badge";
import { Button } from "../../components/Button/Button";
import { Card } from "../../components/Card/Card";
import { ConfirmActionDialog } from "../../components/ConfirmActionDialog/ConfirmActionDialog";
import { DatePicker } from "../../components/DatePicker/DatePicker";
import { DateTimePicker } from "../../components/DateTimePicker/DateTimePicker";
import { EmptyState } from "../../components/EmptyState/EmptyState";
import { ErrorMessage } from "../../components/ErrorMessage/ErrorMessage";
import { FormActions } from "../../components/FormActions/FormActions";
import { FormField } from "../../components/FormField/FormField";
import { FormLayout } from "../../components/FormLayout/FormLayout";
import { FormRow } from "../../components/FormRow/FormRow";
import { FormSection } from "../../components/FormSection/FormSection";
import { PageHeader } from "../../components/PageHeader/PageHeader";
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
import type { UserDetailsInput, UserProfileInput } from "../../api/users";
import type { User } from "../../api/types";
import type { ChangeItem } from "../../api/userManagement";
import {
  useAssignUserWorkStyleForMonth,
  useRemoveUserWorkStyleMonthlyAssignment,
  useUserWorkStyleMonthlyAssignments,
} from "../../hooks/useUserWorkStyleMonthlyAssignments";
import { useUpdateUserDetails, useUser } from "../../hooks/useUsers";
import { useUnsavedChangesGuard } from "../../hooks/useUnsavedChangesGuard";
import { isUserDetailsDirty, type UserDetailsForm } from "./userRoleEditDirty";
import { fieldErrors } from "../../utils/fieldErrors";
import { useWorkStyles } from "../../hooks/useWorkStyles";
import {
  useApplyMembershipChangeNow,
  useCancelMembershipChange,
  useManagedGroups,
  useScheduleMembershipChange,
} from "../../hooks/useUserManagement";
import { formatDate } from "../../utils/weekDates";
import { notify } from "../../lib/notify";
import { ACCOUNT_STATUS_OPTIONS } from "../../utils/userLabels";
import {
  membershipChangeDescription,
  membershipChangeStatusLabel,
} from "../../utils/membershipChangeLabels";

const EXTERNAL_HR_REASON = "外部人事システムが管理する項目のため、ここでは変更できません。";

const DETAILS_ERROR_KEYS = [
  "profile.name",
  "profile.email",
  "profile.employee_number",
  "profile.department",
  "profile.job_title",
  "profile.account_status",
  "hire_date",
  "termination_date",
  "usage_start_date",
  "paid_leave_auto_grant_enabled",
  "special_leave_auto_grant_enabled",
] as const;

type MembershipErrorKey = "effective_at" | "note" | "items.*.to_group_id";

function isExternalHr(user: User, field: string): boolean {
  return (
    user.field_authorities?.some(
      (authority) =>
        authority.field_key === (field === "name" ? "display_name" : field) &&
        authority.authority_type === "EXTERNAL_HR",
    ) ?? false
  );
}

/** 読み込んだ値と画面の値を比べ、変更した項目だけの本文と、保存できない理由を作る。 */
function buildUserDetailsChanges(user: User, form: UserDetailsForm) {
  const input: UserDetailsInput = {};
  let dirty = false;
  const blockers: string[] = [];

  const loadedProfile: Record<string, string> = {
    name: user.name,
    email: user.email ?? "",
    employee_number: user.employee_number ?? "",
    department: user.department ?? "",
    job_title: user.job_title ?? "",
    employment_status: user.employment_status,
    account_status: user.account_status ?? "active",
  };
  const profile: Partial<UserProfileInput> = {};
  for (const [key, value] of Object.entries(form.profile)) {
    if (isExternalHr(user, key) || value === loadedProfile[key]) continue;
    dirty = true;
    if ((key === "name" || key === "email") && !value) {
      blockers.push("氏名とメールアドレスを入力してください。");
      continue;
    }
    const nullable = ["employee_number", "department", "job_title"].includes(key);
    (profile as Record<string, string | null>)[key] =
      nullable && !value ? null : value;
  }
  if (Object.keys(profile).length > 0) input.profile = profile;

  if (!isExternalHr(user, "hire_date") && form.hireDate !== (user.hire_date ?? "")) {
    dirty = true;
    if (form.hireDate) input.hire_date = form.hireDate;
    else blockers.push("入社日を入力してください。");
  }
  if (
    !isExternalHr(user, "termination_date") &&
    form.terminationDate !== (user.termination_date ?? "")
  ) {
    dirty = true;
    input.termination_date = form.terminationDate || null;
  }
  if (
    !isExternalHr(user, "usage_start_date") &&
    form.usageStartDate !== (user.usage_start_date ?? "")
  ) {
    dirty = true;
    if (form.usageStartDate) input.usage_start_date = form.usageStartDate;
    else blockers.push("利用開始日を入力してください。");
  }
  if (
    form.paidLeaveAutoGrantEnabled !== (user.paid_leave_auto_grant_enabled ?? true)
  ) {
    dirty = true;
    input.paid_leave_auto_grant_enabled = form.paidLeaveAutoGrantEnabled;
  }
  if (
    form.specialLeaveAutoGrantEnabled !==
    (user.special_leave_auto_grant_enabled ?? true)
  ) {
    dirty = true;
    input.special_leave_auto_grant_enabled = form.specialLeaveAutoGrantEnabled;
  }
  return { input, dirty, blockers: [...new Set(blockers)] };
}

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

  const updateDetails = useUpdateUserDetails();
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

  // 比べる元(読み込んだ値。保存に成功したら送った値)。
  const [base, setBase] = useState<UserDetailsForm | null>(null);
  const currentForm: UserDetailsForm = {
    profile,
    hireDate,
    terminationDate,
    usageStartDate,
    paidLeaveAutoGrantEnabled,
    specialLeaveAutoGrantEnabled,
  };
  const changes = user ? buildUserDetailsChanges(user, currentForm) : null;
  const dirty = isInitialized && base !== null && isUserDetailsDirty(base, currentForm);
  useUnsavedChangesGuard(dirty);

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
      const loadedProfile = {
        name: user.name,
        email: user.email ?? "",
        employee_number: user.employee_number ?? "",
        department: user.department ?? "",
        job_title: user.job_title ?? "",
        employment_status: user.employment_status,
        account_status: user.account_status ?? "active",
      };
      setProfile(loadedProfile);
      setBase({
        profile: loadedProfile,
        hireDate: user.hire_date ?? "",
        terminationDate: user.termination_date ?? "",
        usageStartDate: user.usage_start_date ?? "",
        paidLeaveAutoGrantEnabled: user.paid_leave_auto_grant_enabled ?? true,
        specialLeaveAutoGrantEnabled:
          user.special_leave_auto_grant_enabled ?? true,
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

  const top = (
    <>
      <BackLink className="mb-0" />
      <PageHeader description="利用者の基本情報・所属・休暇の自動付与・今月の働き方を確認し、更新できます。" />
    </>
  );
  if (isLoadingUser)
    return (
      <div className="space-y-4">
        {top}
        <LoadingState />
      </div>
    );
  if (userError)
    return (
      <div className="space-y-4">
        {top}
        <ErrorMessage
          error={userError}
          fallback="ユーザーの取得に失敗しました。"
        />
      </div>
    );
  if (!user) return <div className="space-y-4">{top}</div>;

  const handleSaveWorkStyle = () => {
    if (workStyleMode === "default") {
      if (currentAssignment) {
        removeWorkStyleAssignment.mutate(
          { id: currentAssignment.id, userId },
          { onSuccess: () => notify.success("働き方を会社のデフォルトに戻しました") },
        );
      }
      return;
    }
    if (!selectedWorkStyleId) return;
    assignWorkStyleForMonth.mutate(
      {
        user_id: userId,
        year_month: currentYearMonth,
        work_style_id: selectedWorkStyleId,
      },
      { onSuccess: () => notify.success("働き方を保存しました") },
    );
  };

  const isExternalHrField = (field: string) => isExternalHr(user, field);
  const { of: detailsError, rest: detailsSubmitError } = fieldErrors(
    updateDetails.error,
    DETAILS_ERROR_KEYS,
  );
  // 描かれる項目と同じ条件でキーを組み立てる(変更先グループは追加・移動のとき、適用日時は予約のときだけ)。
  const membershipMutationError =
    membershipChange.timing === "scheduled"
      ? scheduleMembershipChange.error
      : applyMembershipChangeNow.error;
  const membershipFields: MembershipErrorKey[] = ["note"];
  if (["add", "replace"].includes(membershipChange.operation)) {
    membershipFields.push("items.*.to_group_id");
  }
  if (membershipChange.timing === "scheduled") membershipFields.push("effective_at");
  const {
    of: membershipOf,
    ofRow: membershipOfRow,
    rest: membershipRest,
  } = fieldErrors(membershipMutationError, membershipFields);
  const { of: workStyleOf, rest: workStyleRest } = fieldErrors(
    assignWorkStyleForMonth.error,
    workStyleMode === "specify" ? (["work_style_id"] as const) : ([] as const),
  );
  const externalDescription = (field: string) =>
    isExternalHrField(field) ? EXTERNAL_HR_REASON : undefined;

  const handleSaveDetails = () => {
    if (!changes) return;
    updateDetails.mutate(
      { id: userId, input: changes.input },
      {
        onSuccess: () => {
          setBase(currentForm);
          notify.success("利用者の情報を保存しました");
        },
      },
    );
  };

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
    const isScheduled = membershipChange.timing === "scheduled";
    mutation.mutate(input, {
      onSuccess: () => {
        setMembershipChangeOpen(false);
        notify.success(isScheduled ? "所属変更を予約しました" : "所属を変更しました");
      },
    });
  };

  return (
    <div className="space-y-4">
      {top}
      <Card title={user.name}>
        <FormLayout className="mb-6">
          <FormSection title="基本情報">
            <FormField
              label={`氏名${isExternalHrField("name") ? "（外部HR管理）" : ""}`}
              htmlFor="user-profile-name"
              description={externalDescription("name")}
              error={detailsError("profile.name")}
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
              description={externalDescription("email")}
              error={detailsError("profile.email")}
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
              description={externalDescription("employee_number")}
              error={detailsError("profile.employee_number")}
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
              description={externalDescription("department")}
              error={detailsError("profile.department")}
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
              description={externalDescription("job_title")}
              error={detailsError("profile.job_title")}
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
              description={externalDescription("account_status")}
              error={detailsError("profile.account_status")}
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
          </FormSection>
        </FormLayout>
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
                    onConfirm={() =>
                      cancelMembershipChange
                        .mutateAsync(set.id)
                        .then(() => notify.success("所属変更の予約を取り消しました"))
                    }
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

            {membershipRest != null && <ErrorMessage error={membershipRest} />}

            <div>
              {membershipChange.from_group_id && (
                <div className="mb-4 rounded bg-muted p-3 text-sm">
                  対象:{" "}
                  {user.memberships?.find(
                    (membership) =>
                      membership.group.id === membershipChange.from_group_id,
                  )?.group.name ?? "-"}
                </div>
              )}
              {["add", "replace"].includes(membershipChange.operation) && (
                <FormField
                  label="変更先グループ"
                  htmlFor="membership-change-to"
                  error={membershipOfRow("items", 0, "to_group_id")}
                >
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
                <p className="mb-4 rounded bg-muted p-3 text-sm text-muted-foreground">
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
                  error={membershipOf("effective_at")}
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
              <FormField label="メモ" htmlFor="membership-change-note" error={membershipOf("note")}>
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

        <FormLayout className="mt-8">
          <FormSection title="在籍・利用期間">
            <FormRow>
              <FormField
                label="入社日(有給の自動付与に使用)"
                htmlFor="user-role-edit-hire-date"
                description={externalDescription("hire_date")}
                error={detailsError("hire_date")}
              >
                <DatePicker
                  id="user-role-edit-hire-date"
                  disabled={isExternalHrField("hire_date")}
                  value={hireDate || undefined}
                  onChange={(date) => setHireDate(date ?? "")}
                />
              </FormField>
              <FormField
                label="退社日(未設定なら在籍中)"
                htmlFor="user-role-edit-termination-date"
                description={externalDescription("termination_date")}
                error={detailsError("termination_date")}
              >
                <DatePicker
                  id="user-role-edit-termination-date"
                  disabled={isExternalHrField("termination_date")}
                  min={hireDate || undefined}
                  value={terminationDate || undefined}
                  onChange={(date) => setTerminationDate(date ?? "")}
                />
              </FormField>
            </FormRow>
            <div className="mt-4">
              <FormField
                label="利用開始日(勤怠提出フォロー等の各種フォロー通知の起算日)"
                htmlFor="user-role-edit-usage-start-date"
                description={externalDescription("usage_start_date")}
                error={detailsError("usage_start_date")}
              >
                <DatePicker
                  id="user-role-edit-usage-start-date"
                  disabled={isExternalHrField("usage_start_date")}
                  value={usageStartDate || undefined}
                  onChange={(date) => setUsageStartDate(date ?? "")}
                />
              </FormField>
            </div>
          </FormSection>
        </FormLayout>

        <FormLayout className="mt-8">
          <FormSection title="休暇の自動付与">
            <div className="mb-4 flex flex-col gap-2">
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
            {detailsError("paid_leave_auto_grant_enabled") && (
              <p className="mb-4 text-xs text-destructive">
                {detailsError("paid_leave_auto_grant_enabled")}
              </p>
            )}
            {detailsError("special_leave_auto_grant_enabled") && (
              <p className="mb-4 text-xs text-destructive">
                {detailsError("special_leave_auto_grant_enabled")}
              </p>
            )}
            {changes && changes.blockers.length > 0 && (
              <p className="mb-2 text-xs text-muted-foreground">
                {changes.blockers.join("")}
              </p>
            )}
            {detailsSubmitError != null && <ErrorMessage error={detailsSubmitError} />}
            <FormActions>
              <Button
                isLoading={updateDetails.isPending}
                disabled={!dirty || (changes?.blockers.length ?? 0) > 0}
                onClick={handleSaveDetails}
              >
                保存
              </Button>
            </FormActions>
          </FormSection>
        </FormLayout>

        <FormLayout className="mt-8">
          <FormSection title={`働き方(${currentYearMonth})`}>
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
                <>
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
                  {workStyleOf("work_style_id") && (
                    <p className="text-xs text-destructive">
                      {workStyleOf("work_style_id")}
                    </p>
                  )}
                </>
              )}
            </div>
            {workStyleMode === "specify" && !selectedWorkStyleId && (
              <p className="mb-2 text-xs text-muted-foreground">
                指定する働き方を選択してください。
              </p>
            )}

            {workStyleRest != null && <ErrorMessage error={workStyleRest} />}
            {removeWorkStyleAssignment.error && (
              <ErrorMessage error={removeWorkStyleAssignment.error} />
            )}
            <FormActions>
              <Button
                variant="secondary"
                isLoading={
                  assignWorkStyleForMonth.isPending ||
                  removeWorkStyleAssignment.isPending
                }
                disabled={workStyleMode === "specify" && !selectedWorkStyleId}
                onClick={handleSaveWorkStyle}
              >
                働き方を保存
              </Button>
            </FormActions>
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
          </FormSection>
        </FormLayout>

        <div className="mt-6 border-t border-border pt-4">
          <AttendanceSubmissionReminderExclusionPanel userId={userId} />
        </div>
      </Card>
    </div>
  );
}
