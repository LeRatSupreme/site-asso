'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'

const variantSchema = z.object({
  label: z.string().min(1, 'Le libellé est requis').max(80),
  required: z.boolean().default(true),
})

const choiceSchema = z.object({
  label: z.string().min(1, 'Le libellé est requis').max(60),
})

// ─── Lecture ────────────────────────────────────────────────────────────────

export async function getEventVariants(eventId: string) {
  return prisma.eventVariant.findMany({
    where: { eventId },
    orderBy: { order: 'asc' },
    include: {
      choices: { orderBy: { order: 'asc' } },
    },
  })
}

// ─── Variantes ──────────────────────────────────────────────────────────────

export async function createVariant(eventId: string, data: z.infer<typeof variantSchema>) {
  try {
    await requireAdmin()
    const validated = variantSchema.parse(data)

    const last = await prisma.eventVariant.findFirst({
      where: { eventId },
      orderBy: { order: 'desc' },
      select: { order: true },
    })

    const variant = await prisma.eventVariant.create({
      data: {
        eventId,
        label: validated.label,
        required: validated.required,
        order: (last?.order ?? -1) + 1,
      },
      include: { choices: true },
    })

    revalidatePath(`/admin/events/${eventId}`)
    return { success: true, variant }
  } catch (error) {
    if (error instanceof z.ZodError) return { success: false, error: error.errors[0].message }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateVariant(
  variantId: string,
  data: z.infer<typeof variantSchema>
) {
  try {
    await requireAdmin()
    const validated = variantSchema.parse(data)

    const variant = await prisma.eventVariant.update({
      where: { id: variantId },
      data: { label: validated.label, required: validated.required },
      include: { choices: true },
    })

    revalidatePath(`/admin/events/${variant.eventId}`)
    return { success: true, variant }
  } catch (error) {
    if (error instanceof z.ZodError) return { success: false, error: error.errors[0].message }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deleteVariant(variantId: string) {
  try {
    await requireAdmin()

    const variant = await prisma.eventVariant.findUnique({
      where: { id: variantId },
      select: { eventId: true },
    })
    if (!variant) return { success: false, error: 'Variante introuvable' }

    await prisma.eventVariant.delete({ where: { id: variantId } })

    revalidatePath(`/admin/events/${variant.eventId}`)
    return { success: true }
  } catch {
    return { success: false, error: 'Une erreur est survenue' }
  }
}

// ─── Choix ──────────────────────────────────────────────────────────────────

export async function addChoice(variantId: string, data: z.infer<typeof choiceSchema>) {
  try {
    await requireAdmin()
    const validated = choiceSchema.parse(data)

    const last = await prisma.eventVariantChoice.findFirst({
      where: { variantId },
      orderBy: { order: 'desc' },
      select: { order: true },
    })

    const choice = await prisma.eventVariantChoice.create({
      data: {
        variantId,
        label: validated.label,
        order: (last?.order ?? -1) + 1,
      },
    })

    const variant = await prisma.eventVariant.findUnique({
      where: { id: variantId },
      select: { eventId: true },
    })
    if (variant) revalidatePath(`/admin/events/${variant.eventId}`)

    return { success: true, choice }
  } catch (error) {
    if (error instanceof z.ZodError) return { success: false, error: error.errors[0].message }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deleteChoice(choiceId: string) {
  try {
    await requireAdmin()

    const choice = await prisma.eventVariantChoice.findUnique({
      where: { id: choiceId },
      include: { variant: { select: { eventId: true } } },
    })
    if (!choice) return { success: false, error: 'Choix introuvable' }

    await prisma.eventVariantChoice.delete({ where: { id: choiceId } })

    revalidatePath(`/admin/events/${choice.variant.eventId}`)
    return { success: true }
  } catch {
    return { success: false, error: 'Une erreur est survenue' }
  }
}
