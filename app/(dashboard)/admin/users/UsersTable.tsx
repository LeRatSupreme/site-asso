'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { MoreHorizontal, Shield, ShieldOff, UserX, UserCheck } from 'lucide-react'
import { Button } from '@/app/components/ui/button'
import { Badge } from '@/app/components/ui/badge'
import { Input } from '@/app/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/app/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/app/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/app/components/ui/dropdown-menu'
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
import { getRoleLabel, getRoleBadgeColor } from '@/app/lib/roles'
import { updateUserRole, toggleUserActive } from '@/app/actions/users.actions'
import type { User, Role } from '@prisma/client'

interface UserWithCounts extends User {
  _count: {
    cafeteriaOrders: number
    eventRegistrations: number
  }
}

interface UsersTableProps {
  users: UserWithCounts[]
}

export function UsersTable({ users: initialUsers }: UsersTableProps) {
  const router = useRouter()
  const [users, setUsers] = useState(initialUsers)
  const [actionUser, setActionUser] = useState<{
    id: string
    action: 'role' | 'active'
    currentValue: Role | boolean
  } | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [filter, setFilter] = useState({
    search: '',
    role: 'all',
    status: 'all',
  })

  // Filtrage
  const filteredUsers = users.filter((user) => {
    const searchMatch =
      !filter.search ||
      user.name?.toLowerCase().includes(filter.search.toLowerCase()) ||
      user.email.toLowerCase().includes(filter.search.toLowerCase())

    const roleMatch = filter.role === 'all' || user.role === filter.role
    const statusMatch =
      filter.status === 'all' ||
      (filter.status === 'active' && user.isActive) ||
      (filter.status === 'inactive' && !user.isActive)

    return searchMatch && roleMatch && statusMatch
  })

  const handleRoleChange = async () => {
    if (!actionUser || actionUser.action !== 'role') return

    setIsLoading(true)
    const newRole = actionUser.currentValue === 'ADMIN' ? 'ELEVE' : 'ADMIN'

    try {
      const result = await updateUserRole(actionUser.id, newRole as Role)
      if (result.success) {
        setUsers((prev) =>
          prev.map((u) =>
            u.id === actionUser.id ? { ...u, role: newRole as Role } : u
          )
        )
        toast({
          title: 'Rôle mis à jour',
          description: `L'utilisateur est maintenant ${getRoleLabel(newRole as Role)}`,
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
      setIsLoading(false)
      setActionUser(null)
    }
  }

  const handleToggleActive = async () => {
    if (!actionUser || actionUser.action !== 'active') return

    setIsLoading(true)
    const newStatus = !actionUser.currentValue

    try {
      const result = await toggleUserActive(actionUser.id, newStatus)
      if (result.success) {
        setUsers((prev) =>
          prev.map((u) =>
            u.id === actionUser.id ? { ...u, isActive: newStatus } : u
          )
        )
        toast({
          title: newStatus ? 'Compte activé' : 'Compte désactivé',
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
      setIsLoading(false)
      setActionUser(null)
    }
  }

  return (
    <>
      {/* Filtres */}
      <div className="flex flex-col sm:flex-row gap-4 mb-4">
        <Input
          placeholder="Rechercher..."
          value={filter.search}
          onChange={(e) => setFilter((f) => ({ ...f, search: e.target.value }))}
          className="sm:max-w-xs"
        />
        <Select
          value={filter.role}
          onValueChange={(value) => setFilter((f) => ({ ...f, role: value }))}
        >
          <SelectTrigger className="sm:w-[150px]">
            <SelectValue placeholder="Rôle" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Tous les rôles</SelectItem>
            <SelectItem value="ADMIN">Admin</SelectItem>
            <SelectItem value="ELEVE">Élève</SelectItem>
          </SelectContent>
        </Select>
        <Select
          value={filter.status}
          onValueChange={(value) => setFilter((f) => ({ ...f, status: value }))}
        >
          <SelectTrigger className="sm:w-[150px]">
            <SelectValue placeholder="Statut" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Tous</SelectItem>
            <SelectItem value="active">Actif</SelectItem>
            <SelectItem value="inactive">Désactivé</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="border rounded-lg">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Utilisateur</TableHead>
              <TableHead>Rôle</TableHead>
              <TableHead>Inscriptions</TableHead>
              <TableHead>Commandes</TableHead>
              <TableHead>Date d&apos;inscription</TableHead>
              <TableHead>Statut</TableHead>
              <TableHead className="w-[70px]">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredUsers.length > 0 ? (
              filteredUsers.map((user) => (
                <TableRow key={user.id}>
                  <TableCell>
                    <div>
                      <p className="font-medium">{user.name || 'Sans nom'}</p>
                      <p className="text-sm text-muted-foreground">{user.email}</p>
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge className={getRoleBadgeColor(user.role)}>
                      {getRoleLabel(user.role)}
                    </Badge>
                  </TableCell>
                  <TableCell>{user._count.eventRegistrations}</TableCell>
                  <TableCell>{user._count.cafeteriaOrders}</TableCell>
                  <TableCell>{formatDate(user.createdAt)}</TableCell>
                  <TableCell>
                    <Badge variant={user.isActive ? 'success' : 'secondary'}>
                      {user.isActive ? 'Actif' : 'Désactivé'}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                          <MoreHorizontal className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={() =>
                            setActionUser({
                              id: user.id,
                              action: 'role',
                              currentValue: user.role,
                            })
                          }
                        >
                          {user.role === 'ADMIN' ? (
                            <>
                              <ShieldOff className="mr-2 h-4 w-4" />
                              Retirer admin
                            </>
                          ) : (
                            <>
                              <Shield className="mr-2 h-4 w-4" />
                              Promouvoir admin
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() =>
                            setActionUser({
                              id: user.id,
                              action: 'active',
                              currentValue: user.isActive,
                            })
                          }
                        >
                          {user.isActive ? (
                            <>
                              <UserX className="mr-2 h-4 w-4" />
                              Désactiver
                            </>
                          ) : (
                            <>
                              <UserCheck className="mr-2 h-4 w-4" />
                              Activer
                            </>
                          )}
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))
            ) : (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8">
                  <p className="text-muted-foreground">Aucun utilisateur</p>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      {/* Dialog changement de rôle */}
      <AlertDialog
        open={actionUser?.action === 'role'}
        onOpenChange={() => setActionUser(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Changer le rôle ?</AlertDialogTitle>
            <AlertDialogDescription>
              {actionUser?.currentValue === 'ADMIN'
                ? 'L\'utilisateur perdra ses droits d\'administration.'
                : 'L\'utilisateur aura accès au panel d\'administration.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isLoading}>Annuler</AlertDialogCancel>
            <AlertDialogAction onClick={handleRoleChange} disabled={isLoading}>
              {isLoading ? 'Mise à jour...' : 'Confirmer'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Dialog activation/désactivation */}
      <AlertDialog
        open={actionUser?.action === 'active'}
        onOpenChange={() => setActionUser(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {actionUser?.currentValue ? 'Désactiver' : 'Activer'} le compte ?
            </AlertDialogTitle>
            <AlertDialogDescription>
              {actionUser?.currentValue
                ? 'L\'utilisateur ne pourra plus se connecter.'
                : 'L\'utilisateur pourra à nouveau se connecter.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isLoading}>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleToggleActive}
              disabled={isLoading}
              className={
                actionUser?.currentValue
                  ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                  : ''
              }
            >
              {isLoading
                ? 'Mise à jour...'
                : actionUser?.currentValue
                ? 'Désactiver'
                : 'Activer'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
