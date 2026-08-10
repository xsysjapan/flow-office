export const ACCOUNT_STATUS_OPTIONS = [
  { value: "pending", label: "利用開始待ち" },
  { value: "active", label: "有効" },
  { value: "suspended", label: "一時停止" },
  { value: "leave", label: "休職中" },
  { value: "retired", label: "退職済み" },
  { value: "disabled", label: "無効" },
] as const;

const accountStatusLabels = Object.fromEntries(
  ACCOUNT_STATUS_OPTIONS.map((option) => [option.value, option.label]),
) as Record<string, string>;

const employmentStatusLabels: Record<string, string> = {
  active: "在籍中",
  leave: "休職中",
  retired: "退職済み",
};

export function accountStatusLabel(status: string): string {
  return accountStatusLabels[status] ?? status;
}

export function employmentStatusLabel(status: string): string {
  return employmentStatusLabels[status] ?? status;
}
