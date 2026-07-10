import { useQuery } from '@tanstack/react-query'
import { fetchAuditLog, type AuditLogFilters } from '../api/auditLog'

export function useAuditLog(filters: AuditLogFilters) {
  return useQuery({
    queryKey: ['audit-log', filters],
    queryFn: () => fetchAuditLog(filters),
  })
}
