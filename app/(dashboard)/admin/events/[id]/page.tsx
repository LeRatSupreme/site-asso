import { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { prisma } from '@/app/lib/prisma'
import { EventForm } from '../EventForm'
import { VariantsManager } from '../VariantsManager'

interface EditEventPageProps {
  params: Promise<{ id: string }>
}

export async function generateMetadata({ params }: EditEventPageProps): Promise<Metadata> {
  const { id } = await params
  const event = await prisma.event.findUnique({ where: { id } })
  return {
    title: event ? `Modifier ${event.title}` : 'Événement non trouvé',
  }
}

export default async function EditEventPage({ params }: EditEventPageProps) {
  const { id } = await params

  const event = await prisma.event.findUnique({
    where: { id },
    include: {
      variants: {
        orderBy: { order: 'asc' },
        include: { choices: { orderBy: { order: 'asc' } } },
      },
    },
  })

  if (!event) notFound()

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button asChild variant="ghost" size="icon">
          <Link href="/admin/events">
            <ArrowLeft className="h-4 w-4" />
          </Link>
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Modifier l&apos;événement</h1>
          <p className="text-muted-foreground mt-1">{event.title}</p>
        </div>
      </div>

      {/* Formulaire principal */}
      <EventForm event={event} />

      {/* Variantes / Options */}
      <VariantsManager eventId={event.id} initialVariants={event.variants} />
    </div>
  )
}
