import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  fetchUser,
  fetchUsers,
  searchUsers,
  updatePaidLeaveAutoGrantEnabled,
  updateSpecialLeaveAutoGrantEnabled,
  updateUserHireDate,
  updateUserTerminationDate,
  updateUserUsageStartDate,
  type UserFilters,
  createUser,
  updateUser,
  type UserProfileInput,
} from "../api/users";

export function useUsers(
  query?: string,
  perPage?: number,
  filters: UserFilters = {},
  page?: number,
) {
  const activeFilters = Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== undefined),
  ) as UserFilters;
  return useQuery({
    queryKey: ["users", query ?? "", perPage ?? "default", activeFilters, page ?? 1],
    queryFn: () => fetchUsers(query, perPage, activeFilters, page),
    placeholderData: keepPreviousData,
  });
}

/**
 * 承認者選択(UserPicker)等、一般社員も使う軽量な検索。
 * `permission`を指定すると、globalスコープで当該Permissionを保有するユーザーのみに絞り込む。
 */
export function useUserSearch(
  query?: string,
  perPage?: number,
  permission?: string,
) {
  return useQuery({
    queryKey: [
      "users",
      "search",
      query ?? "",
      perPage ?? "default",
      permission ?? "",
    ],
    queryFn: () => searchUsers(query, perPage, permission),
    placeholderData: keepPreviousData,
  });
}

export function useUser(id: string) {
  return useQuery({
    queryKey: ["users", "detail", id],
    queryFn: () => fetchUser(id),
    enabled: Boolean(id),
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: UserProfileInput) => createUser(input),
    onSuccess: () =>
      void queryClient.invalidateQueries({ queryKey: ["users"] }),
  });
}

export function useUpdateUser() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: string;
      input: Partial<UserProfileInput>;
    }) => updateUser(id, input),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}

export function useUpdateUserHireDate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, hireDate }: { id: string; hireDate: string }) =>
      updateUserHireDate(id, hireDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}

export function useUpdateUserTerminationDate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      id,
      terminationDate,
    }: {
      id: string;
      terminationDate: string | null;
    }) => updateUserTerminationDate(id, terminationDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}

export function useUpdateUserUsageStartDate() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      id,
      usageStartDate,
    }: {
      id: string;
      usageStartDate: string;
    }) => updateUserUsageStartDate(id, usageStartDate),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}

export function useUpdatePaidLeaveAutoGrantEnabled() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) =>
      updatePaidLeaveAutoGrantEnabled(id, enabled),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}

export function useUpdateSpecialLeaveAutoGrantEnabled() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) =>
      updateSpecialLeaveAutoGrantEnabled(id, enabled),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: ["users"] });
      void queryClient.invalidateQueries({ queryKey: ["users", "detail", id] });
    },
  });
}
