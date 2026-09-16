/**
 * MODULE: Produk Hukum Caption Fade Effect
 * Menyamakan fade dan zoom caption dengan halaman berita/kepengurusan.
 */

export function initHukumCaptionFade() {
    const heroCaption = document.querySelector('.page-hukum .hero-caption');
    if (!heroCaption) return;

    history.scrollRestoration = 'manual';
    window.scrollTo(0, 0);

    function updateCaptionFade() {
        const scrollY = window.scrollY;
        const captionOpacity = scrollY > 20
            ? Math.max(0, 1 - ((scrollY - 20) / 280))
            : 1;

        if (scrollY > 0 && scrollY < 300) {
            const textMove = Math.min(scrollY * 0.05, 15);
            const zoom = Math.max(0.85, 1 - (scrollY * 0.0005));
            heroCaption.style.transform = `translateY(-${textMove}px) scale(${zoom})`;
        } else if (scrollY >= 300) {
            heroCaption.style.transform = 'translateY(-15px) scale(0.85)';
        } else {
            heroCaption.style.transform = 'translateY(0) scale(1)';
        }

        heroCaption.style.opacity = captionOpacity;
        heroCaption.style.visibility = captionOpacity <= 0.01 ? 'hidden' : 'visible';
    }

    window.addEventListener('scroll', updateCaptionFade);
    updateCaptionFade();
    requestAnimationFrame(() => {
        window.scrollTo(0, 0);
        updateCaptionFade();
    });
}
