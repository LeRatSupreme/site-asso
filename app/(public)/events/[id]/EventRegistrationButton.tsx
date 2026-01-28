'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Button } from '@/app/components/ui/button'
import { toast } from '@/app/components/ui/use-toast'
import { registerToEvent, unregisterFromEvent } from '@/app/actions/registrations.actions'
import { CheckCircle } from 'lucide-react'

interface EventRegistrationButtonProps {
  eventId: string
  isRegistered: boolean
}

export function EventRegistrationButton({ 
  eventId, 
  isRegistered: initialIsRegistered 
}: EventRegistrationButtonProps) {
  const router = useRouter()
  const [isRegistered, setIsRegistered] = useState(initialIsRegistered)
  const [isLoading, setIsLoading] = useState(false)

  const handleToggleRegistration = async () => {
    setIsLoading(true)

    try {
      if (isRegistered) {
        const result = await unregisterFromEvent(eventId)
        if (result.success) {
          setIsRegistered(false)
          toast({
            title: 'Désinscription réussie',
            description: 'Vous avez été désinscrit de cet événement',
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
            title: 'Inscription réussie',
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

  return (
    <Button 
      onClick={handleToggleRegistration}
      disabled={isLoading}
      variant={isRegistered ? 'secondary' : 'default'}
      className="w-full"
    >
      {isRegistered ? (
        <>
          <CheckCircle className="mr-2 h-4 w-4" />
          Inscrit - Cliquez pour annuler
        </>
      ) : (
        "S'inscrire"
      )}
    </Button>
  )
}
