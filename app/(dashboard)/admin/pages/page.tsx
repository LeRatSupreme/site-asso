import { Metadata } from 'next'
import Link from 'next/link'
import { Plus } from 'lucide-react'
import { prisma } from '@/app/lib/prisma'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { PagesTable } from './PagesTable'

export const metadata: Metadata = {
  title: 'Gestion des pages | Administration',
  description: 'Gérez les pages du site',
}

async function getPages() {
  return prisma.page.findMany({
    orderBy: { updatedAt: 'desc' },
  })
}

export default async function PagesPage() {
  const pages = await getPages()

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Pages</h1>
          <p className="text-muted-foreground">
            Gérez le contenu des pages du site
          </p>
        </div>
        <Button asChild>
          <Link href="/admin/pages/new">
            <Plus className="mr-2 h-4 w-4" />
            Nouvelle page
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Pages du site</CardTitle>
          <CardDescription>
            Modifiez le contenu des pages depuis cette interface
          </CardDescription>
        </CardHeader>
        <CardContent>
          <PagesTable pages={pages} />
        </CardContent>
      </Card>
    </div>
  )
}
