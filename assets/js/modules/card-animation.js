/**
 * CARD ANIMATION MODULE
 * Lightweight depth enhancement for the BPM org cards without adding new dependencies.
 */

function getDepthCards() {
    return document.querySelectorAll(
        '.bph-grid .org-card:not(.static-org-card):not(.organization-chart-card), ' +
        '.commission-card:not(.static-org-card):not(.organization-chart-card)'
    );
}

function applyDepthDefaults(card) {
    card.style.setProperty('--pointer-x', '50%');
    card.style.setProperty('--pointer-y', '50%');
    card.style.setProperty('--glow-x', '50%');
    card.style.setProperty('--glow-y', '50%');
    card.style.setProperty('--rotate-x', '0deg');
    card.style.setProperty('--rotate-y', '0deg');
}

function initializeDepthCard(card) {
    if (card.dataset.depthBound === 'true') {
        return;
    }

    const mediaReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const mediaHoverNone = window.matchMedia && window.matchMedia('(hover: none)').matches;

    const resetDepth = () => applyDepthDefaults(card);

    if (mediaReducedMotion || mediaHoverNone) {
        resetDepth();
        card.dataset.depthBound = 'true';
        return;
    }

    card.addEventListener('pointermove', (event) => {
        const rect = card.getBoundingClientRect();
        const px = Math.min(Math.max((event.clientX - rect.left) / rect.width, 0), 1);
        const py = Math.min(Math.max((event.clientY - rect.top) / rect.height, 0), 1);
        const rotateY = ((px - 0.5) * 12).toFixed(2);
        const rotateX = ((0.5 - py) * 12).toFixed(2);

        card.style.setProperty('--pointer-x', `${(px * 100).toFixed(2)}%`);
        card.style.setProperty('--pointer-y', `${(py * 100).toFixed(2)}%`);
        card.style.setProperty('--glow-x', `${(px * 100).toFixed(2)}%`);
        card.style.setProperty('--glow-y', `${(py * 100).toFixed(2)}%`);
        card.style.setProperty('--rotate-x', `${rotateX}deg`);
        card.style.setProperty('--rotate-y', `${rotateY}deg`);
    });

    card.addEventListener('pointerleave', resetDepth);
    card.addEventListener('pointercancel', resetDepth);
    card.addEventListener('blur', resetDepth);

    resetDepth();
    card.dataset.depthBound = 'true';
}

export function initCardAnimation() {
    const cards = Array.from(getDepthCards());

    if (!cards.length) {
        return;
    }

    cards.forEach((card) => {
        if (!card.style.opacity) {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        }
        initializeDepthCard(card);
    });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('visible');
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -20px 0px' });

        cards.forEach((card) => observer.observe(card));

        setTimeout(() => {
            cards.forEach((card) => {
                const rect = card.getBoundingClientRect();
                const isVisible = rect.top < window.innerHeight && rect.bottom > 0;

                if (isVisible && !card.classList.contains('visible')) {
                    card.classList.add('visible');
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                    observer.unobserve(card);
                }
            });
        }, 100);

        return;
    }

    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('visible');
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

export function refreshCardAnimation() {
    const cards = Array.from(getDepthCards()).filter((card) => !card.classList.contains('visible'));

    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('visible');
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    cards.forEach((card) => initializeDepthCard(card));
}

export function resetCardAnimation() {
    const cards = Array.from(getDepthCards());

    cards.forEach((card) => {
        card.classList.remove('visible');
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        applyDepthDefaults(card);
    });

    initCardAnimation();
}