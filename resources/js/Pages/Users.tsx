import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Users as UsersContent } from '@/features/users'

export default function Users() {
  return (
    <AuthenticatedLayout>
      <UsersContent />
    </AuthenticatedLayout>
  )
}
