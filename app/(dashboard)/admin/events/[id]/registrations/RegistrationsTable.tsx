'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Trash2, User, Mail, Calendar } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/app/components/ui/avatar'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/app/components/ui/table'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/app/components/ui/alert-dialog'
import { toast } from '@/app/components/ui/use-toast'
import { formatDate } from '@/app/lib/utils'
import { removeEventRegistration } from '@/app/actions/events.actions'

interface Registration {
  id: string
  createdAt: Date
  user: {
    id: string
    name: string | null
    email: string | null
    image: string | null
  }
}

interface RegistrationsTableProps {
  registrations: Registration[]
  eventId: string
}

export function RegistrationsTable({ registrations, eventId }: RegistrationsTableProps) {
  const router = useRouter()
  const [deleteRegistration, setDeleteRegistration] = useState<Registration | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)

  const handleDelete = async () => {
    if (!deleteRegistration) return

    setIsDeleting(true)
    try {
      const result = await removeEventRegistration(deleteRegistration.id)
      if (result.success) {
        toast({
          title: 'Inscription supprimée',
          description: `${deleteRegistration.user.name || deleteRegistration.user.email} a été désinscrit de l'événement`,
          variant: 'success',
        })
        router.refresh()
      } else {
        toast({
          title: 'Erreur',
          description: result.error,
          variant: 'destructive',
        })
      }
    } catch {
      toast({
        title: 'Erreur',
        description: 'Une erreur est survenue',
        variant: 'destructive',
      })
    } finally {
      setIsDeleting(false)
      setDeleteRegistration(null)
    }
  }

  const getInitials = (name: string | null, email: string | null) => {
    if (name) {
      return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2)
    }
    if (email) {
      return email[0].toUpperCase()
    }
    return 'U'
  }

  return (
    <>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Utilisateur</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Date d&apos;inscription</TableHead>
            <TableHead className="w-[100px]">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {registrations.map((registration) => (
            <TableRow key={registration.id}>
              <TableCell>
                <div className="flex items-center gap-3">
                  <Avatar className="h-8 w-8">
                    <AvatarImage 
                      src={registration.user.image || undefined} 
                      alt={registration.user.name || 'Utilisateur'} 
                    />
                    <AvatarFallback className="text-xs">
                      {getInitials(registration.user.name, registration.user.email)}
                    </AvatarFallback>
                  </Avatar>
                  <div>
                    <p className="font-medium">
                      {registration.user.name || 'Sans nom'}
                    </p>
                  </div>
                </div>
              </TableCell>
              <TableCell>
                <span className="text-muted-foreground">
                  {registration.user.email || '-'}
                </span>
              </TableCell>
              <TableCell>
                <span className="text-muted-foreground text-sm">
                  {formatDate(registration.createdAt)}
                </span>
              </TableCell>
              <TableCell>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10"
                  onClick={() => setDeleteRegistration(registration)}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {/* Dialog de confirmation de suppression */}
      <AlertDialog 
        open={!!deleteRegistration} 
        onOpenChange={(open) => !open && setDeleteRegistration(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer cette inscription ?</AlertDialogTitle>
            <AlertDialogDescription>
              Êtes-vous sûr de vouloir désinscrire{' '}
              <strong>{deleteRegistration?.user.name || deleteRegistration?.user.email}</strong>{' '}
              de cet événement ? Cette action est irréversible.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isDeleting}>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={isDeleting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isDeleting ? 'Suppression...' : 'Supprimer'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
