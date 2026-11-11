import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'
import { setPageRoot } from './lib/page'
import './styles.css'

// El build deja el contenido ya escrito en el HTML (para buscadores y para quien no ejecuta scripts);
// aquí React lo reemplaza por la página interactiva.
setPageRoot(document.documentElement.dataset.root)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
