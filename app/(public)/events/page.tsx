import { Metadata } from 'next'
import { EventCard } from '@/app/components/EventCard'
import { prisma } from '@/app/lib/prisma'

export const metadata: Metadata = {
  title: 'Événements',
  description: 'Découvrez tous nos événements à venir et passés',
}

export default async function EventsPage() {
  const now = new Date()

  const [upcomingEvents, pastEvents] = await Promise.all([
    prisma.event.findMany({
      where: {
        isPublished: true,
        date: { gte: now },
      },
      orderBy: { date: 'asc' },
    }),
    prisma.event.findMany({
      where: {
        isPublished: true,
        date: { lt: now },
      },
      orderBy: { date: 'desc' },
      take: 10,
    }),
  ])

  return (
    <div className="container py-8">
      <div className="mb-8">
        <h1 className="text-4xl font-bold mb-4">Événements</h1>
        <p className="text-muted-foreground text-lg">
          Retrouvez tous nos événements à venir et passés
        </p>
      </div>

      {/* Événements à venir */}
      <section className="mb-12">
        <h2 className="text-2xl font-semibold mb-6">À venir</h2>
        {upcomingEvents.length > 0 ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {upcomingEvents.map((event) => (
              <EventCard key={event.id} event={event} />
            ))}
          </div>
        ) : (
          <div className="text-center py-12 bg-muted/50 rounded-lg">
            <p className="text-muted-foreground">
              Aucun événement à venir pour le moment.
            </p>
            <p className="text-sm text-muted-foreground mt-2">
              Revenez bientôt pour découvrir nos prochaines activités !
            </p>
          </div>
        )}
      </section>

      {/* Événements passés */}
      {pastEvents.length > 0 && (
        <section>
          <h2 className="text-2xl font-semibold mb-6">Événements passés</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {pastEvents.map((event) => (
              <EventCard key={event.id} event={event} showActions={false} />
            ))}
          </div>
        </section>
      )}
    </div>
  )
}
