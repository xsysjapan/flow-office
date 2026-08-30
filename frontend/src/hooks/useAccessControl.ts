import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import * as api from "../api/accessControl";

const useAccessMutation = <T>(mutationFn: (input: T) => Promise<unknown>) => {
  const q = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: async () => {
      await q.invalidateQueries({ queryKey: ["access"] });
      await q.invalidateQueries({ queryKey: ["user-management"] });
    },
  });
};
export const useEffectiveAccess = () =>
  useQuery({ queryKey: ["access", "me"], queryFn: api.fetchEffectiveAccess });
export const useFeatures = (enabled = true) =>
  useQuery({
    queryKey: ["access", "features"],
    queryFn: api.fetchFeatures,
    enabled,
  });
export const usePermissions = (enabled = true) =>
  useQuery({
    queryKey: ["access", "permissions"],
    queryFn: api.fetchPermissions,
    enabled,
  });
export const useAccessRoles = (enabled = true) =>
  useQuery({
    queryKey: ["access", "roles"],
    queryFn: api.fetchAccessRoles,
    enabled,
  });
export const useRoleAssignments = (enabled = true) =>
  useQuery({
    queryKey: ["access", "role-assignments"],
    queryFn: api.fetchRoleAssignments,
    enabled,
  });
export const useFeatureSuspensions = (enabled = true) =>
  useQuery({
    queryKey: ["access", "feature-suspensions"],
    queryFn: api.fetchFeatureSuspensions,
    enabled,
  });
export const useCreateRole = () => useAccessMutation(api.createRole);
export const useCloneRole = () =>
  useAccessMutation(
    ({
      id,
      input,
    }: {
      id: number;
      input: Parameters<typeof api.cloneRole>[1];
    }) => api.cloneRole(id, input),
  );
export const useUpdateRole = () =>
  useAccessMutation(
    ({
      id,
      input,
    }: {
      id: number;
      input: Parameters<typeof api.updateRole>[1];
    }) => api.updateRole(id, input),
  );
export const useCreateRoleAssignment = () =>
  useAccessMutation(api.createRoleAssignment);
export const useRemoveRoleAssignment = () =>
  useAccessMutation(api.removeRoleAssignment);
export const useUpdateRoleAssignment = () =>
  useAccessMutation(
    ({
      id,
      input,
    }: {
      id: string;
      input: Parameters<typeof api.updateRoleAssignment>[1];
    }) => api.updateRoleAssignment(id, input),
  );
export const useSuspendUserFeature = () =>
  useAccessMutation(api.suspendUserFeature);
export const useRemoveFeatureSuspension = () =>
  useAccessMutation(api.removeFeatureSuspension);
export const useUpdateRolePermissions = () =>
  useAccessMutation(
    ({ roleId, permissionIds }: { roleId: number; permissionIds: number[] }) =>
      api.updateRolePermissions(roleId, permissionIds),
  );
export const useUpdateRoleFeatures = () =>
  useAccessMutation(
    ({ roleId, featureIds }: { roleId: number; featureIds: number[] }) =>
      api.updateRoleFeatures(roleId, featureIds),
  );
