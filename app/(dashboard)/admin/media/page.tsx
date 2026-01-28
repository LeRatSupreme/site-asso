import { Metadata } from 'next'
import { prisma } from '@/app/lib/prisma'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { MediaLibrary } from './MediaLibrary'

export const metadata: Metadata = {
  title: 'Médiathèque | Administration',
  description: 'Gérez les médias du site',
}

async function getMedia() {
  return prisma.media.findMany({
    orderBy: { createdAt: 'desc' },
  })
}

export default async function MediaPage() {
  const media = await getMedia()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Médiathèque</h1>
        <p className="text-muted-foreground">
          Gérez les images et fichiers du site
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Fichiers</CardTitle>
          <CardDescription>
            Uploadez et gérez vos médias
          </CardDescription>
        </CardHeader>
        <CardContent>
          <MediaLibrary media={media} />
        </CardContent>
      </Card>
    </div>
  )
}
