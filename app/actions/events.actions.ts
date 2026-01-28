'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'

const eventSchema = z.object({
  title: z.string().min(1, 'Le titre est requis'),
  description: z.string().min(1, 'La description est requise'),
  date: z.coerce.date(),
  location: z.string().min(1, 'Le lieu est requis'),
  image: z.string().url().optional().nullable().or(z.literal('')),
  sumupLink: z.string().url().optional().nullable().or(z.literal('')),
  isPublished: z.boolean().default(false),
})

export async function createEvent(data: z.infer<typeof eventSchema>) {
  try {
    await requireAdmin()

    const validated = eventSchema.parse(data)

    const event = await prisma.event.create({
      data: {
        title: validated.title,
        description: validated.description,
        date: validated.date,
        location: validated.location,
        image: validated.image || null,
        sumupLink: validated.sumupLink || null,
        isPublished: validated.isPublished,
      },
    })

    revalidatePath('/admin/events')
    revalidatePath('/events')
    revalidatePath('/')

    return { success: true, event }
  } catch (error) {
    console.error('Create event error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateEvent(id: string, data: z.infer<typeof eventSchema>) {
  try {
    await requireAdmin()

    const validated = eventSchema.parse(data)

    const event = await prisma.event.update({
      where: { id },
      data: {
        title: validated.title,
        description: validated.description,
        date: validated.date,
        location: validated.location,
        image: validated.image || null,
        sumupLink: validated.sumupLink || null,
        isPublished: validated.isPublished,
      },
    })

    revalidatePath('/admin/events')
    revalidatePath(`/admin/events/${id}`)
    revalidatePath('/events')
    revalidatePath(`/events/${id}`)
    revalidatePath('/')

    return { success: true, event }
  } catch (error) {
    console.error('Update event error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deleteEvent(id: string) {
  try {
    await requireAdmin()

    // Vérifier s'il y a des inscriptions
    const registrationsCount = await prisma.eventRegistration.count({
      where: { eventId: id },
    })

    if (registrationsCount > 0) {
      return {
        success: false,
        error: `Impossible de supprimer: ${registrationsCount} inscription(s) existante(s)`,
      }
    }

    await prisma.event.delete({
      where: { id },
    })

    revalidatePath('/admin/events')
    revalidatePath('/events')
    revalidatePath('/')

    return { success: true }
  } catch (error) {
    console.error('Delete event error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function toggleEventPublished(id: string, isPublished: boolean) {
  try {
    await requireAdmin()

    await prisma.event.update({
      where: { id },
      data: { isPublished },
    })

    revalidatePath('/admin/events')
    revalidatePath('/events')
    revalidatePath('/')

    return { success: true }
  } catch (error) {
    console.error('Toggle event published error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function removeEventRegistration(registrationId: string) {
  try {
    await requireAdmin()

    const registration = await prisma.eventRegistration.findUnique({
      where: { id: registrationId },
      include: { event: true }
    })

    if (!registration) {
      return { success: false, error: 'Inscription non trouvée' }
    }

    await prisma.eventRegistration.delete({
      where: { id: registrationId },
    })

    revalidatePath('/admin/events')
    revalidatePath(`/admin/events/${registration.eventId}`)
    revalidatePath(`/admin/events/${registration.eventId}/registrations`)
    revalidatePath('/events')
    revalidatePath(`/events/${registration.eventId}`)

    return { success: true }
  } catch (error) {
    console.error('Remove registration error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
