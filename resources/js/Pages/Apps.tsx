import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Apps as AppsContent } from '@/features/apps'

export default function Apps() {
  return (
    <AuthenticatedLayout>
      <AppsContent />
    </AuthenticatedLayout>
  )
}
