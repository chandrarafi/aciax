import { useState } from 'react'
import { router, Link } from '@inertiajs/react'
import { AuthLayout } from '@/features/auth/auth-layout'
import { cn } from '@/lib/utils'
import { Button, buttonVariants } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export default function Login() {
  const [data, setData] = useState({ email: '', password: '', remember: false })
  const [processing, setProcessing] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setProcessing(true)
    router.post('/login', data, {
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
            Login to your account
          </h1>
          <p className='text-sm text-muted-foreground'>
            Enter your email and password below <br />
            to log into your account
          </p>
        </div>

        <form onSubmit={handleSubmit} className='grid gap-4'>
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
            <div className='flex items-center justify-between'>
              <Label htmlFor='password'>Password</Label>
            </div>
            <Input
              id='password'
              type='password'
              placeholder='********'
              autoComplete='current-password'
              value={data.password}
              onChange={(e) => setData({ ...data, password: e.target.value })}
              className={errors.password ? 'border-destructive' : ''}
            />
            {errors.password && (
              <p className='text-sm text-destructive'>{errors.password}</p>
            )}
          </div>
          <Button className='mt-2' disabled={processing}>
            {processing ? 'Logging in...' : 'Login'}
          </Button>
        </form>

        <div className='text-center text-sm'>
          Don't have an account?{' '}
          <Link href='/register' className='underline underline-offset-4'>
            Sign Up
          </Link>
        </div>
      </div>
    </AuthLayout>
  )
}
