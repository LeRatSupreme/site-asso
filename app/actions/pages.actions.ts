'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'
import { z } from 'zod'

const pageSchema = z.object({
  title: z.string().min(1, 'Le titre est requis'),
  slug: z.string().min(1, 'Le slug est requis').regex(/^[a-z0-9-]+$/, 'Slug invalide'),
  content: z.string().min(1, 'Le contenu est requis'),
  metaTitle: z.string().optional().nullable(),
  metaDescription: z.string().optional().nullable(),
  isPublished: z.boolean().default(false),
})

export async function createPage(data: z.infer<typeof pageSchema>) {
  try {
    await requireAdmin()

    const validated = pageSchema.parse(data)

    // Vérifier si le slug existe déjà
    const existing = await prisma.page.findUnique({
      where: { slug: validated.slug },
    })

    if (existing) {
      return { success: false, error: 'Ce slug existe déjà' }
    }

    const page = await prisma.page.create({
      data: {
        ...validated,
        metaTitle: validated.metaTitle || null,
        metaDescription: validated.metaDescription || null,
      },
    })

    revalidatePath('/admin/pages')
    revalidatePath(`/${validated.slug}`)

    return { success: true, page }
  } catch (error) {
    console.error('Create page error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updatePage(id: string, data: z.infer<typeof pageSchema>) {
  try {
    await requireAdmin()

    const validated = pageSchema.parse(data)

    // Vérifier si le slug existe déjà pour une autre page
    const existing = await prisma.page.findFirst({
      where: {
        slug: validated.slug,
        NOT: { id },
      },
    })

    if (existing) {
      return { success: false, error: 'Ce slug existe déjà' }
    }

    const page = await prisma.page.update({
      where: { id },
      data: {
        ...validated,
        metaTitle: validated.metaTitle || null,
        metaDescription: validated.metaDescription || null,
      },
    })

    revalidatePath('/admin/pages')
    revalidatePath(`/admin/pages/${id}`)
    revalidatePath(`/${validated.slug}`)
    revalidatePath('/')

    return { success: true, page }
  } catch (error) {
    console.error('Update page error:', error)
    if (error instanceof z.ZodError) {
      return { success: false, error: error.errors[0].message }
    }
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function deletePage(id: string) {
  try {
    await requireAdmin()

    const page = await prisma.page.findUnique({
      where: { id },
    })

    if (!page) {
      return { success: false, error: 'Page non trouvée' }
    }

    // Empêcher la suppression des pages système
    const systemPages = ['home', 'presentation', 'team', 'legal', 'privacy']
    if (systemPages.includes(page.slug)) {
      return { success: false, error: 'Impossible de supprimer une page système' }
    }

    await prisma.page.delete({
      where: { id },
    })

    revalidatePath('/admin/pages')
    revalidatePath(`/${page.slug}`)

    return { success: true }
  } catch (error) {
    console.error('Delete page error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function togglePagePublished(id: string, isPublished: boolean) {
  try {
    await requireAdmin()

    const page = await prisma.page.update({
      where: { id },
      data: { isPublished },
    })

    revalidatePath('/admin/pages')
    revalidatePath(`/${page.slug}`)

    return { success: true }
  } catch (error) {
    console.error('Toggle page published error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}
