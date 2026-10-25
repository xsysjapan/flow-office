import { apiFetch } from './client'

/** メンテナンス中か(認証不要。包みなし)。 */
export function fetchMaintenanceStatus(): Promise<{ maintenance: boolean }> {
  return apiFetch('/maintenance-status')
}
