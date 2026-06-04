'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAuth } from '@/app/lib/permissions'
import { auth } from '@/app/lib/auth'

type VariantChoice = { variantId: string; choiceId: string }

export async function registerToEvent(
  eventId: string,
  selectedChoices: VariantChoice[] = []
) {
  try {
    const session = await requireAuth()

    const event = await prisma.event.findUnique({
      where: { id: eventId },
      include: {
        variants: {
          where: { required: true },
          include: { choices: true },
        },
      },
    })

    if (!event) return { success: false, error: "L'événement n'existe pas" }
    if (!event.isPublished) return { success: false, error: "L'événement n'est pas disponible" }
    if (new Date(event.date) < new Date()) return { success: false, error: "L'événement est passé" }

    // Valider que toutes les variantes requises ont un choix
    for (const variant of event.variants) {
      const chosen = selectedChoices.find((c) => c.variantId === variant.id)
      if (!chosen) {
        return { success: false, error: `Veuillez sélectionner une option pour "${variant.label}"` }
      }
      const validChoice = variant.choices.some((c) => c.id === chosen.choiceId)
      if (!validChoice) {
        return { success: false, error: `Option invalide pour "${variant.label}"` }
      }
    }

    const existing = await prisma.eventRegistration.findUnique({
      where: { userId_eventId: { userId: session.user.id, eventId } },
    })
    if (existing) return { success: false, error: 'Vous êtes déjà inscrit à cet événement' }

    // Créer inscription + choix en transaction
    await prisma.$transaction(async (tx) => {
      const registration = await tx.eventRegistration.create({
        data: { userId: session.user.id, eventId },
      })

      if (selectedChoices.length > 0) {
        await tx.eventRegistrationChoice.createMany({
          data: selectedChoices.map((c) => ({
            registrationId: registration.id,
            variantId: c.variantId,
            choiceId: c.choiceId,
          })),
        })
      }
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

    const event = await prisma.event.findUnique({ where: { id: eventId } })
    if (!event) return { success: false, error: "L'événement n'existe pas" }
    if (new Date(event.date) < new Date()) {
      return { success: false, error: "Impossible de se désinscrire d'un événement passé" }
    }

    await prisma.eventRegistration.delete({
      where: { userId_eventId: { userId: session.user.id, eventId } },
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
    if (!session?.user?.id) return { isRegistered: false }

    const registration = await prisma.eventRegistration.findUnique({
      where: { userId_eventId: { userId: session.user.id, eventId } },
    })

    return { isRegistered: !!registration }
  } catch {
    return { isRegistered: false }
  }
}
