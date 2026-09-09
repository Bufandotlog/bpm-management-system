export function initHeroParallax() {
    const heroVisual = document.querySelector('.hero-visual');
    const heroImage = document.querySelector('.hero-background img');
    const heroContent = document.querySelector('.hero-content');

    if (!heroVisual || !heroImage) return;

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

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function update() {
        if (prefersReducedMotion) {
            heroVisual.style.transform = 'scale(1)';
            heroVisual.style.filter = 'none';
            if (heroContent) {
                heroContent.style.transform = 'translate(-50%, -48%)';
                heroContent.style.opacity = '1';
            }
            return;
        }

        const scrollY = window.scrollY;
        const zoom = 1 + Math.min(scrollY / 500, 1) * 0.3;
        let blur;
        let brightness;
        if (scrollY <= 500) {
            blur = (scrollY / 500) * 10;
            brightness = 0.85;
        } else {
            const extraScroll = scrollY - 500;
            const extraFactor = Math.min(extraScroll / 500, 1);
            blur = 10 + (extraFactor * 10);
            brightness = 0.85 - (extraFactor * 0.3);
        }
        heroVisual.style.transform = `scale(${zoom})`;
        heroVisual.style.filter = `blur(${blur}px) brightness(${brightness}) contrast(1.0)`;
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
            heroVisual.style.transform = 'scale(1)';
            heroVisual.style.filter = 'none';
            if (heroContent) {
                heroContent.style.transform = 'translate(-50%, -48%)';
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