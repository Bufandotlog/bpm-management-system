const ACCENT = '#ef4658';
const LINE = '#8b97ad';

const clamp = (value, min = 0, max = 1) => Math.min(Math.max(value, min), max);
const mapRange = (value, start, end) => clamp((value - start) / (end - start));

function smooth(value) {
    return value * value * (3 - (2 * value));
}

function initOrganizationChart() {
    const wrapper = document.querySelector('.static-org-chart-wrapper');
    if (!wrapper) return;

    const leader = wrapper.querySelector('.leader-card');
    const departments = Array.from(wrapper.querySelectorAll('.dept-card'));
    const commissions = Array.from(wrapper.querySelectorAll('.commission-card'));
    if (!leader || departments.length < 2 || commissions.length < 3) return;

    const cards = [leader, ...departments, ...commissions];
    cards.forEach((card) => {
        card.classList.add('static-org-card', 'organization-chart-card');
    });

    const chart = document.createElement('div');
    chart.className = 'organization-chart-stage';
    wrapper.parentNode.insertBefore(chart, wrapper);
    chart.appendChild(wrapper);

    const progress = document.createElement('div');
    progress.className = 'organization-chart-progress';
    progress.innerHTML = '<span data-stage="0">KETUA UMUM</span><b>→</b><span data-stage="1">PENGURUS INTI</span><b>→</b><span data-stage="2">KOMISI</span>';
    chart.prepend(progress);

    const container = document.createElement('div');
    container.className = 'organization-chart-canvas';
    chart.appendChild(container);
    container.appendChild(wrapper);

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.classList.add('organization-chart-connectors');
    svg.setAttribute('aria-hidden', 'true');
    container.prepend(svg);

    const paths = [];
    const addPath = (range, getPath) => {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', LINE);
        path.setAttribute('stroke-width', '2');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
        path.dataset.rangeStart = `${range[0]}`;
        path.dataset.rangeEnd = `${range[1]}`;
        path._getPath = getPath;
        svg.appendChild(path);
        paths.push(path);
    };

    let geometry = null;
    const measure = () => {
        const transforms = cards.map((card) => card.style.transform);
        cards.forEach((card) => {
            card.style.transform = 'none';
        });
        const base = container.getBoundingClientRect();
        const rel = (element) => {
            const rect = element.getBoundingClientRect();
            return {
                x: rect.left - base.left + rect.width / 2,
                top: rect.top - base.top,
                bottom: rect.bottom - base.top
            };
        };
        const leaderPoint = rel(leader);
        const departmentPoints = departments.map(rel);
        const commissionPoints = commissions.map(rel);
        const l2Top = Math.min(departmentPoints[0].top, departmentPoints[1].top);
        const l2Bottom = Math.max(departmentPoints[0].bottom, departmentPoints[1].bottom);
        const commissionTop = Math.min(...commissionPoints.map((point) => point.top));
        geometry = {
            width: base.width,
            height: base.height,
            center: leaderPoint.x,
            leaderBottom: leaderPoint.bottom,
            l2Bus: (leaderPoint.bottom + l2Top) / 2,
            l2Top,
            l2Bottom,
            departmentLeft: departmentPoints[0].x,
            departmentRight: departmentPoints[1].x,
            commissionBus: (l2Bottom + commissionTop) / 2,
            commissionTop,
            commission: commissionPoints.map((point) => point.x)
        };
        svg.setAttribute('viewBox', `0 0 ${geometry.width} ${geometry.height}`);
        paths.forEach((path) => {
            const d = path._getPath(geometry);
            path.setAttribute('d', d);
            const length = path.getTotalLength();
            path.style.strokeDasharray = `${length}`;
            path.dataset.length = `${length}`;
        });
        cards.forEach((card, index) => {
            card.style.transform = transforms[index];
        });
    };

    addPath([0.1, 0.2], (g) => `M${g.center},${g.leaderBottom} L${g.center},${g.l2Bus}`);
    addPath([0.18, 0.24], (g) => `M${g.center},${g.l2Bus} L${g.departmentLeft},${g.l2Bus}`);
    addPath([0.18, 0.24], (g) => `M${g.center},${g.l2Bus} L${g.departmentRight},${g.l2Bus}`);
    addPath([0.24, 0.3], (g) => `M${g.departmentLeft},${g.l2Bus} L${g.departmentLeft},${g.l2Top}`);
    addPath([0.24, 0.3], (g) => `M${g.departmentRight},${g.l2Bus} L${g.departmentRight},${g.l2Top}`);
    addPath([0.3, 0.42], (g) => `M${g.center},${g.l2Bus} L${g.center},${g.commissionBus}`);
    addPath([0.42, 0.48], (g) => `M${g.center},${g.commissionBus} L${g.commission[0]},${g.commissionBus}`);
    addPath([0.42, 0.48], (g) => `M${g.center},${g.commissionBus} L${g.commission[2]},${g.commissionBus}`);
    addPath([0.48, 0.54], (g) => `M${g.commission[0]},${g.commissionBus} L${g.commission[0]},${g.commissionTop}`);
    addPath([0.48, 0.54], (g) => `M${g.commission[1]},${g.commissionBus} L${g.commission[1]},${g.commissionTop}`);
    addPath([0.48, 0.54], (g) => `M${g.commission[2]},${g.commissionBus} L${g.commission[2]},${g.commissionTop}`);

    const particles = paths.map(() => {
        const particle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        particle.setAttribute('r', '4');
        particle.setAttribute('fill', ACCENT);
        particle.style.filter = `drop-shadow(0 0 7px ${ACCENT}) drop-shadow(0 0 12px ${ACCENT})`;
        particle.style.opacity = '0';
        svg.appendChild(particle);
        return particle;
    });

    const highlightRoutes = {
        leader: paths.map((_, index) => index),
        secretary: [0, 1, 3],
        treasurer: [0, 2, 4],
        commission0: [0, 5, 6, 8],
        commission1: [0, 5, 9],
        commission2: [0, 5, 7, 10]
    };
    const highlightParticles = highlightRoutes.secretary.map(() => {
        const particle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        particle.setAttribute('r', '4');
        particle.setAttribute('fill', ACCENT);
        particle.classList.add('bph-path-particle');
        particle.style.opacity = '0';
        svg.appendChild(particle);
        return particle;
    });
    let highlightAnimationFrame = 0;
    let highlightAnimationStartedAt = 0;
    let activeHighlightRoute = null;

    const animateHighlightPath = (timestamp) => {
        if (!activeHighlightRoute) {
            highlightAnimationFrame = 0;
            highlightParticles.forEach((particle) => {
                particle.style.opacity = '0';
            });
            return;
        }

        if (!highlightAnimationStartedAt) highlightAnimationStartedAt = timestamp;
        const animationDuration = activeHighlightRoute === 'leader' ? 3600 : 1800;
        const progress = ((timestamp - highlightAnimationStartedAt) % animationDuration) / animationDuration;
        const route = highlightRoutes[activeHighlightRoute].map((index) => ({
            path: paths[index],
            length: Number(paths[index].dataset.length)
        }));
        const routeLength = route.reduce((total, segment) => total + segment.length, 0);

        highlightParticles.forEach((particle, particleIndex) => {
            let distance = (progress * routeLength + (particleIndex * routeLength) / highlightParticles.length) % routeLength;
            let point = null;

            for (const segment of route) {
                if (distance <= segment.length) {
                    point = segment.path.getPointAtLength(distance);
                    break;
                }
                distance -= segment.length;
            }

            if (!point) {
                const finalSegment = route[route.length - 1];
                point = finalSegment.path.getPointAtLength(finalSegment.length);
            }

            particle.style.opacity = '1';
            particle.setAttribute('cx', `${point.x}`);
            particle.setAttribute('cy', `${point.y}`);
        });
        highlightAnimationFrame = requestAnimationFrame(animateHighlightPath);
    };

    const setBphPathHighlight = (role) => {
        activeHighlightRoute = role;
        leader.classList.toggle('is-bph-highlight', Boolean(role));
        departments.forEach((card, index) => {
            const departmentRole = index === 0 ? 'secretary' : 'treasurer';
            card.classList.toggle('is-bph-highlight', role === 'leader' || role === departmentRole);
        });
        commissions.forEach((card, index) => {
            card.classList.toggle('is-bph-highlight', role === 'leader' || role === `commission${index}`);
        });
        paths.forEach((path) => path.classList.remove('is-bph-highlight'));
        if (role) {
            highlightRoutes[role].forEach((index) => {
                paths[index].classList.add('is-bph-highlight');
            });
        }
        if (role && !highlightAnimationFrame) {
            highlightAnimationStartedAt = 0;
            highlightAnimationFrame = requestAnimationFrame(animateHighlightPath);
        }
    };

    const bindBphHighlight = (card, role) => {
        card.addEventListener('pointerenter', () => setBphPathHighlight(role));
        card.addEventListener('pointerleave', () => setBphPathHighlight(null));
        card.addEventListener('focus', () => setBphPathHighlight(role));
        card.addEventListener('blur', () => setBphPathHighlight(null));
    };

    bindBphHighlight(leader, 'leader');
    bindBphHighlight(departments[0], 'secretary');
    bindBphHighlight(departments[1], 'treasurer');
    commissions.forEach((card, index) => {
        bindBphHighlight(card, `commission${index}`);
    });

    const update = () => {
        if (!geometry) measure();
        const rect = chart.getBoundingClientRect();
        const available = Math.max(chart.offsetHeight - window.innerHeight, 1);
        const value = clamp(-rect.top / available);
        if (value >= 1 && !progress.classList.contains('is-complete')) {
            progress.classList.add('is-complete');
            progress.style.top = '0';
        } else if (value < 0.98 && progress.classList.contains('is-complete')) {
            progress.classList.remove('is-complete');
            progress.style.top = '';
        }
        cards.forEach((card, index) => {
            let amount = index === 0 ? mapRange(value, 0, 0.1)
                : index <= 2 ? mapRange(value, 0.28, 0.4)
                : index === 4 ? mapRange(value, 0.52, 0.62)
                : mapRange(value, 0.54, 0.64);
            amount = smooth(amount);
            const direction = index === 1 ? -48 : index === 2 ? 48 : index === 3 ? -48 : index === 5 ? 48 : 0;
            card.style.opacity = `${amount}`;
            card.style.transform = `translate3d(${direction * (1 - amount)}px, ${(1 - amount) * 24}px, 0) scale(${0.94 + amount * 0.06})`;
        });
        paths.forEach((path, index) => {
            const amount = mapRange(value, Number(path.dataset.rangeStart), Number(path.dataset.rangeEnd));
            const length = Number(path.dataset.length);
            const particle = particles[index];
            path.style.strokeDashoffset = `${length * (1 - amount)}`;
            path.style.opacity = amount > 0 ? '1' : '0';
            if (amount > 0 && amount < 1) {
                const point = path.getPointAtLength(length * amount);
                particle.setAttribute('cx', `${point.x}`);
                particle.setAttribute('cy', `${point.y}`);
                particle.style.opacity = '1';
            } else {
                particle.style.opacity = '0';
            }
        });
        progress.querySelectorAll('[data-stage]').forEach((step) => {
            step.classList.toggle('is-active', Number(step.dataset.stage) === (value < 0.3 ? 0 : value < 0.5 ? 1 : 2));
        });
    };

    let frame = 0;
    const requestUpdate = () => {
        if (!frame) frame = requestAnimationFrame(() => {
            frame = 0;
            update();
        });
    };
    if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(() => {
            measure();
            requestUpdate();
        });
        resizeObserver.observe(container);
    }
    window.addEventListener('resize', () => {
        measure();
        requestUpdate();
    });
    window.addEventListener('scroll', requestUpdate, { passive: true });
    measure();
    update();
}

export { initOrganizationChart };
