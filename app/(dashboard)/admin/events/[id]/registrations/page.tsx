import { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { ArrowLeft, Calendar, MapPin, Users } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card'
import { Badge } from '@/app/components/ui/badge'
import { prisma } from '@/app/lib/prisma'
import { formatDate } from '@/app/lib/utils'
import { RegistrationsTable } from './RegistrationsTable'

interface RegistrationsPageProps {
  params: Promise<{ id: string }>
}

export async function generateMetadata({ params }: RegistrationsPageProps): Promise<Metadata> {
  const { id } = await params
  const event = await prisma.event.findUnique({ where: { id } })
  return {
    title: event ? `Inscriptions - ${event.title}` : 'Événement non trouvé',
  }
}

export default async function RegistrationsPage({ params }: RegistrationsPageProps) {
  const { id } = await params
  
  const event = await prisma.event.findUnique({
    where: { id },
    include: {
      registrations: {
        include: {
          user: {
            select: {
              id: true,
              name: true,
              email: true,
              image: true,
            }
          }
        },
        orderBy: {
          createdAt: 'desc'
        }
      },
      _count: {
        select: {
          registrations: true
        }
      }
    }
  })

  if (!event) {
    notFound()
  }

  const isPastEvent = new Date(event.date) < new Date()

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button asChild variant="ghost" size="icon">
          <Link href="/admin/events">
            <ArrowLeft className="h-4 w-4" />
          </Link>
        </Button>
        <div className="flex-1">
          <h1 className="text-3xl font-bold">Gestion des inscriptions</h1>
          <p className="text-muted-foreground mt-1">{event.title}</p>
        </div>
      </div>

      {/* Event Info Card */}
      <Card>
        <CardHeader>
          <div className="flex items-start justify-between">
            <div>
              <CardTitle className="text-xl">{event.title}</CardTitle>
              <CardDescription className="mt-1 flex items-center gap-4 flex-wrap">
                <span className="flex items-center gap-1">
                  <Calendar className="h-4 w-4" />
                  {formatDate(event.date)}
                </span>
                <span className="flex items-center gap-1">
                  <MapPin className="h-4 w-4" />
                  {event.location}
                </span>
                <span className="flex items-center gap-1">
                  <Users className="h-4 w-4" />
                  {event._count.registrations} inscrit(s)
                </span>
              </CardDescription>
            </div>
            <div className="flex gap-2">
              {isPastEvent && (
                <Badge variant="secondary">Passé</Badge>
              )}
              <Badge variant={event.isPublished ? 'default' : 'outline'}>
                {event.isPublished ? 'Publié' : 'Brouillon'}
              </Badge>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground line-clamp-2">
            {event.description}
          </p>
        </CardContent>
      </Card>

      {/* Registrations Table */}
      <Card>
        <CardHeader>
          <CardTitle>Liste des inscrits</CardTitle>
          <CardDescription>
            {event._count.registrations === 0 
              ? "Aucune inscription pour cet événement"
              : `${event._count.registrations} personne(s) inscrite(s)`
            }
          </CardDescription>
        </CardHeader>
        <CardContent>
          {event.registrations.length > 0 ? (
            <RegistrationsTable 
              registrations={event.registrations} 
              eventId={event.id}
            />
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              <Users className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p>Aucune inscription pour le moment</p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
