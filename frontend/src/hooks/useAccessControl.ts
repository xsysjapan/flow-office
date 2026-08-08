import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import * as api from '../api/accessControl'

const useAccessMutation = <T,>(mutationFn: (input: T) => Promise<unknown>) => { const q=useQueryClient(); return useMutation({mutationFn,onSuccess:async()=>{await q.invalidateQueries({queryKey:['access']});await q.invalidateQueries({queryKey:['user-management']})}}) }
export const useEffectiveAccess = () => useQuery({ queryKey: ['access', 'me'], queryFn: api.fetchEffectiveAccess })
export const useFeatures = () => useQuery({ queryKey: ['access', 'features'], queryFn: api.fetchFeatures })
export const usePermissions = () => useQuery({ queryKey: ['access', 'permissions'], queryFn: api.fetchPermissions })
export const useAccessRoles = () => useQuery({ queryKey: ['access', 'roles'], queryFn: api.fetchAccessRoles })
export const useRoleAssignments = () => useQuery({ queryKey: ['access', 'role-assignments'], queryFn: api.fetchRoleAssignments })
export const useFeatureSuspensions = () => useQuery({ queryKey: ['access', 'feature-suspensions'], queryFn: api.fetchFeatureSuspensions })
export const useCreateRole = () => useAccessMutation(api.createRole)
export const useUpdateRole = () => useAccessMutation(({id,input}:{id:number;input:Parameters<typeof api.updateRole>[1]})=>api.updateRole(id,input))
export const useAssignFeatureToGroup = () => useAccessMutation(({groupId,featureId}:{groupId:string;featureId:number})=>api.assignFeatureToGroup(groupId,featureId))
export const useRemoveFeatureFromGroup = () => useAccessMutation(({groupId,featureId}:{groupId:string;featureId:number})=>api.removeFeatureFromGroup(groupId,featureId))
export const useCreateRoleAssignment = () => useAccessMutation(api.createRoleAssignment)
export const useRemoveRoleAssignment = () => useAccessMutation(api.removeRoleAssignment)
export const useUpdateRoleAssignment = () => useAccessMutation(({id,input}:{id:string;input:Parameters<typeof api.updateRoleAssignment>[1]})=>api.updateRoleAssignment(id,input))
export const useSuspendUserFeature = () => useAccessMutation(api.suspendUserFeature)
export const useRemoveFeatureSuspension = () => useAccessMutation(api.removeFeatureSuspension)
export const useUpdateRolePermissions = () => useAccessMutation(({roleId,permissionIds}:{roleId:number;permissionIds:number[]})=>api.updateRolePermissions(roleId,permissionIds))
