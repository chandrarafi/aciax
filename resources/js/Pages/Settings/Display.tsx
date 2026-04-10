import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Settings } from '@/features/settings'
import { SettingsDisplay } from '@/features/settings/display'

export default function SettingsDisplayPage() {
  return (
    <AuthenticatedLayout>
      <Settings>
        <SettingsDisplay />
      </Settings>
    </AuthenticatedLayout>
  )
}
