import '@/css/base.css'
import '@/css/canvas.css'

import { Engine } from '@/Experience/Engine'

const canvas = document.querySelector('[data-depth-gallery-canvas]')

if (!(canvas instanceof HTMLCanvasElement)) {
  throw new Error('Missing .webgl canvas element in index.html')
}

const engine = new Engine(canvas)

engine.init().catch((error) => {
  console.error('Engine initialization failed', error)
})
