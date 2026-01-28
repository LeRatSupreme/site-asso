'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import { Label } from '@/app/components/ui/label'
import { Switch } from '@/app/components/ui/switch'
import { Card, CardContent, CardHeader, CardTitle } from '@/app/components/ui/card'
import { RichTextEditor } from '@/app/components/RichTextEditor'
import { toast } from '@/app/components/ui/use-toast'
import { createEvent, updateEvent } from '@/app/actions/events.actions'
import { Loader2 } from 'lucide-react'
import type { Event } from '@prisma/client'

interface EventFormProps {
  event?: Event
}

export function EventForm({ event }: EventFormProps) {
  const router = useRouter()
  const [isLoading, setIsLoading] = useState(false)
  const [formData, setFormData] = useState({
    title: event?.title || '',
    description: event?.description || '',
    image: event?.image || '',
    date: event?.date 
      ? new Date(event.date).toISOString().slice(0, 16)
      : '',
    location: event?.location || '',
    sumupLink: event?.sumupLink || '',
    isPublished: event?.isPublished || false,
  })

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement>
  ) => {
    const { name, value, type, checked } = e.target
    setFormData((prev) => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    
    if (!formData.title || !formData.date || !formData.location) {
      toast({
        title: 'Erreur',
        description: 'Veuillez remplir tous les champs obligatoires',
        variant: 'destructive',
      })
      return
    }

    setIsLoading(true)

    try {
      const data = {
        title: formData.title,
        description: formData.description,
        image: formData.image || undefined,
        date: new Date(formData.date),
        location: formData.location,
        sumupLink: formData.sumupLink || undefined,
        isPublished: formData.isPublished,
      }

      const result = event
        ? await updateEvent(event.id, data)
        : await createEvent(data)

      if (result.success) {
        toast({
          title: event ? 'Événement modifié' : 'Événement créé',
          description: event
            ? 'L\'événement a été modifié avec succès'
            : 'L\'événement a été créé avec succès',
          variant: 'success',
        })
        router.push('/admin/events')
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
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Contenu principal */}
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Informations générales</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="title">Titre *</Label>
                <Input
                  id="title"
                  name="title"
                  value={formData.title}
                  onChange={handleChange}
                  placeholder="Titre de l'événement"
                  required
                  disabled={isLoading}
                />
              </div>

              <div className="space-y-2">
                <Label>Description</Label>
                <RichTextEditor
                  content={formData.description}
                  onChange={(content) =>
                    setFormData((prev) => ({ ...prev, description: content }))
                  }
                />
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Publication</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="isPublished">Publier</Label>
                <Switch
                  id="isPublished"
                  checked={formData.isPublished}
                  onCheckedChange={(checked) =>
                    setFormData((prev) => ({ ...prev, isPublished: checked }))
                  }
                  disabled={isLoading}
                />
              </div>
              <p className="text-sm text-muted-foreground">
                {formData.isPublished
                  ? 'L\'événement sera visible sur le site'
                  : 'L\'événement restera en brouillon'}
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Date et lieu</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="date">Date et heure *</Label>
                <Input
                  id="date"
                  name="date"
                  type="datetime-local"
                  value={formData.date}
                  onChange={handleChange}
                  required
                  disabled={isLoading}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="location">Lieu *</Label>
                <Input
                  id="location"
                  name="location"
                  value={formData.location}
                  onChange={handleChange}
                  placeholder="Lieu de l'événement"
                  required
                  disabled={isLoading}
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Image et paiement</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="image">URL de l&apos;image</Label>
                <Input
                  id="image"
                  name="image"
                  type="url"
                  value={formData.image}
                  onChange={handleChange}
                  placeholder="https://..."
                  disabled={isLoading}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="sumupLink">Lien SumUp</Label>
                <Input
                  id="sumupLink"
                  name="sumupLink"
                  type="url"
                  value={formData.sumupLink}
                  onChange={handleChange}
                  placeholder="https://sumup.io/..."
                  disabled={isLoading}
                />
                <p className="text-xs text-muted-foreground">
                  Lien de paiement pour l&apos;événement
                </p>
              </div>
            </CardContent>
          </Card>

          <div className="flex gap-4">
            <Button
              type="button"
              variant="outline"
              className="flex-1"
              onClick={() => router.back()}
              disabled={isLoading}
            >
              Annuler
            </Button>
            <Button type="submit" className="flex-1" disabled={isLoading}>
              {isLoading ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  {event ? 'Modification...' : 'Création...'}
                </>
              ) : event ? (
                'Modifier'
              ) : (
                'Créer'
              )}
            </Button>
          </div>
        </div>
      </div>
    </form>
  )
}
