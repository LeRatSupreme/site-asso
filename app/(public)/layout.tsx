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
  let maintenanceMode = false
  
  try {
    maintenanceMode = await isMaintenanceMode()
  } catch {
    // Fallback pendant le build statique
    maintenanceMode = false
  }
  
  // Si mode maintenance activé, vérifier si l'utilisateur est admin
  if (maintenanceMode) {
    try {
      const session = await auth()
      const isAdmin = session?.user?.role === 'ADMIN'
      
      // Les admins peuvent toujours accéder au site
      if (!isAdmin) {
        return <MaintenancePage />
      }
    } catch {
      // En cas d'erreur, afficher la page de maintenance
      return <MaintenancePage />
    }
  }

  return (
    <div className="flex min-h-screen min-w-0 flex-col pb-16 md:pb-0">
      <Navbar />
      <main className="min-w-0 flex-1">
        {children}
      </main>
      <Footer />
    </div>
  )
}
