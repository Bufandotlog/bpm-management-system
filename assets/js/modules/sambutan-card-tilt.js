function resetTilt(card) {
    card.style.setProperty('--sambutan-tilt-x', '0deg');
    card.style.setProperty('--sambutan-tilt-y', '0deg');
}

function initializeTilt(card) {
    if (card.dataset.tiltBound === 'true') {
        return;
    }

    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const noHover = window.matchMedia?.('(hover: none)').matches;
    if (reducedMotion || noHover) {
        resetTilt(card);
        card.dataset.tiltBound = 'true';
        return;
    }

    card.addEventListener('pointermove', (event) => {
        const rect = card.getBoundingClientRect();
        const x = Math.min(Math.max((event.clientX - rect.left) / rect.width, 0), 1);
        const y = Math.min(Math.max((event.clientY - rect.top) / rect.height, 0), 1);

        card.style.setProperty('--sambutan-tilt-x', `${((0.5 - y) * 10).toFixed(2)}deg`);
        card.style.setProperty('--sambutan-tilt-y', `${((x - 0.5) * 12).toFixed(2)}deg`);
    });

    card.addEventListener('pointerleave', () => resetTilt(card));
    card.addEventListener('pointercancel', () => resetTilt(card));
    resetTilt(card);
    card.dataset.tiltBound = 'true';
}

export function initSambutanCardTilt() {
    document.querySelectorAll('.sambutan-foto').forEach(initializeTilt);
}
