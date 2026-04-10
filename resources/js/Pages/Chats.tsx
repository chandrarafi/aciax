import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Chats as ChatsContent } from '@/features/chats'

export default function Chats() {
  return (
    <AuthenticatedLayout>
      <ChatsContent />
    </AuthenticatedLayout>
  )
}
