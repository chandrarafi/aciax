import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'
import { Tasks as TasksContent } from '@/features/tasks'

export default function Tasks() {
  return (
    <AuthenticatedLayout>
      <TasksContent />
    </AuthenticatedLayout>
  )
}
