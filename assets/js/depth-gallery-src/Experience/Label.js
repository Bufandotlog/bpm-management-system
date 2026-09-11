import * as THREE from 'three'

class Label {
  constructor(gallery) {
    this.gallery = gallery
    this.overlayElements = new Map()
    this.projectedCorners = Array.from({ length: 4 }, () => new THREE.Vector3())
    this.worldCorner = new THREE.Vector3()
    this.cameraCorner = new THREE.Vector3()
    this.referenceCamera = new THREE.PerspectiveCamera()
    this.referenceSizes = new Map()
    this.activePlaneIndex = -1
  }

  createElement() {
    const element = document.createElement('div')
    element.className = 'depth-gallery-title-overlay'
    element.innerHTML = `
      <div class="depth-gallery-title"></div>
      <div class="depth-gallery-subtitle"></div>
    `
    return {
      element,
      titleElement: element.querySelector('.depth-gallery-title'),
      subtitleElement: element.querySelector('.depth-gallery-subtitle'),
    }
  }

  init() {
    if (this.overlayElements.size) return

    this.gallery.planes.forEach((_, index) => {
      const overlay = this.createElement()
      overlay.element.style.opacity = '0'
      this.overlayElements.set(index, overlay)
      this.gallery.scope?.append(overlay.element)
    })
  }

  projectPlaneQuad(plane, camera, canvasRect, viewportRect) {
    camera.updateMatrixWorld(true)
    plane.updateMatrixWorld(true)
    const corners = [
      [-1.5, 1.5],
      [1.5, 1.5],
      [1.5, -1.5],
      [-1.5, -1.5],
    ]
    const quad = []
    let isVisible = true

    corners.forEach(([x, y]) => {
      this.worldCorner.set(x, y, 0)
      plane.localToWorld(this.worldCorner)
      this.cameraCorner.copy(this.worldCorner).applyMatrix4(camera.matrixWorldInverse)
      if (-this.cameraCorner.z <= camera.near) {
        isVisible = false
      }
      const projected = this.projectedCorners[quad.length]
        .copy(this.worldCorner)
        .project(camera)
      if (
        !Number.isFinite(projected.x) ||
        !Number.isFinite(projected.y) ||
        !Number.isFinite(projected.z)
      ) {
        isVisible = false
      }

      const screenX =
        canvasRect.left -
        viewportRect.left +
        ((projected.x + 1) / 2) * canvasRect.width
      const screenY =
        canvasRect.top -
        viewportRect.top +
        ((1 - projected.y) / 2) * canvasRect.height

      quad.push({ x: screenX, y: screenY })
    })

    const [tl, tr, br, bl] = quad
    const distance = (first, second) =>
      Math.hypot(first.x - second.x, first.y - second.y)
    const apparentWidth = (distance(tl, tr) + distance(bl, br)) / 2
    const apparentHeight = (distance(tl, bl) + distance(tr, br)) / 2
    const center = quad.reduce(
      (result, point) => ({
        x: result.x + point.x / quad.length,
        y: result.y + point.y / quad.length,
      }),
      { x: 0, y: 0 }
    )

    return {
      quad,
      center,
      apparentWidth: Math.max(apparentWidth, 1),
      apparentHeight: Math.max(apparentHeight, 1),
      isVisible,
    }
  }

  getReferenceSize(plane, camera, canvasRect, viewportRect) {
    if (this.referenceSizes.has(plane)) return this.referenceSizes.get(plane)

    this.referenceCamera.copy(camera)
    this.referenceCamera.position.z = plane.position.z + 5
    this.referenceCamera.updateProjectionMatrix()
    this.referenceCamera.updateMatrixWorld()
    const geometry = this.projectPlaneQuad(
      plane,
      this.referenceCamera,
      canvasRect,
      viewportRect
    )
    if (!geometry.isVisible) {
      return {
        width: 1,
        height: 1,
      }
    }
    const size = {
      width: geometry.apparentWidth,
      height: geometry.apparentHeight,
    }
    this.referenceSizes.set(plane, size)
    return size
  }

  updatePlaneOverlay(index, camera, canvasRect, viewportRect) {
    const plane = this.gallery.planes[index]
    const overlay = this.overlayElements.get(index)
    if (!plane || !overlay) return

    const transitionOpacity = Number.isFinite(plane.userData.transitionOpacity)
      ? plane.userData.transitionOpacity
      : 0
    if (transitionOpacity <= 0.001) {
      overlay.element.style.opacity = '0'
      return
    }

    const geometry = this.projectPlaneQuad(plane, camera, canvasRect, viewportRect)
    if (!geometry.isVisible) {
      overlay.element.style.opacity = '0'
      return
    }
    const referenceSize = this.getReferenceSize(
      plane,
      camera,
      canvasRect,
      viewportRect
    )
    const widthScale = geometry.apparentWidth / referenceSize.width
    const heightScale = geometry.apparentHeight / referenceSize.height
    const scale = (widthScale + heightScale) / 2
    const [tl, tr, br, bl] = geometry.quad
    const isCardOnLeft = geometry.center.x < viewportRect.width / 2
    const textWidth = Math.min(viewportRect.width * 0.28, 260)
    const gap = 32
    const isCompact = viewportRect.width < 760
    const planeData = this.gallery.planeConfig[index]

    overlay.titleElement.textContent =
      planeData?.title || planeData?.label?.word || `Card ${index + 1}`
    overlay.subtitleElement.textContent =
      planeData?.subtitle || `Card ${String(index + 1).padStart(2, '0')}`
    overlay.element.classList.toggle('is-left', isCardOnLeft)
    overlay.element.classList.toggle('is-right', !isCardOnLeft)
    overlay.element.classList.toggle('is-compact', isCompact)
    overlay.element.style.transform = isCompact
      ? `scale(${scale})`
      : `translateY(-50%) scale(${scale})`
    overlay.element.style.transformOrigin = isCompact
      ? 'center top'
      : isCardOnLeft
        ? 'left center'
        : 'right center'

    const rightAnchorX = (tr.x + br.x) / 2
    const leftAnchorX = (tl.x + bl.x) / 2
    const anchorY = geometry.center.y
    let left = isCardOnLeft
      ? rightAnchorX + gap
      : leftAnchorX - textWidth - gap
    let top = anchorY
    if (isCompact) {
      left = geometry.center.x - textWidth / 2
      top = Math.max(tl.y, tr.y, br.y, bl.y) + gap
    }

    overlay.element.style.width = `${textWidth}px`
    overlay.element.style.left = `${THREE.MathUtils.clamp(
      left,
      16,
      Math.max(16, viewportRect.width - textWidth - 16)
    )}px`
    overlay.element.style.top = `${THREE.MathUtils.clamp(
      top,
      16,
      Math.max(16, viewportRect.height - 16)
    )}px`
    overlay.element.style.opacity = String(transitionOpacity)
  }

  update(camera = null) {
    if (!camera || !this.overlayElements.size || !this.gallery.scope) return

    const viewportRect = this.gallery.scope.getBoundingClientRect()
    const isViewportVisible = viewportRect.bottom > 0 && viewportRect.top < window.innerHeight
    if (!isViewportVisible) {
      this.overlayElements.forEach(({ element }) => {
        element.style.opacity = '0'
      })
      return
    }

    const canvas = this.gallery.scope.querySelector('[data-depth-gallery-canvas]')
    const blendData = this.gallery.getPlaneBlendData(camera.position.z)
    if (!canvas || !blendData) return

    const canvasRect = canvas.getBoundingClientRect()
    const indices = new Set([blendData.currentPlaneIndex, blendData.nextPlaneIndex])
    this.overlayElements.forEach(({ element }, index) => {
      if (!indices.has(index)) element.style.opacity = '0'
    })
    indices.forEach((index) => {
      this.updatePlaneOverlay(index, camera, canvasRect, viewportRect)
    })
    this.activePlaneIndex =
      blendData.blend >= 0.5
        ? blendData.nextPlaneIndex
        : blendData.currentPlaneIndex
  }

  resize() {
    this.referenceSizes.clear()
  }

  render() {}

  dispose() {
    this.overlayElements.forEach(({ element }) => element.remove())
    this.overlayElements.clear()
    this.referenceSizes.clear()
    this.activePlaneIndex = -1
  }
}

export { Label }
