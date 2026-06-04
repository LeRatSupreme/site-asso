import Link from 'next/link'
import Image from 'next/image'
import { ArrowRight, Calendar, MapPin } from 'lucide-react'
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

export function EventCard({ event, showActions = true, isRegistered = false }: EventCardProps) {
  const isPast = new Date(event.date) < new Date()

  return (
    <article className={cn('group flex h-full min-w-0 flex-col overflow-hidden rounded-[20px] border border-white/[0.08] bg-white/[0.04] transition-all duration-200 hover:-translate-y-1 hover:border-primary/25', isPast && 'opacity-60')}>
      <div className="flex items-center justify-between gap-3 border-b border-white/[0.07] px-4 py-3">
        <span className="min-w-0 truncate text-[9px] font-bold uppercase tracking-[.16em] text-muted-foreground">{event.location}</span>
        <span className="shrink-0 text-[10px] font-bold text-primary">{formatDate(event.date)}</span>
      </div>
      {event.image && (
        <div className="relative aspect-[16/8] overflow-hidden border-b border-white/[0.07]">
          <Image src={event.image} alt={event.title} fill className="object-cover opacity-75 transition duration-300 group-hover:scale-105 group-hover:opacity-100" />
        </div>
      )}
      <div className="flex flex-1 flex-col p-4">
        <div className="flex min-w-0 items-start justify-between gap-3">
          <h3 className="min-w-0 break-words text-xl font-black uppercase leading-[1] tracking-[-.045em] text-white">{event.title}</h3>
          {isRegistered && <Badge>Inscrit</Badge>}
          {isPast && <Badge variant="secondary">Terminé</Badge>}
        </div>
        <p className="mt-4 line-clamp-2 text-xs leading-6 text-muted-foreground">{truncate(stripHtml(event.description), 120)}</p>
        <div className="mt-5 grid grid-cols-2 gap-2">
          <div className="rounded-xl border border-white/[0.07] bg-white/[0.03] px-3 py-2">
            <Calendar className="size-3 text-primary" />
            <p className="mt-1 truncate text-[9px] font-bold uppercase tracking-[.1em] text-white/65">{formatDate(event.date, { hour: '2-digit', minute: '2-digit' })}</p>
          </div>
          <div className="rounded-xl border border-white/[0.07] bg-white/[0.03] px-3 py-2">
            <MapPin className="size-3 text-primary" />
            <p className="mt-1 truncate text-[9px] font-bold uppercase tracking-[.1em] text-white/65">{event.location}</p>
          </div>
        </div>
        {showActions && (
          <Link href={`/events/${event.id}`} className="mt-4 flex items-center justify-between rounded-xl bg-primary px-4 py-3 text-[10px] font-black uppercase tracking-[.14em] text-primary-foreground transition-colors hover:bg-white">
            Voir les détails <ArrowRight className="size-4" />
          </Link>
        )}
      </div>
    </article>
  )
}
