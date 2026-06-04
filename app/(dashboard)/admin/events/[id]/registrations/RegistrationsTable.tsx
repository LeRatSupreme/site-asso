'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Trash2 } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/app/components/ui/avatar'
import { Badge } from '@/app/components/ui/badge'
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

type RegistrationChoice = {
  id: string
  variant: { label: string }
  choice: { label: string }
}

interface Registration {
  id: string
  createdAt: Date
  user: {
    id: string
    name: string | null
    email: string | null
    image: string | null
  }
  choices: RegistrationChoice[]
}

interface RegistrationsTableProps {
  registrations: Registration[]
  eventId: string
  hasVariants: boolean
}

export function RegistrationsTable({ registrations, eventId, hasVariants }: RegistrationsTableProps) {
  const router = useRouter()
  const [deleteTarget, setDeleteTarget] = useState<Registration | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)

  const handleDelete = async () => {
    if (!deleteTarget) return
    setIsDeleting(true)
    try {
      const result = await removeEventRegistration(deleteTarget.id)
      if (result.success) {
        toast({
          title: 'Inscription supprimée',
          description: `${deleteTarget.user.name || deleteTarget.user.email} a été désinscrit`,
          variant: 'success',
        })
        router.refresh()
      } else {
        toast({ title: 'Erreur', description: result.error, variant: 'destructive' })
      }
    } catch {
      toast({ title: 'Erreur', description: 'Une erreur est survenue', variant: 'destructive' })
    } finally {
      setIsDeleting(false)
      setDeleteTarget(null)
    }
  }

  function getInitials(name: string | null, email: string | null) {
    if (name) return name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2)
    return email?.[0]?.toUpperCase() ?? 'U'
  }

  return (
    <>
      <div className="overflow-x-auto -mx-6">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-6">Participant</TableHead>
              <TableHead>Email</TableHead>
              {hasVariants && <TableHead>Options choisies</TableHead>}
              <TableHead>Inscrit le</TableHead>
              <TableHead className="w-[60px] pr-6" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {registrations.map((reg) => (
              <TableRow key={reg.id}>
                {/* User */}
                <TableCell className="pl-6">
                  <div className="flex items-center gap-3">
                    <Avatar className="h-8 w-8 flex-shrink-0">
                      <AvatarImage src={reg.user.image ?? undefined} alt={reg.user.name ?? ''} />
                      <AvatarFallback className="text-xs bg-primary/10 text-primary">
                        {getInitials(reg.user.name, reg.user.email)}
                      </AvatarFallback>
                    </Avatar>
                    <span className="font-medium text-sm">{reg.user.name || 'Sans nom'}</span>
                  </div>
                </TableCell>

                {/* Email */}
                <TableCell className="text-sm text-muted-foreground">
                  {reg.user.email || '—'}
                </TableCell>

                {/* Choices */}
                {hasVariants && (
                  <TableCell>
                    {reg.choices.length === 0 ? (
                      <span className="text-xs text-muted-foreground italic">Aucun choix</span>
                    ) : (
                      <div className="flex flex-wrap gap-1.5">
                        {reg.choices.map((c) => (
                          <Badge
                            key={c.id}
                            variant="secondary"
                            className="text-xs gap-1 font-normal"
                          >
                            <span className="text-muted-foreground">{c.variant.label}:</span>
                            <span className="font-semibold">{c.choice.label}</span>
                          </Badge>
                        ))}
                      </div>
                    )}
                  </TableCell>
                )}

                {/* Date */}
                <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                  {formatDate(reg.createdAt)}
                </TableCell>

                {/* Actions */}
                <TableCell className="pr-6">
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                    onClick={() => setDeleteTarget(reg)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <AlertDialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer cette inscription ?</AlertDialogTitle>
            <AlertDialogDescription>
              Désinscription de{' '}
              <strong>{deleteTarget?.user.name || deleteTarget?.user.email}</strong>.
              Cette action est irréversible.
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
