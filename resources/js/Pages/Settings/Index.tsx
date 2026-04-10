import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Settings } from '@/features/settings'
import { SettingsProfile } from '@/features/settings/profile'

export default function SettingsProfilePage() {
  return (
    <AuthenticatedLayout>
      <Settings>
        <SettingsProfile />
      </Settings>
    </AuthenticatedLayout>
  )
}
