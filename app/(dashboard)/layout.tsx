import { Navbar } from '@/app/components/Navbar'
import MaintenancePage from '@/app/components/MaintenancePage'
import { isMaintenanceMode } from '@/app/lib/config'
import { auth } from '@/app/lib/auth'

export default async function DashboardLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const maintenanceMode = await isMaintenanceMode()
  
  // Si mode maintenance activé, vérifier si l'utilisateur est admin
  if (maintenanceMode) {
    const session = await auth()
    const isAdmin = session?.user?.role === 'ADMIN'
    
    // Seuls les admins peuvent accéder au dashboard en mode maintenance
    if (!isAdmin) {
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
