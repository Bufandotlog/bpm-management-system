const galleryCards = globalThis.__DEPTH_GALLERY_CARDS__

if (Array.isArray(galleryCards) && galleryCards.length === 0) {
  // The checked-in bundle still contains its legacy fixture; feed it a sparse
  // array so its mapping step produces no planes for an empty period.
  const emptyGalleryCards = new Array(1)
  globalThis.__DEPTH_GALLERY_CARDS__ = emptyGalleryCards

  import('./depth-gallery.js').finally(() => {
    globalThis.__DEPTH_GALLERY_CARDS__ = galleryCards
  })
} else {
  import('./depth-gallery.js')
}
