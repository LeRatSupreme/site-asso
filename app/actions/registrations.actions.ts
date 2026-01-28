'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAuth } from '@/app/lib/permissions'
import { auth } from '@/app/lib/auth'

export async function registerToEvent(eventId: string) {
  try {
    const session = await requireAuth()

    // Vérifier que l'événement existe et est publié
    const event = await prisma.event.findUnique({
      where: { id: eventId },
    })

    if (!event) {
      return { success: false, error: "L'événement n'existe pas" }
    }

    if (!event.isPublished) {
      return { success: false, error: "L'événement n'est pas disponible" }
    }

    // Vérifier que l'événement n'est pas passé
    if (new Date(event.date) < new Date()) {
      return { success: false, error: "L'événement est passé" }
    }

    // Vérifier si déjà inscrit
    const existing = await prisma.eventRegistration.findUnique({
      where: {
        userId_eventId: {
          userId: session.user.id,
          eventId,
        },
      },
    })

    if (existing) {
      return { success: false, error: 'Vous êtes déjà inscrit à cet événement' }
    }

    // Créer l'inscription
    await prisma.eventRegistration.create({
      data: {
        userId: session.user.id,
        eventId,
      },
    })

    revalidatePath(`/events/${eventId}`)
    revalidatePath('/eleve/inscriptions')

    return { success: true }
  } catch (error) {
    console.error('Register to event error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function unregisterFromEvent(eventId: string) {
  try {
    const session = await requireAuth()

    // Vérifier que l'événement existe
    const event = await prisma.event.findUnique({
      where: { id: eventId },
    })

    if (!event) {
      return { success: false, error: "L'événement n'existe pas" }
    }

    // Vérifier que l'événement n'est pas passé (optionnel)
    if (new Date(event.date) < new Date()) {
      return { success: false, error: "Impossible de se désinscrire d'un événement passé" }
    }

    // Supprimer l'inscription
    await prisma.eventRegistration.delete({
      where: {
        userId_eventId: {
          userId: session.user.id,
          eventId,
        },
      },
    })

    revalidatePath(`/events/${eventId}`)
    revalidatePath('/eleve/inscriptions')

    return { success: true }
  } catch (error) {
    console.error('Unregister from event error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function getRegistrationStatus(eventId: string) {
  try {
    const session = await auth()
    
    if (!session?.user?.id) {
      return { isRegistered: false }
    }

    const registration = await prisma.eventRegistration.findUnique({
      where: {
        userId_eventId: {
          userId: session.user.id,
          eventId,
        },
      },
    })

    return { isRegistered: !!registration }
  } catch (error) {
    console.error('Get registration status error:', error)
    return { isRegistered: false }
  }
}
