import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createRotationPattern,
  fetchRotationPatterns,
  previewRotationPattern,
  type CreateRotationPatternInput,
  type PreviewRotationPatternInput,
} from '../api/rotationPatterns'

const LIST_KEY = ['rotation-patterns']

export function useRotationPatterns() {
  return useQuery({ queryKey: LIST_KEY, queryFn: () => fetchRotationPatterns() })
}

export function useCreateRotationPattern() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateRotationPatternInput) => createRotationPattern(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY })
    },
  })
}

export function usePreviewRotationPattern() {
  return useMutation({
    mutationFn: ({ rotationPatternId, input }: { rotationPatternId: number; input: PreviewRotationPatternInput }) =>
      previewRotationPattern(rotationPatternId, input),
  })
}
