import { Metadata } from 'next'
import Image from 'next/image'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { Calendar, MapPin, ArrowLeft, ExternalLink } from 'lucide-react'
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
    <div className="container py-8">
      <Button asChild variant="ghost" className="mb-6">
        <Link href="/events">
          <ArrowLeft className="mr-2 h-4 w-4" />
          Retour aux événements
        </Link>
      </Button>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Contenu principal */}
        <div className="lg:col-span-2 space-y-6">
          {event.image && (
            <div className="relative h-64 md:h-96 rounded-lg overflow-hidden">
              <Image
                src={event.image}
                alt={event.title}
                fill
                className="object-cover"
              />
              {isPast && (
                <Badge 
                  variant="secondary" 
                  className="absolute top-4 right-4 text-lg px-4 py-2"
                >
                  Événement terminé
                </Badge>
              )}
            </div>
          )}

          <div>
            <h1 className="text-4xl font-bold mb-4">{event.title}</h1>
            
            <div className="flex flex-wrap gap-4 text-muted-foreground mb-6">
              <div className="flex items-center gap-2">
                <Calendar className="h-5 w-5" />
                <span>{formatDateTime(event.date)}</span>
              </div>
              <div className="flex items-center gap-2">
                <MapPin className="h-5 w-5" />
                <span>{event.location}</span>
              </div>
            </div>

            <div 
              className="prose prose-lg max-w-none"
              dangerouslySetInnerHTML={{ __html: event.description }}
            />
          </div>

          {/* Galerie photos */}
          {event.photos.length > 0 && (
            <div>
              <h2 className="text-2xl font-semibold mb-4">Photos</h2>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                {event.photos.map((photo) => (
                  <div 
                    key={photo.id} 
                    className="relative aspect-square rounded-lg overflow-hidden"
                  >
                    <Image
                      src={photo.url}
                      alt={photo.caption || 'Photo'}
                      fill
                      className="object-cover hover:scale-105 transition-transform"
                    />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Actions */}
          {!isPast && (
            <Card>
              <CardContent className="p-6 space-y-4">
                <h3 className="font-semibold text-lg">Participer</h3>
                
                {session?.user ? (
                  <EventRegistrationButton 
                    eventId={event.id}
                    isRegistered={isRegistered}
                  />
                ) : (
                  <div className="text-center py-4">
                    <p className="text-muted-foreground mb-4">
                      Connectez-vous pour vous inscrire
                    </p>
                    <Button asChild className="w-full">
                      <Link href={`/login?callbackUrl=/events/${event.id}`}>
                        Se connecter
                      </Link>
                    </Button>
                  </div>
                )}

                {event.sumupLink && (
                  <Button asChild variant="outline" className="w-full">
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
              <h3 className="font-semibold text-lg mb-4">
                Participants ({event.registrations.length})
              </h3>
              {event.registrations.length > 0 ? (
                <ul className="space-y-2">
                  {event.registrations.slice(0, 10).map((reg) => (
                    <li key={reg.id} className="text-sm">
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
