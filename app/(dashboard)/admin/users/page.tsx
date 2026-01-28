import { Metadata } from 'next'
import { prisma } from '@/app/lib/prisma'
import { UsersTable } from './UsersTable'

export const metadata: Metadata = {
  title: 'Gestion des utilisateurs',
  description: 'Gérez tous les utilisateurs',
}

export default async function AdminUsersPage() {
  const users = await prisma.user.findMany({
    orderBy: { createdAt: 'desc' },
    include: {
      _count: {
        select: {
          cafeteriaOrders: true,
          eventRegistrations: true,
        },
      },
    },
  })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Utilisateurs</h1>
        <p className="text-muted-foreground mt-2">
          Gérez les membres de l&apos;association
        </p>
      </div>

      <UsersTable users={users} />
    </div>
  )
}
