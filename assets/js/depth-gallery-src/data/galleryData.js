import FLOWER01 from '@/assets/flower-01.webp'
import FLOWER02 from '@/assets/flower-02.webp'
import FLOWER03 from '@/assets/flower-03.webp'
import FLOWER04 from '@/assets/flower-04.webp'
import FLOWER05 from '@/assets/flower-05.webp'

const defaultGalleryPlaneData = [
  {
    fallbackColor: '#feca4f',
    accentColor: '#feca4f',
    textureSrc: FLOWER01,
    title: 'Golden',
    subtitle: 'Card 01',
    position: { x: -0.9, y: 0 },
    backgroundColor: '#fffaf0',
    blob1Color: '#ffdf94',
    blob2Color: '#fce7c4',
    label: {
      word: 'golden',
      pms: 'PMS 135 C',
      color: '#2e2e2e',
    },
  },
  {
    fallbackColor: '#80455a',
    accentColor: '#80455a',
    textureSrc: FLOWER02,
    title: 'Violet',
    subtitle: 'Card 02',
    position: { x: 0.8, y: 0 },
    backgroundColor: '#fffaf0',
    blob1Color: '#d29a41',
    blob2Color: '#bb96af',
    label: {
      word: 'violet',
      pms: 'PMS 4985 C',
      color: '#2e2e2e',
    },
  },
  {
    fallbackColor: '#fa7b71',
    accentColor: '#fa7b71',
    textureSrc: FLOWER03,
    title: 'Afterglow',
    subtitle: 'Card 03',
    position: { x: -0.7, y: 0 },
    backgroundColor: '#5f81ab',
    blob1Color: '#f88b8d',
    blob2Color: '#cfbbdd',
    label: {
      word: 'afterglow',
      pms: 'PMS 170 C',
      color: '#f4f4f4',
    },
  },
  {
    fallbackColor: '#3c72c6',
    accentColor: '#3c72c6',
    textureSrc: FLOWER04,
    title: 'Cobalt',
    subtitle: 'Card 04',
    position: { x: 1, y: 0 },
    backgroundColor: '#5b9bc2',
    blob1Color: '#ffaa00',
    blob2Color: '#00e1ff',
    label: {
      word: 'cobalt',
      pms: 'PMS 660 C',
      color: '#f4f4f4',
    },
  },
  {
    fallbackColor: '#fdd895',
    accentColor: '#fdd895',
    textureSrc: FLOWER05,
    title: 'Meadow',
    subtitle: 'Card 05',
    position: { x: -0.7, y: 0 },
    backgroundColor: '#7d936e',
    blob1Color: '#fdd895',
    blob2Color: '#a5b599',
    label: {
      word: 'meadow',
      pms: 'PMS 7507 C',
      color: '#f4f4f4',
    },
  },
]

const serverCards = Array.isArray(globalThis.__DEPTH_GALLERY_CARDS__)
  ? globalThis.__DEPTH_GALLERY_CARDS__
  : []

const galleryPlaneData = serverCards.length
  ? serverCards.map((card, index) => {
      const template = defaultGalleryPlaneData[index % defaultGalleryPlaneData.length]
      return {
        ...template,
        textureSrc: card.image,
        title: card.title || `Card ${String(index + 1).padStart(2, '0')}`,
        subtitle: card.subtitle || '',
      }
    })
  : defaultGalleryPlaneData

export { galleryPlaneData }
