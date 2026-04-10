import { createInertiaApp } from '@inertiajs/react'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from '@/context/theme-provider'
import { FontProvider } from '@/context/font-provider'
import { DirectionProvider } from '@/context/direction-provider'

createInertiaApp({
  title: (title) => title ? `${title} - Shadcn Admin` : 'Shadcn Admin',
  resolve: (name) =>
    resolvePageComponent(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx')
    ),
  setup({ el, App, props }) {
    const root = createRoot(el)
    root.render(
      <ThemeProvider>
        <FontProvider>
          <DirectionProvider>
            <App {...props} />
          </DirectionProvider>
        </FontProvider>
      </ThemeProvider>
    )
  },
  progress: {
    color: '#dc2626',
    showSpinner: false,
  },
})
