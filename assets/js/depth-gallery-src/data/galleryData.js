const serverCards = Array.isArray(globalThis.__DEPTH_GALLERY_CARDS__)
  ? globalThis.__DEPTH_GALLERY_CARDS__
  : []

const galleryPlaneData = serverCards.map((card, index) => ({
  fallbackColor: '#ffffff',
  accentColor: '#ffffff',
  textureSrc: card.image || '',
  title: card.title || `Card ${String(index + 1).padStart(2, '0')}`,
  subtitle: card.subtitle || '',
  position: { x: 0, y: 0 },
  backgroundColor: '#fffaf0',
  blob1Color: '#ffdf94',
  blob2Color: '#fce7c4',
}))

export { galleryPlaneData }
