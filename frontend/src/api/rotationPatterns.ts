import { apiFetch } from './client'
import type { RotationPattern, RotationPreviewDay } from './types'

export function fetchRotationPatterns(workStyleId?: string): Promise<RotationPattern[]> {
  return apiFetch('/rotation-patterns', { query: { work_style_id: workStyleId } })
}

export interface CreateRotationPatternInput {
  work_style_id: string
  name: string
  items: Array<{ sequence: number; shift_pattern_id: string }>
}

export function createRotationPattern(input: CreateRotationPatternInput): Promise<RotationPattern> {
  return apiFetch('/rotation-patterns', { method: 'POST', body: input })
}

export interface PreviewRotationPatternInput {
  rotation_start_date: string
  rotation_start_position: number
  from: string
  to: string
}

export function previewRotationPattern(
  rotationPatternId: string,
  input: PreviewRotationPatternInput,
): Promise<{ days: RotationPreviewDay[] }> {
  return apiFetch(`/rotation-patterns/${rotationPatternId}/preview`, { method: 'POST', body: input })
}
