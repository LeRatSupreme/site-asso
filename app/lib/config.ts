import { prisma } from './prisma'

export type ConfigKey = 
  | 'site_name'
  | 'site_description'
  | 'contact_email'
  | 'logo_url'
  | 'hero_image'
  | 'facebook_url'
  | 'instagram_url'
  | 'twitter_url'
  | 'linkedin_url'
  | 'default_sumup_link'
  | 'orders_enabled'
  | 'registrations_enabled'
  | 'maintenance_mode'
  | 'panini_options'
  | 'pizza_options'
  | 'raclette_options'
  | 'panini_price'
  | 'pizza_price'
  | 'raclette_price'

// Cache pour les settings (durée: 5 minutes)
let settingsCache: Map<string, { value: string; timestamp: number }> = new Map()
const CACHE_TTL = 5 * 60 * 1000 // 5 minutes

export async function getSetting(key: ConfigKey): Promise<string> {
  // Check cache first
  const cached = settingsCache.get(key)
  if (cached && Date.now() - cached.timestamp < CACHE_TTL) {
    return cached.value
  }

  const setting = await prisma.setting.findUnique({
    where: { key },
  })

  const value = setting?.value ?? ''
  
  // Update cache
  settingsCache.set(key, { value, timestamp: Date.now() })
  
  return value
}

export async function getSettings(keys: ConfigKey[]): Promise<Record<ConfigKey, string>> {
  const settings = await prisma.setting.findMany({
    where: { key: { in: keys } },
  })

  const result: Record<string, string> = {}
  
  for (const key of keys) {
    const setting = settings.find((s) => s.key === key)
    result[key] = setting?.value ?? ''
  }
  
  return result as Record<ConfigKey, string>
}

export async function getAllSettings() {
  const settings = await prisma.setting.findMany({
    orderBy: [{ group: 'asc' }, { key: 'asc' }],
  })
  return settings
}

export async function getSettingsByGroup(group: string) {
  const settings = await prisma.setting.findMany({
    where: { group },
    orderBy: { key: 'asc' },
  })
  return settings
}

export function clearSettingsCache() {
  settingsCache.clear()
}

export function clearSettingCache(key: ConfigKey) {
  settingsCache.delete(key)
}

// Helper pour vérifier si une fonctionnalité est activée
export async function isFeatureEnabled(feature: 'orders_enabled' | 'registrations_enabled'): Promise<boolean> {
  const value = await getSetting(feature)
  return value === 'true'
}

// Helper pour vérifier si le mode maintenance est activé
export async function isMaintenanceMode(): Promise<boolean> {
  const value = await getSetting('maintenance_mode')
  return value === 'true'
}

// Helper pour obtenir les options d'un type de commande
export async function getOrderOptions(type: 'panini' | 'pizza' | 'raclette'): Promise<string[]> {
  const key = `${type}_options` as ConfigKey
  const value = await getSetting(key)
  return value ? value.split(',').map((o) => o.trim()) : []
}

// Helper pour obtenir le prix d'un type de commande
export async function getOrderPrice(type: 'panini' | 'pizza' | 'raclette'): Promise<number> {
  const key = `${type}_price` as ConfigKey
  const value = await getSetting(key)
  return parseFloat(value) || 0
}
