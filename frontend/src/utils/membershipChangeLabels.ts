import type { ChangeItem } from "../api/userManagement";

export const MEMBERSHIP_CHANGE_STATUS_LABELS: Record<string, string> = {
  draft: "下書き",
  scheduled: "予約済み",
  applied: "適用済み",
  cancelled: "取消済み",
  failed: "失敗",
};

export function membershipChangeStatusLabel(status: string): string {
  return MEMBERSHIP_CHANGE_STATUS_LABELS[status] ?? status;
}

export function membershipChangeDescription(
  item: ChangeItem,
  groups: Array<{ id: string; name: string }> = [],
): string {
  const groupName = (id?: string | null) =>
    groups.find((group) => group.id === id)?.name ?? "-";
  const fromId =
    item.from_group_id ??
    (item.operation === "remove" ? item.target_group_id : null);
  const toId =
    item.to_group_id ??
    (["add", "set_primary"].includes(item.operation)
      ? item.target_group_id
      : null);

  if (item.operation === "add") return `追加: ${groupName(toId)}`;
  if (item.operation === "remove") return `解除: ${groupName(fromId)}`;
  if (item.operation === "replace") {
    return `移動: ${groupName(fromId)} → ${groupName(toId)}`;
  }
  return `主所属変更: ${groupName(toId ?? fromId)}`;
}
