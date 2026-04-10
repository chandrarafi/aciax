import { useState } from 'react'
import { router, Link } from '@inertiajs/react'
import { AuthLayout } from '@/features/auth/auth-layout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export default function Register() {
  const [data, setData] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [processing, setProcessing] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setProcessing(true)
    router.post('/register', data, {
      onError: (errors) => {
        setErrors(errors)
        setProcessing(false)
      },
      onFinish: () => setProcessing(false),
    })
  }

  return (
    <AuthLayout>
      <div className='flex flex-col gap-4'>
        <div className='text-center'>
          <h1 className='text-2xl font-semibold tracking-tight'>
            Create an account
          </h1>
          <p className='text-sm text-muted-foreground'>
            Enter your details below to create your account
          </p>
        </div>

        <form onSubmit={handleSubmit} className='grid gap-4'>
          <div className='grid gap-2'>
            <Label htmlFor='name'>Name</Label>
            <Input
              id='name'
              type='text'
              placeholder='Your name'
              value={data.name}
              onChange={(e) => setData({ ...data, name: e.target.value })}
              className={errors.name ? 'border-destructive' : ''}
            />
            {errors.name && (
              <p className='text-sm text-destructive'>{errors.name}</p>
            )}
          </div>
          <div className='grid gap-2'>
            <Label htmlFor='email'>Email</Label>
            <Input
              id='email'
              type='email'
              placeholder='name@example.com'
              autoComplete='email'
              value={data.email}
              onChange={(e) => setData({ ...data, email: e.target.value })}
              className={errors.email ? 'border-destructive' : ''}
            />
            {errors.email && (
              <p className='text-sm text-destructive'>{errors.email}</p>
            )}
          </div>
          <div className='grid gap-2'>
            <Label htmlFor='password'>Password</Label>
            <Input
              id='password'
              type='password'
              placeholder='********'
              value={data.password}
              onChange={(e) => setData({ ...data, password: e.target.value })}
              className={errors.password ? 'border-destructive' : ''}
            />
            {errors.password && (
              <p className='text-sm text-destructive'>{errors.password}</p>
            )}
          </div>
          <div className='grid gap-2'>
            <Label htmlFor='password_confirmation'>Confirm Password</Label>
            <Input
              id='password_confirmation'
              type='password'
              placeholder='********'
              value={data.password_confirmation}
              onChange={(e) =>
                setData({ ...data, password_confirmation: e.target.value })
              }
            />
          </div>
          <Button className='mt-2' disabled={processing}>
            {processing ? 'Creating account...' : 'Create account'}
          </Button>
        </form>

        <div className='text-center text-sm'>
          Already have an account?{' '}
          <Link href='/login' className='underline underline-offset-4'>
            Login
          </Link>
        </div>
      </div>
    </AuthLayout>
  )
}
