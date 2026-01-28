'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import * as z from 'zod'
import { Loader2 } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Input } from '@/app/components/ui/input'
import { Label } from '@/app/components/ui/label'
import { Checkbox } from '@/app/components/ui/checkbox'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { RichTextEditor } from '@/app/components/RichTextEditor'
import { toast } from '@/app/components/ui/use-toast'
import { createPage, updatePage } from '@/app/actions/pages.actions'
import type { Page } from '@prisma/client'

const pageSchema = z.object({
  title: z.string().min(1, 'Le titre est requis'),
  slug: z
    .string()
    .min(1, 'Le slug est requis')
    .regex(/^[a-z0-9-]+$/, 'Uniquement des lettres minuscules, chiffres et tirets'),
  content: z.string().min(1, 'Le contenu est requis'),
  metaTitle: z.string().optional(),
  metaDescription: z.string().optional(),
  isPublished: z.boolean().default(false),
})

type PageFormData = z.infer<typeof pageSchema>

interface PageFormProps {
  page?: Page
}

export function PageForm({ page }: PageFormProps) {
  const router = useRouter()
  const [isLoading, setIsLoading] = useState(false)
  const [content, setContent] = useState(page?.content || '')

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<PageFormData>({
    resolver: zodResolver(pageSchema),
    defaultValues: {
      title: page?.title || '',
      slug: page?.slug || '',
      content: page?.content || '',
      metaTitle: page?.metaTitle || '',
      metaDescription: page?.metaDescription || '',
      isPublished: page?.isPublished || false,
    },
  })

  const isPublished = watch('isPublished')

  const onSubmit = async (data: PageFormData) => {
    setIsLoading(true)

    try {
      const result = page
        ? await updatePage(page.id, { ...data, content })
        : await createPage({ ...data, content })

      if (result.success) {
        toast({
          title: page ? 'Page mise à jour' : 'Page créée',
          variant: 'success',
        })
        router.push('/admin/pages')
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

  // Génération automatique du slug
  const generateSlug = (title: string) => {
    return title
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Contenu</CardTitle>
              <CardDescription>
                Informations principales de la page
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="title">Titre *</Label>
                <Input
                  id="title"
                  {...register('title')}
                  onChange={(e) => {
                    register('title').onChange(e)
                    if (!page) {
                      setValue('slug', generateSlug(e.target.value))
                    }
                  }}
                  placeholder="Titre de la page"
                />
                {errors.title && (
                  <p className="text-sm text-destructive">{errors.title.message}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="slug">Slug (URL) *</Label>
                <Input
                  id="slug"
                  {...register('slug')}
                  placeholder="url-de-la-page"
                />
                {errors.slug && (
                  <p className="text-sm text-destructive">{errors.slug.message}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label>Contenu *</Label>
                <RichTextEditor
                  content={content}
                  onChange={(value) => {
                    setContent(value)
                    setValue('content', value)
                  }}
                />
                {errors.content && (
                  <p className="text-sm text-destructive">{errors.content.message}</p>
                )}
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Publication</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="isPublished"
                  checked={isPublished}
                  onCheckedChange={(checked) =>
                    setValue('isPublished', checked as boolean)
                  }
                />
                <Label htmlFor="isPublished">Publier la page</Label>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>SEO</CardTitle>
              <CardDescription>
                Optimisation pour les moteurs de recherche
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="metaTitle">Meta titre</Label>
                <Input
                  id="metaTitle"
                  {...register('metaTitle')}
                  placeholder="Titre pour les moteurs de recherche"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="metaDescription">Meta description</Label>
                <Input
                  id="metaDescription"
                  {...register('metaDescription')}
                  placeholder="Description pour les moteurs de recherche"
                />
              </div>
            </CardContent>
          </Card>

          <div className="flex gap-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => router.back()}
              disabled={isLoading}
              className="flex-1"
            >
              Annuler
            </Button>
            <Button type="submit" disabled={isLoading} className="flex-1">
              {isLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {page ? 'Mettre à jour' : 'Créer'}
            </Button>
          </div>
        </div>
      </div>
    </form>
  )
}
