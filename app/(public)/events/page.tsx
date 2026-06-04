import { Metadata } from 'next'
import { Calendar, Clock } from 'lucide-react'
import { EventCard } from '@/app/components/EventCard'
import { prisma } from '@/app/lib/prisma'

export const dynamic = 'force-dynamic'
export const metadata: Metadata = { title: 'Événements', description: 'Découvrez tous nos événements à venir et passés' }

export default async function EventsPage() {
  const now = new Date()
  let upcomingEvents: Awaited<ReturnType<typeof prisma.event.findMany>> = []
  let pastEvents: Awaited<ReturnType<typeof prisma.event.findMany>> = []
  try {
    ;[upcomingEvents, pastEvents] = await Promise.all([
      prisma.event.findMany({ where: { isPublished: true, date: { gte: now } }, orderBy: { date: 'asc' } }),
      prisma.event.findMany({ where: { isPublished: true, date: { lt: now } }, orderBy: { date: 'desc' }, take: 12 }),
    ])
  } catch (error) {
    console.error('Public events page query failed:', error)
  }

  return (
    <div className="min-w-0 overflow-x-clip">
      <section className="relative overflow-hidden border-b border-white/[0.07]">
        <div className="pointer-events-none absolute -right-32 -top-60 size-[42rem] rounded-full bg-[radial-gradient(circle,rgba(72,189,211,.15),transparent_65%)]" />
        <div className="mx-auto max-w-7xl px-4 py-16 lg:px-8 lg:py-20">
          <span className="eyebrow"><Calendar className="size-3.5" /> Agenda AEIC</span>
          <h1 className="mt-4 text-5xl font-black uppercase leading-[.93] tracking-[-.06em] text-white sm:text-6xl lg:text-7xl">
            Les prochains<br /><span className="text-primary">rendez-vous.</span>
          </h1>
          <p className="mt-5 max-w-lg text-sm leading-7 text-muted-foreground">Soirées, conférences, sorties et événements qui rythment la vie de l&apos;association.</p>
          <div className="mt-8 flex gap-5 text-[9px] font-bold uppercase tracking-[.18em] text-muted-foreground">
            <span><strong className="mr-2 text-xl font-black text-white">{upcomingEvents.length}</strong>À venir</span>
            <span className="border-l border-white/[0.08] pl-5"><strong className="mr-2 text-xl font-black text-white">{pastEvents.length}</strong>Passés</span>
          </div>
        </div>
      </section>

      <div className="mx-auto max-w-7xl space-y-16 px-4 py-14 lg:px-8">
        <section className="min-w-0">
          <div className="flex items-center gap-3"><Clock className="size-4 text-primary" /><h2 className="text-sm font-black uppercase tracking-[.14em] text-white">À venir</h2></div>
          {upcomingEvents.length > 0 ? (
            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{upcomingEvents.map((event) => <EventCard key={event.id} event={event} />)}</div>
          ) : (
            <div className="surface mt-6 p-12 text-center text-sm text-muted-foreground">Aucun événement annoncé pour le moment.</div>
          )}
        </section>
        {pastEvents.length > 0 && (
          <section className="min-w-0">
            <div className="flex items-center gap-3"><Calendar className="size-4 text-primary" /><h2 className="text-sm font-black uppercase tracking-[.14em] text-white">Archives</h2></div>
            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{pastEvents.map((event) => <EventCard key={event.id} event={event} showActions={false} />)}</div>
          </section>
        )}
      </div>
    </div>
  )
}
