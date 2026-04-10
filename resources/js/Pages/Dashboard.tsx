import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Dashboard as DashboardContent } from '@/features/dashboard'

export default function Dashboard() {
  return (
    <AuthenticatedLayout>
      <DashboardContent />
    </AuthenticatedLayout>
  )
}
