export function initTypingAnimation() {
    const heroTitle = document.querySelector('.hero-title');
    const heroSub = document.querySelector('.hero-sub');

    if (!heroTitle || !heroSub) {
        return;
    }

    if (heroTitle.querySelector('.hero-title-line')) {
        heroTitle.style.opacity = '1';
        heroSub.style.opacity = '1';
        return;
    }

    const titleText = heroTitle.textContent.trim();
    const subText = heroSub.textContent.trim();

    heroTitle.textContent = '';
    heroSub.textContent = '';

    let titleIndex = 0;
    let subIndex = 0;
    let timeoutId;

    function typeTitle() {
        if (titleIndex < titleText.length) {
            heroTitle.textContent = titleText.substring(0, titleIndex + 1);
            titleIndex++;
            timeoutId = setTimeout(typeTitle, 25);
        } else {
            timeoutId = setTimeout(typeSub, 120);
        }
    }

    function typeSub() {
        if (subIndex < subText.length) {
            heroSub.textContent = subText.substring(0, subIndex + 1);
            subIndex++;
            timeoutId = setTimeout(typeSub, 18);
        }
    }

    timeoutId = setTimeout(typeTitle, 180);

    return () => {
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
    };
}