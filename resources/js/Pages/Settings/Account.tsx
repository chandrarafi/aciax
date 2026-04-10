import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Settings } from '@/features/settings'
import { SettingsAccount } from '@/features/settings/account'

export default function SettingsAccountPage() {
  return (
    <AuthenticatedLayout>
      <Settings>
        <SettingsAccount />
      </Settings>
    </AuthenticatedLayout>
  )
}
