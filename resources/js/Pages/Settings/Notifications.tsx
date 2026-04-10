import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Settings } from '@/features/settings'
import { SettingsNotifications } from '@/features/settings/notifications'

export default function SettingsNotificationsPage() {
  return (
    <AuthenticatedLayout>
      <Settings>
        <SettingsNotifications />
      </Settings>
    </AuthenticatedLayout>
  )
}
