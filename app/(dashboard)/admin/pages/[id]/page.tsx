import { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { prisma } from '@/app/lib/prisma'
import { PageForm } from '../PageForm'

export const metadata: Metadata = {
  title: 'Modifier la page | Administration',
  description: 'Modifier une page',
}

interface EditPageProps {
  params: Promise<{ id: string }>
}

async function getPage(id: string) {
  return prisma.page.findUnique({
    where: { id },
  })
}

export default async function EditPagePage({ params }: EditPageProps) {
  const { id } = await params
  const page = await getPage(id)

  if (!page) {
    notFound()
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Modifier la page</h1>
        <p className="text-muted-foreground">
          Modifiez le contenu de la page
        </p>
      </div>

      <PageForm page={page} />
    </div>
  )
}
