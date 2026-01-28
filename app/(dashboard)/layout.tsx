import { Navbar } from '@/app/components/Navbar'
import MaintenancePage from '@/app/components/MaintenancePage'
import { isMaintenanceMode } from '@/app/lib/config'
import { auth } from '@/app/lib/auth'

export default async function DashboardLayout({
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
      
      // Seuls les admins peuvent accéder au dashboard en mode maintenance
      if (!isAdmin) {
        return <MaintenancePage />
      }
    } catch {
      // En cas d'erreur, afficher la page de maintenance
      return <MaintenancePage />
    }
  }

  return (
    <div className="min-h-screen flex flex-col">
      <Navbar />
      <div className="flex-1">
        {children}
      </div>
    </div>
  )
}
