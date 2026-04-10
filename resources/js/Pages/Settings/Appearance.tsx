import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Settings } from '@/features/settings'
import { SettingsAppearance } from '@/features/settings/appearance'

export default function SettingsAppearancePage() {
  return (
    <AuthenticatedLayout>
      <Settings>
        <SettingsAppearance />
      </Settings>
    </AuthenticatedLayout>
  )
}
