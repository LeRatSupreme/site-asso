import { Navbar } from '@/app/components/Navbar'
import { Footer } from '@/app/components/Footer'
import MaintenancePage from '@/app/components/MaintenancePage'
import { isMaintenanceMode } from '@/app/lib/config'
import { auth } from '@/app/lib/auth'

export default async function PublicLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const maintenanceMode = await isMaintenanceMode()
  
  // Si mode maintenance activé, vérifier si l'utilisateur est admin
  if (maintenanceMode) {
    const session = await auth()
    const isAdmin = session?.user?.role === 'ADMIN'
    
    // Les admins peuvent toujours accéder au site
    if (!isAdmin) {
      return <MaintenancePage />
    }
  }

  return (
    <div className="flex flex-col min-h-screen">
      <Navbar />
      <main className="flex-1">
        {children}
      </main>
      <Footer />
    </div>
  )
}
