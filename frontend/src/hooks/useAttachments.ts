import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchAttachments, uploadAttachment, type AttachmentOwnerType } from '../api/attachments'

export function useAttachments(ownerType: AttachmentOwnerType, ownerId: number) {
  return useQuery({
    queryKey: ['attachments', ownerType, ownerId],
    queryFn: () => fetchAttachments(ownerType, ownerId),
    enabled: Number.isFinite(ownerId),
  })
}

export function useUploadAttachment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ ownerType, ownerId, file }: { ownerType: AttachmentOwnerType; ownerId: number; file: File }) =>
      uploadAttachment(ownerType, ownerId, file),
    onSuccess: (_data, { ownerType, ownerId }) => {
      void queryClient.invalidateQueries({ queryKey: ['attachments', ownerType, ownerId] })
    },
  })
}
