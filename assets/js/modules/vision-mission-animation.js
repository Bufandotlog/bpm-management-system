const clamp = (value, min = 0, max = 1) => Math.min(Math.max(value, min), max);
const easeInOut = (value) => value * value * (3 - 2 * value);

export function initVisionMissionAnimation() {
    const section = document.querySelector('.visi-misi-section');
    const cards = section?.querySelector('.visi-misi');

    if (!section || !cards) return;

    cards.classList.add('visi-misi-scroll-animated');

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        cards.style.setProperty('--visi-entry', '1');
        cards.style.setProperty('--misi-entry', '1');
        cards.style.setProperty('--visi-exit', '0');
        cards.style.setProperty('--misi-exit', '0');
        return;
    }

    let frameId = null;

    const updateAnimation = () => {
        frameId = null;

        const viewportHeight = window.innerHeight;
        const { top, height } = section.getBoundingClientRect();
        const progress = clamp((viewportHeight - top) / (viewportHeight + height));
        // Use longer, eased ranges so the cards move steadily instead of jumping
        // when a desktop snap section changes viewport position.
        const entryProgress = easeInOut(clamp(progress / 0.42));
        const exitProgress = easeInOut(clamp((progress - 0.7) / 0.3));

        cards.style.setProperty('--visi-entry', entryProgress.toString());
        cards.style.setProperty('--misi-entry', entryProgress.toString());
        cards.style.setProperty('--visi-exit', exitProgress.toString());
        cards.style.setProperty('--misi-exit', exitProgress.toString());
    };

    const requestUpdate = () => {
        if (frameId === null) {
            frameId = window.requestAnimationFrame(updateAnimation);
        }
    };

    window.addEventListener('scroll', requestUpdate, { passive: true });
    window.addEventListener('resize', requestUpdate);
    requestUpdate();
}
