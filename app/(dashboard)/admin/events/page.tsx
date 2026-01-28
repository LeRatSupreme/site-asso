import { Metadata } from 'next'
import Link from 'next/link'
import { Plus } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { prisma } from '@/app/lib/prisma'
import { EventsTable } from './EventsTable'

export const metadata: Metadata = {
  title: 'Gestion des événements',
  description: 'Gérez tous les événements de l\'association',
}

export default async function AdminEventsPage() {
  const events = await prisma.event.findMany({
    orderBy: { date: 'desc' },
    include: {
      _count: { select: { registrations: true, photos: true } },
    },
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Événements</h1>
          <p className="text-muted-foreground mt-2">
            Gérez les événements de l&apos;association
          </p>
        </div>
        <Button asChild>
          <Link href="/admin/events/new">
            <Plus className="mr-2 h-4 w-4" />
            Nouvel événement
          </Link>
        </Button>
      </div>

      <EventsTable events={events} />
    </div>
  )
}
