import { Metadata } from 'next'
import { prisma } from '@/app/lib/prisma'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { SettingsForm } from './SettingsForm'

export const metadata: Metadata = {
  title: 'Paramètres | Administration',
  description: 'Configurez les paramètres du site',
}

async function getSettings() {
  const settings = await prisma.setting.findMany({
    orderBy: { key: 'asc' },
  })
  
  // Convertir en objet clé-valeur
  const settingsMap: Record<string, string> = {}
  settings.forEach((s) => {
    settingsMap[s.key] = s.value
  })
  
  return settingsMap
}

export default async function SettingsPage() {
  const settings = await getSettings()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Paramètres</h1>
        <p className="text-muted-foreground">
          Configurez les paramètres globaux du site
        </p>
      </div>

      <SettingsForm settings={settings} />
    </div>
  )
}
