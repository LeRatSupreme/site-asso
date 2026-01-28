'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'

export async function deleteMedia(id: string) {
  try {
    await requireAdmin()

    const media = await prisma.media.findUnique({
      where: { id },
    })

    if (!media) {
      return { success: false, error: 'Média non trouvé' }
    }

    // Supprimer le fichier du stockage (à implémenter selon le service de stockage)
    // await deleteFromStorage(media.url)

    await prisma.media.delete({
      where: { id },
    })

    revalidatePath('/admin/media')

    return { success: true }
  } catch (error) {
    console.error('Delete media error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateMediaAlt(id: string, alt: string) {
  try {
    await requireAdmin()

    await prisma.media.update({
      where: { id },
      data: { alt },
    })

    revalidatePath('/admin/media')

    return { success: true }
  } catch (error) {
    console.error('Update media alt error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
