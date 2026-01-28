'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Loader2, Save } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import { Label } from '@/app/components/ui/label'
import { Textarea } from '@/app/components/ui/textarea'
import { Switch } from '@/app/components/ui/switch'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/app/components/ui/tabs'
import { toast } from '@/app/components/ui/use-toast'
import { updateSettings } from '@/app/actions/settings.actions'

interface SettingsFormProps {
  settings: Record<string, string>
}

export function SettingsForm({ settings: initialSettings }: SettingsFormProps) {
  const router = useRouter()
  const [settings, setSettings] = useState(initialSettings)
  const [isLoading, setIsLoading] = useState(false)

  const updateSetting = (key: string, value: string) => {
    setSettings((prev) => ({ ...prev, [key]: value }))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setIsLoading(true)

    try {
      const result = await updateSettings(settings)
      if (result.success) {
        toast({
          title: 'Paramètres sauvegardés',
          variant: 'success',
        })
        router.refresh()
      } else {
        toast({
          title: 'Erreur',
          description: result.error,
          variant: 'destructive',
        })
      }
    } catch {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <Tabs defaultValue="general" className="space-y-6">
        <TabsList className="grid w-full grid-cols-4">
          <TabsTrigger value="general">Général</TabsTrigger>
          <TabsTrigger value="social">Réseaux sociaux</TabsTrigger>
          <TabsTrigger value="orders">Commandes</TabsTrigger>
          <TabsTrigger value="features">Fonctionnalités</TabsTrigger>
        </TabsList>

        {/* Général */}
        <TabsContent value="general">
          <Card>
            <CardHeader>
              <CardTitle>Informations générales</CardTitle>
              <CardDescription>
                Configuration de base du site
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="site_name">Nom du site</Label>
                <Input
                  id="site_name"
                  value={settings.site_name || ''}
                  onChange={(e) => updateSetting('site_name', e.target.value)}
                  placeholder="BDE ESIEE Amiens"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="site_description">Description</Label>
                <Textarea
                  id="site_description"
                  value={settings.site_description || ''}
                  onChange={(e) => updateSetting('site_description', e.target.value)}
                  placeholder="Le Bureau des Élèves de l'ESIEE Amiens"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="contact_email">Email de contact</Label>
                <Input
                  id="contact_email"
                  type="email"
                  value={settings.contact_email || ''}
                  onChange={(e) => updateSetting('contact_email', e.target.value)}
                  placeholder="contact@bde-esiee.fr"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="contact_address">Adresse</Label>
                <Textarea
                  id="contact_address"
                  value={settings.contact_address || ''}
                  onChange={(e) => updateSetting('contact_address', e.target.value)}
                  placeholder="14 Quai de la Somme, 80000 Amiens"
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Réseaux sociaux */}
        <TabsContent value="social">
          <Card>
            <CardHeader>
              <CardTitle>Réseaux sociaux</CardTitle>
              <CardDescription>
                Liens vers vos pages sur les réseaux sociaux
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="social_facebook">Facebook</Label>
                <Input
                  id="social_facebook"
                  value={settings.social_facebook || ''}
                  onChange={(e) => updateSetting('social_facebook', e.target.value)}
                  placeholder="https://facebook.com/..."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="social_instagram">Instagram</Label>
                <Input
                  id="social_instagram"
                  value={settings.social_instagram || ''}
                  onChange={(e) => updateSetting('social_instagram', e.target.value)}
                  placeholder="https://instagram.com/..."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="social_twitter">Twitter / X</Label>
                <Input
                  id="social_twitter"
                  value={settings.social_twitter || ''}
                  onChange={(e) => updateSetting('social_twitter', e.target.value)}
                  placeholder="https://twitter.com/..."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="social_linkedin">LinkedIn</Label>
                <Input
                  id="social_linkedin"
                  value={settings.social_linkedin || ''}
                  onChange={(e) => updateSetting('social_linkedin', e.target.value)}
                  placeholder="https://linkedin.com/company/..."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="social_discord">Discord</Label>
                <Input
                  id="social_discord"
                  value={settings.social_discord || ''}
                  onChange={(e) => updateSetting('social_discord', e.target.value)}
                  placeholder="https://discord.gg/..."
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Cafétéria */}
        <TabsContent value="orders">
          <Card>
            <CardHeader>
              <CardTitle>Configuration de la cafétéria</CardTitle>
              <CardDescription>
                Paramètres généraux du système de commandes
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="cafeteria_hours">
                  Horaires d&apos;ouverture
                </Label>
                <Input
                  id="cafeteria_hours"
                  value={settings.cafeteria_hours || '10h00 - 14h00'}
                  onChange={(e) => updateSetting('cafeteria_hours', e.target.value)}
                  placeholder="10h00 - 14h00"
                />
                <p className="text-sm text-muted-foreground">
                  Horaires affichés sur la page cafétéria
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="cafeteria_message">
                  Message d&apos;information
                </Label>
                <Textarea
                  id="cafeteria_message"
                  value={settings.cafeteria_message || ''}
                  onChange={(e) => updateSetting('cafeteria_message', e.target.value)}
                  placeholder="Message optionnel affiché aux étudiants..."
                />
                <p className="text-sm text-muted-foreground">
                  Affiché en haut de la page cafétéria (laissez vide pour ne rien afficher)
                </p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Fonctionnalités */}
        <TabsContent value="features">
          <Card>
            <CardHeader>
              <CardTitle>Fonctionnalités</CardTitle>
              <CardDescription>
                Activer ou désactiver des fonctionnalités
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Inscriptions ouvertes</Label>
                  <p className="text-sm text-muted-foreground">
                    Permet aux nouveaux utilisateurs de s&apos;inscrire
                  </p>
                </div>
                <Switch
                  checked={settings.registration_open === 'true'}
                  onCheckedChange={(checked) =>
                    updateSetting('registration_open', checked ? 'true' : 'false')
                  }
                />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Commandes actives</Label>
                  <p className="text-sm text-muted-foreground">
                    Active le système de commandes
                  </p>
                </div>
                <Switch
                  checked={settings.orders_enabled === 'true'}
                  onCheckedChange={(checked) =>
                    updateSetting('orders_enabled', checked ? 'true' : 'false')
                  }
                />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Mode maintenance</Label>
                  <p className="text-sm text-muted-foreground">
                    Affiche une page de maintenance aux visiteurs
                  </p>
                </div>
                <Switch
                  checked={settings.maintenance_mode === 'true'}
                  onCheckedChange={(checked) =>
                    updateSetting('maintenance_mode', checked ? 'true' : 'false')
                  }
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <div className="mt-6 flex justify-end">
        <Button type="submit" disabled={isLoading}>
          {isLoading ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <Save className="mr-2 h-4 w-4" />
          )}
          Sauvegarder
        </Button>
      </div>
    </form>
  )
}
