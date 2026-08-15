import { apiFetch } from "./client";
import type { Paginated, User, UserSearchResult } from "./types";

/** user.view Permissionを持つ管理画面向けのユーザー一覧。 */
export interface UserFilters {
  group_id?: string;
  group_type_id?: number;
  external_unlinked?: boolean;
  external_hr?: boolean;
  account_status?: string;
}
export function fetchUsers(
  query?: string,
  perPage?: number,
  filters: UserFilters = {},
  page?: number,
): Promise<Paginated<User>> {
  return apiFetch("/users", {
    query: { q: query, per_page: perPage, page, ...filters },
  });
}

/** 承認者選択(UserPicker)等、一般社員も使う軽量な検索。機微な項目は返らない。 */
export function searchUsers(
  query?: string,
  perPage?: number,
): Promise<Paginated<UserSearchResult>> {
  return apiFetch("/users/search", { query: { q: query, per_page: perPage } });
}

export function fetchUser(id: string): Promise<User> {
  return apiFetch(`/users/${id}`);
}

export interface UserProfileInput {
  name: string;
  email: string | null;
  employee_number: string | null;
  department: string | null;
  job_title: string | null;
  employment_status: string;
  account_status: string;
}

export function createUser(input: UserProfileInput): Promise<User> {
  return apiFetch("/users", { method: "POST", body: input });
}

export function updateUser(
  id: string,
  input: Partial<UserProfileInput>,
): Promise<User> {
  return apiFetch(`/users/${id}`, { method: "PATCH", body: input });
}

/** UC-P002: 有給の自動付与に使う継続勤務期間の基準日として入社日を設定する。 */
export function updateUserHireDate(
  id: string,
  hireDate: string,
): Promise<User> {
  return apiFetch(`/users/${id}/hire-date`, {
    method: "PUT",
    body: { hire_date: hireDate },
  });
}

export function updateUserTerminationDate(
  id: string,
  terminationDate: string | null,
): Promise<User> {
  return apiFetch(`/users/${id}/termination-date`, {
    method: "PUT",
    body: { termination_date: terminationDate },
  });
}

/** 勤怠提出フォロー等の各種フォロー通知の起算日となる利用開始日を設定する。 */
export function updateUserUsageStartDate(
  id: string,
  usageStartDate: string,
): Promise<User> {
  return apiFetch(`/users/${id}/usage-start-date`, {
    method: "PUT",
    body: { usage_start_date: usageStartDate },
  });
}
