import { Metadata } from 'next'
import { requireAuth } from '@/app/lib/permissions'
import { ProfileForm } from './ProfileForm'

export const metadata: Metadata = {
  title: 'Mon profil',
  description: 'Gérez votre profil',
}

export default async function ProfilePage() {
  const session = await requireAuth()

  return (
    <div className="container py-8">
      <div className="max-w-2xl mx-auto">
        <div className="mb-8">
          <h1 className="text-3xl font-bold">Mon profil</h1>
          <p className="text-muted-foreground mt-2">
            Gérez vos informations personnelles
          </p>
        </div>

        <ProfileForm user={session.user} />
      </div>
    </div>
  )
}
