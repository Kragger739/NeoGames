import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import './styles/app.css'
import './styles/ddf.css'
import './styles/avatar.css'
import './styles/cosmetics.css'
import './styles/workshop.css'
import App from './App.tsx'
import { initTheme } from './stores/themeStore'

initTheme()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
)
