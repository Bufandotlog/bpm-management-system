export function initHeroParallax() {
    const heroBackground = document.querySelector('.hero-background');
    const heroImage = document.querySelector('.hero-background img');
    const heroContent = document.querySelector('.hero-content');

    if (!heroBackground || !heroImage) return;

    if (window.__bpmHeroParallaxInitialized) {
        return;
    }
    window.__bpmHeroParallaxInitialized = true;

    if (heroImage.src) {
        sessionStorage.setItem('heroBgImage', heroImage.src);

        if (window.updateGlobalBackground) {
            window.updateGlobalBackground(heroImage.src);
        } else {
            document.dispatchEvent(new CustomEvent('heroImageReady', {
                detail: { src: heroImage.src }
            }));
        }
    }

    heroBackground.style.transformOrigin = 'center center';
    heroImage.style.transformOrigin = 'center center';
    heroImage.style.filter = 'none';
    heroBackground.style.transform = 'scale(1.08)';

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function update() {
        const scrollY = window.scrollY;
        const maxScroll = Math.min(window.innerHeight * 1.3, 700);
        const progress = Math.min(scrollY / maxScroll, 1);

        const zoom = 1.1 - progress * 0.1;
        const blur = progress * 7;
        const brightness = 0.92 - progress * 0.28;
        const contrast = 1.18 + progress * 0.2;

        heroBackground.style.transform = `scale(${zoom})`;
        heroBackground.style.filter = `blur(${blur}px) brightness(${brightness}) contrast(${contrast}) saturate(0.9)`;

        if (heroContent) {
            const textMove = Math.min(scrollY * 0.35, 180);
            heroContent.style.transform = `translate(-50%, calc(-50% - ${textMove}px))`;

            let opacity = 1;
            if (scrollY > 50) {
                opacity = Math.max(0, 1 - ((scrollY - 50) / 350));
            }
            heroContent.style.opacity = opacity;
        }
    }

    let rafId = null;
    function scheduleUpdate() {
        if (prefersReducedMotion) {
            heroBackground.style.transform = 'scale(1.02)';
            heroBackground.style.filter = 'blur(0px) brightness(0.9) contrast(1.18)';
            if (heroContent) {
                heroContent.style.transform = 'translate(-50%, -50%)';
                heroContent.style.opacity = '1';
            }
            return;
        }

        if (rafId) return;
        rafId = requestAnimationFrame(() => {
            update();
            rafId = null;
        });
    }

    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleUpdate, { passive: true });
    scheduleUpdate();

    console.log('✅ Hero Parallax diinisialisasi');
}