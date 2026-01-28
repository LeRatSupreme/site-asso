'use server'

import { revalidatePath } from 'next/cache'
import { prisma } from '@/app/lib/prisma'
import { requireAdmin } from '@/app/lib/permissions'

export async function updateSetting(key: string, value: string) {
  try {
    await requireAdmin()

    await prisma.setting.upsert({
      where: { key },
      update: { value },
      create: { key, value },
    })

    revalidatePath('/admin/settings')
    revalidatePath('/')

    return { success: true }
  } catch (error) {
    console.error('Update setting error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function updateSettings(settings: Record<string, string>) {
  try {
    await requireAdmin()

    // Mettre à jour chaque paramètre
    const promises = Object.entries(settings).map(([key, value]) =>
      prisma.setting.upsert({
        where: { key },
        update: { value },
        create: { key, value },
      })
    )

    await Promise.all(promises)

    revalidatePath('/admin/settings')
    revalidatePath('/')

    return { success: true }
  } catch (error) {
    console.error('Update settings error:', error)
    return { success: false, error: 'Une erreur est survenue' }
  }
}

export async function getSetting(key: string) {
  try {
    const setting = await prisma.setting.findUnique({
      where: { key },
    })

    return setting?.value || null
  } catch (error) {
    console.error('Get setting error:', error)
    return null
  }
}

export async function getSettings(keys: string[]) {
  try {
    const settings = await prisma.setting.findMany({
      where: {
        key: { in: keys },
      },
    })

    const result: Record<string, string> = {}
    settings.forEach((s) => {
      result[s.key] = s.value
    })

    return result
  } catch (error) {
    console.error('Get settings error:', error)
    return {}
  }
}
