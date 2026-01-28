import Link from 'next/link'
import Image from 'next/image'
import { Calendar, MapPin, ExternalLink, ImageIcon } from 'lucide-react'
import { Card, CardContent, CardFooter, CardHeader } from '@/app/components/ui/card'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { formatDate, stripHtml, truncate, cn } from '@/app/lib/utils'
import type { Event } from '@prisma/client'

interface EventCardProps {
  event: Event
  showActions?: boolean
  isRegistered?: boolean
  onRegister?: () => void
  registrationLoading?: boolean
}

export function EventCard({ 
  event, 
  showActions = true,
  isRegistered = false,
  onRegister,
  registrationLoading = false,
}: EventCardProps) {
  const isPast = new Date(event.date) < new Date()
  const description = truncate(stripHtml(event.description), 150)

  return (
    <Card className={cn(
      "overflow-hidden group",
      isPast && "opacity-75 grayscale-[30%]"
    )}>
      {/* Image Container */}
      <div className="relative h-52 w-full overflow-hidden bg-gradient-to-br from-blue-100 to-violet-100 dark:from-blue-950 dark:to-violet-950">
        {event.image ? (
          <Image
            src={event.image}
            alt={event.title}
            fill
            className="object-cover transition-transform duration-500 group-hover:scale-105"
          />
        ) : (
          <div className="flex items-center justify-center h-full">
            <div className="p-4 rounded-full bg-gradient-to-br from-blue-500/10 to-violet-500/10">
              <ImageIcon className="h-12 w-12 text-blue-400" />
            </div>
          </div>
        )}
        
        {/* Overlay Gradient */}
        <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent" />
        
        {/* Date Badge */}
        <div className="absolute top-4 left-4">
          <div className="bg-white/90 dark:bg-gray-900/90 backdrop-blur-sm rounded-xl px-3 py-2 shadow-lg">
            <p className="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase">
              {new Date(event.date).toLocaleDateString('fr-FR', { month: 'short' })}
            </p>
            <p className="text-2xl font-bold text-gray-900 dark:text-white leading-none">
              {new Date(event.date).getDate()}
            </p>
          </div>
        </div>

        {/* Status Badge */}
        {isPast && (
          <Badge 
            className="absolute top-4 right-4 bg-gray-800/80 text-white border-0"
          >
            Terminé
          </Badge>
        )}
        
        {isRegistered && !isPast && (
          <Badge 
            className="absolute top-4 right-4 bg-gradient-to-r from-green-500 to-emerald-500 text-white border-0"
          >
            Inscrit ✓
          </Badge>
        )}
      </div>
      
      <CardHeader className="pb-2">
        <h3 className="text-xl font-bold line-clamp-2 group-hover:text-blue-600 transition-colors duration-200">
          {event.title}
        </h3>
        
        <div className="flex flex-wrap gap-3 text-sm text-muted-foreground mt-2">
          <div className="flex items-center gap-1.5">
            <Calendar className="h-4 w-4 text-blue-500" />
            <span>{formatDate(event.date, { hour: '2-digit', minute: '2-digit' })}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <MapPin className="h-4 w-4 text-violet-500" />
            <span className="truncate max-w-[150px]">{event.location}</span>
          </div>
        </div>
      </CardHeader>
      
      <CardContent className="pb-4">
        <p className="text-muted-foreground text-sm leading-relaxed">{description}</p>
      </CardContent>
      
      {showActions && (
        <CardFooter className="flex gap-2 pt-0">
          <Button asChild variant="outline" className="flex-1">
            <Link href={`/events/${event.id}`}>
              {isPast ? 'Voir le résumé' : 'Voir détails'}
            </Link>
          </Button>
          
          {isPast ? (
            <Button asChild variant="secondary" className="flex-1">
              <Link href={`/events/${event.id}/photos`}>
                <ImageIcon className="h-4 w-4 mr-2" />
                Photos
              </Link>
            </Button>
          ) : (
            <>
              {event.sumupLink && (
                <Button asChild className="flex-1">
                  <a href={event.sumupLink} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="h-4 w-4 mr-2" />
                    Payer
                  </a>
                </Button>
              )}
              
              {onRegister && (
                <Button 
                  onClick={onRegister}
                  disabled={registrationLoading || isRegistered}
                  variant={isRegistered ? "secondary" : "default"}
                  className="flex-1"
                >
                  {isRegistered ? "Inscrit ✓" : "S'inscrire"}
                </Button>
              )}
            </>
          )}
        </CardFooter>
      )}
    </Card>
  )
}
