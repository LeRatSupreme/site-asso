'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'

const photoSchema = z.object({
  url: z.string().url('URL invalide'),
  caption: z.string().optional(),
  eventId: z.string(),
})

export async function addPhoto(data: z.infer<typeof photoSchema>) {
  try {
    await requireAdmin()

    const validated = photoSchema.parse(data)

    const photo = await prisma.photo.create({
      data: {
        url: validated.url,
        caption: validated.caption,
        eventId: validated.eventId,
      },
    })

    revalidatePath(`/events/${validated.eventId}`)
    revalidatePath(`/admin/events/${validated.eventId}`)
    revalidatePath('/admin/photos')

    return { success: true, photo }
  } catch (error) {
    console.error('Add photo error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deletePhoto(id: string) {
  try {
    await requireAdmin()

    const photo = await prisma.photo.findUnique({
      where: { id },
    })

    if (!photo) {
      return { success: false, error: 'Photo non trouvée' }
    }

    await prisma.photo.delete({
      where: { id },
    })

    if (photo.eventId) {
      revalidatePath(`/events/${photo.eventId}`)
      revalidatePath(`/admin/events/${photo.eventId}`)
    }
    revalidatePath('/admin/photos')

    return { success: true }
  } catch (error) {
    console.error('Delete photo error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updatePhotoCaption(id: string, caption: string) {
  try {
    await requireAdmin()

    await prisma.photo.update({
      where: { id },
      data: { caption },
    })

    revalidatePath('/admin/photos')

    return { success: true }
  } catch (error) {
    console.error('Update photo caption error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
