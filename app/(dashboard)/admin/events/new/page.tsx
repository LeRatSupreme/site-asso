import { Metadata } from 'next'
import Link from 'next/link'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { EventForm } from '../EventForm'

export const metadata: Metadata = {
  title: 'Nouvel événement',
  description: 'Créer un nouvel événement',
}

export default function NewEventPage() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button asChild variant="ghost" size="icon">
          <Link href="/admin/events">
            <ArrowLeft className="h-4 w-4" />
          </Link>
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Nouvel événement</h1>
          <p className="text-muted-foreground mt-2">
            Créez un nouvel événement pour l&apos;association
          </p>
        </div>
      </div>

      <EventForm />
    </div>
  )
}
