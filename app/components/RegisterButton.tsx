'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { useSession } from 'next-auth/react'
import { Loader2 } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { toast } from '@/app/components/ui/use-toast'
import { registerToEvent, unregisterFromEvent } from '@/app/actions/registrations.actions'

interface RegisterButtonProps {
  eventId: string
  isRegistered: boolean
  isFull: boolean
  isPast: boolean
}

export function RegisterButton({ eventId, isRegistered: initialIsRegistered, isFull, isPast }: RegisterButtonProps) {
  const router = useRouter()
  const { data: session, status } = useSession()
  const [isRegistered, setIsRegistered] = useState(initialIsRegistered)
  const [isLoading, setIsLoading] = useState(false)

  const handleClick = async () => {
    if (status === 'unauthenticated') {
      router.push(`/login?callbackUrl=/events/${eventId}`)
      return
    }

    setIsLoading(true)

    try {
      if (isRegistered) {
        const result = await unregisterFromEvent(eventId)
        if (result.success) {
          setIsRegistered(false)
          toast({
            title: 'Désinscription confirmée',
            description: 'Vous êtes désinscrit de cet événement',
            variant: 'success',
          })
        } else {
          toast({
            title: 'Erreur',
            description: result.error,
            variant: 'destructive',
          })
        }
      } else {
        const result = await registerToEvent(eventId)
        if (result.success) {
          setIsRegistered(true)
          toast({
            title: 'Inscription confirmée',
            description: 'Vous êtes inscrit à cet événement',
            variant: 'success',
          })
        } else {
          toast({
            title: 'Erreur',
            description: result.error,
            variant: 'destructive',
          })
        }
      }
      router.refresh()
    } catch {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  if (isPast) {
    return (
      <Button disabled className="w-full">
        Événement passé
      </Button>
    )
  }

  if (isFull && !isRegistered) {
    return (
      <Button disabled className="w-full">
        Complet
      </Button>
    )
  }

  return (
    <Button
      onClick={handleClick}
      disabled={isLoading}
      variant={isRegistered ? 'outline' : 'default'}
      className="w-full"
    >
      {isLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
      {isRegistered ? 'Se désinscrire' : "S'inscrire"}
    </Button>
  )
}
