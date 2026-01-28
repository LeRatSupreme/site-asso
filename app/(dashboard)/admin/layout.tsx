import { DashboardLayout } from '@/app/components/DashboardLayout'
import { requireAdmin } from '@/app/lib/permissions'

export default async function AdminLayout({
  children,
}: {
  children: React.ReactNode
}) {
  await requireAdmin()

  return <DashboardLayout>{children}</DashboardLayout>
}
