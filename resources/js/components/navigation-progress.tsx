import { useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'
import LoadingBar, { type LoadingBarRef } from 'react-top-loading-bar'

export function NavigationProgress() {
  const ref = useRef<LoadingBarRef>(null)

  useEffect(() => {
    const startHandler = router.on('start', () => {
      ref.current?.continuousStart()
    })
    const finishHandler = router.on('finish', () => {
      ref.current?.complete()
    })

    return () => {
      startHandler()
      finishHandler()
    }
  }, [])

  return (
    <LoadingBar
      color='var(--muted-foreground)'
      ref={ref}
      shadow={true}
      height={2}
    />
  )
}
