import { Metadata } from 'next'
import Link from 'next/link'
import { prisma } from '@/app/lib/prisma'
import { auth } from '@/app/lib/auth'
import { redirect } from 'next/navigation'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Badge } from '@/app/components/ui/badge'
import { formatDateTime } from '@/app/lib/utils'
import { CalendarDays, MapPin } from 'lucide-react'

export const metadata: Metadata = {
  title: 'Mes inscriptions',
  description: 'Gérez vos inscriptions aux événements',
}

export default async function InscriptionsPage() {
  const session = await auth()
  if (!session?.user?.id) {
    redirect('/login')
  }

  const registrations = await prisma.eventRegistration.findMany({
    where: { userId: session.user.id },
    include: {
      event: true,
    },
    orderBy: { event: { date: 'desc' } },
  })

  const upcomingRegistrations = registrations.filter(
    (r) => new Date(r.event.date) >= new Date()
  )
  const pastRegistrations = registrations.filter(
    (r) => new Date(r.event.date) < new Date()
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Mes inscriptions</h1>
        <p className="text-muted-foreground">
          Événements auxquels vous êtes inscrit
        </p>
      </div>

      {registrations.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center">
            <p className="text-muted-foreground mb-4">
              Vous n&apos;êtes inscrit à aucun événement
            </p>
            <Button asChild>
              <Link href="/events">Voir les événements</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          {upcomingRegistrations.length > 0 && (
            <div className="space-y-4">
              <h2 className="text-xl font-semibold">Événements à venir</h2>
              <div className="grid gap-4">
                {upcomingRegistrations.map(({ event, id }) => (
                  <Card key={id}>
                    <CardHeader className="pb-3">
                      <div className="flex items-center justify-between">
                        <CardTitle className="text-lg">{event.title}</CardTitle>
                        <Badge variant="success">Inscrit</Badge>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div className="flex flex-col gap-2 text-sm text-muted-foreground">
                          <div className="flex items-center gap-2">
                            <CalendarDays className="h-4 w-4" />
                            <span>{formatDateTime(event.date)}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <MapPin className="h-4 w-4" />
                            <span>{event.location}</span>
                          </div>
                        </div>
                        <Button asChild variant="outline">
                          <Link href={`/events/${event.id}`}>Voir détails</Link>
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </div>
          )}

          {pastRegistrations.length > 0 && (
            <div className="space-y-4">
              <h2 className="text-xl font-semibold">Événements passés</h2>
              <div className="grid gap-4">
                {pastRegistrations.map(({ event, id }) => (
                  <Card key={id} className="opacity-75">
                    <CardHeader className="pb-3">
                      <div className="flex items-center justify-between">
                        <CardTitle className="text-lg">{event.title}</CardTitle>
                        <Badge variant="secondary">Terminé</Badge>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div className="flex flex-col gap-2 text-sm text-muted-foreground">
                          <div className="flex items-center gap-2">
                            <CalendarDays className="h-4 w-4" />
                            <span>{formatDateTime(event.date)}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <MapPin className="h-4 w-4" />
                            <span>{event.location}</span>
                          </div>
                        </div>
                        <Button asChild variant="ghost">
                          <Link href={`/events/${event.id}`}>Voir détails</Link>
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
