import { Metadata } from 'next'
import Image from 'next/image'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { Calendar, MapPin, ArrowLeft, ExternalLink, Users, Camera } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { Card, CardContent } from '@/app/components/ui/card'
import { prisma } from '@/app/lib/prisma'
import { formatDateTime } from '@/app/lib/utils'
import { getAuthSession } from '@/app/lib/permissions'
import { EventRegistrationButton } from './EventRegistrationButton'

export const dynamic = 'force-dynamic'

interface EventPageProps {
  params: Promise<{ id: string }>
}

export async function generateMetadata({ params }: EventPageProps): Promise<Metadata> {
  const { id } = await params
  const event = await prisma.event.findUnique({
    where: { id },
  })

  if (!event) {
    return { title: 'Événement non trouvé' }
  }

  return {
    title: event.title,
    description: event.description.replace(/<[^>]*>/g, '').slice(0, 160),
  }
}

export default async function EventPage({ params }: EventPageProps) {
  const { id } = await params
  const session = await getAuthSession()

  const event = await prisma.event.findUnique({
    where: { id, isPublished: true },
    include: {
      photos: true,
      registrations: {
        include: { user: { select: { id: true, name: true } } },
      },
      variants: {
        orderBy: { order: 'asc' },
        include: { choices: { orderBy: { order: 'asc' } } },
      },
    },
  })

  if (!event) {
    notFound()
  }

  const isPast = new Date(event.date) < new Date()
  const isRegistered = session?.user 
    ? event.registrations.some((r) => r.userId === session.user.id)
    : false

  return (
    <div className="container py-6 md:py-10">
      <Button asChild variant="ghost" className="mb-5 -ml-3">
        <Link href="/events">
          <ArrowLeft className="mr-2 h-4 w-4" />
          Retour aux événements
        </Link>
      </Button>

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-8 lg:gap-10">
        {/* Contenu principal */}
        <div className="space-y-8">
          {event.image && (
            <div className="relative aspect-[16/9] rounded-3xl overflow-hidden border border-white/[0.08] bg-white/[0.04]">
              <Image
                src={event.image}
                alt={event.title}
                fill
                className="object-cover"
              />
              {isPast && (
                <Badge 
                  variant="secondary" 
                  className="absolute top-4 right-4 px-4 py-2"
                >
                  Événement terminé
                </Badge>
              )}
            </div>
          )}

          <div className="surface p-6 md:p-8">
            <div className="eyebrow mb-4">
              <Calendar className="h-4 w-4" />
              Événement AEIC
            </div>
            <h1 className="text-4xl md:text-6xl font-black uppercase leading-[.95] tracking-[-.055em] mb-6 text-balance text-white">{event.title}</h1>

            <div className="grid sm:grid-cols-2 gap-3 mb-8">
              <div className="flex items-center gap-3 rounded-xl bg-muted/60 p-3.5">
                <div className="rounded-lg bg-primary/10 p-2 text-primary">
                  <Calendar className="h-4 w-4" />
                </div>
                <span className="text-sm font-semibold">{formatDateTime(event.date)}</span>
              </div>
              <div className="flex items-center gap-3 rounded-xl bg-muted/60 p-3.5">
                <div className="rounded-lg bg-primary/10 p-2 text-primary">
                  <MapPin className="h-4 w-4" />
                </div>
                <span className="text-sm font-semibold">{event.location}</span>
              </div>
            </div>

            <div
              className="prose prose-lg"
              dangerouslySetInnerHTML={{ __html: event.description }}
            />
          </div>

          {/* Galerie photos */}
          {event.photos.length > 0 && (
            <div className="surface p-6 md:p-8">
              <h2 className="flex items-center gap-2 text-2xl font-bold mb-5">
                <Camera className="h-5 w-5 text-primary" />
                Photos
              </h2>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                {event.photos.map((photo) => (
                  <div 
                    key={photo.id} 
                    className="relative aspect-square rounded-2xl overflow-hidden bg-muted"
                  >
                    <Image
                      src={photo.url}
                      alt={photo.caption || 'Photo'}
                      fill
                      className="object-cover hover:scale-105 transition-transform duration-500"
                    />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-5 lg:sticky lg:top-24 lg:self-start">
          {/* Actions */}
          {!isPast && (
            <Card className="overflow-hidden border-primary/20">
              <div className="h-1 bg-primary" />
              <CardContent className="p-6 space-y-4">
                <h3 className="font-bold text-xl">Participer</h3>
                <p className="text-sm text-muted-foreground">
                  Réserve ta place pour cet événement.
                </p>
                
                {session?.user ? (
                  <EventRegistrationButton
                    eventId={event.id}
                    isRegistered={isRegistered}
                    variants={event.variants}
                  />
                ) : (
                  <div className="text-center py-4">
                    <p className="text-muted-foreground mb-4">
                      Connectez-vous pour vous inscrire
                    </p>
                    <Button asChild className="w-full h-11">
                      <Link href={`/login?callbackUrl=/events/${event.id}`}>
                        Se connecter
                      </Link>
                    </Button>
                  </div>
                )}

                {event.sumupLink && (
                  <Button asChild variant="outline" className="w-full h-11">
                    <a href={event.sumupLink} target="_blank" rel="noopener noreferrer">
                      <ExternalLink className="mr-2 h-4 w-4" />
                      Payer en ligne
                    </a>
                  </Button>
                )}
              </CardContent>
            </Card>
          )}

          {/* Inscrits */}
          <Card>
            <CardContent className="p-6">
              <h3 className="flex items-center gap-2 font-bold text-lg mb-4">
                <Users className="h-5 w-5 text-primary" />
                Participants
                <Badge variant="secondary" className="ml-auto">{event.registrations.length}</Badge>
              </h3>
              {event.registrations.length > 0 ? (
                <ul className="space-y-1.5">
                  {event.registrations.slice(0, 10).map((reg) => (
                    <li key={reg.id} className="rounded-lg bg-muted/55 px-3 py-2 text-sm font-medium">
                      {reg.user.name || 'Utilisateur'}
                    </li>
                  ))}
                  {event.registrations.length > 10 && (
                    <li className="text-sm text-muted-foreground">
                      Et {event.registrations.length - 10} autres...
                    </li>
                  )}
                </ul>
              ) : (
                <p className="text-muted-foreground text-sm">
                  Aucun participant pour le moment
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
