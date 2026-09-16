/**
 * MAIN SCRIPT - JavaScript untuk website BPM Kabinet Astawidya
 */

import { initMobileMenu } from './modules/mobile-menu.js';
import { initNavigation } from './modules/navigation.js';
import { initScrollEffects } from './modules/scroll-effects.js';
import { initSocialTooltip } from './modules/social-tooltip.js';
import { initFormValidation } from './modules/form-validation.js';
import { initBackToTop } from './modules/back-to-top.js';
import { initCardAnimation } from './modules/card-animation.js';
import { initHeroParallax } from './modules/hero-parallax.js';
import { initScrollReveal } from './modules/scroll-reveal.js?v=2.0.1';
import { initTypingAnimation } from './modules/typing-animation.js';
import { initBeritaCaptionFade } from './modules/berita-caption.js';
import { initKepengurusanCaptionFade } from './modules/kepengurusan-caption.js';
import { initHukumCaptionFade } from './modules/hukum-caption.js';
import { initKepengurusanDropdown } from './modules/kepengurusan-dropdown.js'; // BARU: Import dropdown module
import { initRelighting } from './modules/relighting.js';
import { initOrganizationChart } from './modules/organization-chart.js';
import { initSambutanCardTilt } from './modules/sambutan-card-tilt.js';
import { initVisionMissionAnimation } from './modules/vision-mission-animation.js';

import * as utilities from './modules/utilities.js';

document.addEventListener('DOMContentLoaded', function() {
    
    // Module lainnya
    initMobileMenu();
    initNavigation();
    initScrollEffects();
    initSocialTooltip();
    initFormValidation();
    initBackToTop();
    initOrganizationChart();
    initSambutanCardTilt();
    initVisionMissionAnimation();
    initCardAnimation();
    initHeroParallax();
    initScrollReveal();
    initTypingAnimation();
    initBeritaCaptionFade();
    initKepengurusanCaptionFade();
    initHukumCaptionFade();
    initKepengurusanDropdown(); // BARU: Inisialisasi custom dropdown
    initRelighting();
    
    console.log('✅ Semua modul JavaScript berhasil diinisialisasi');
    
});

// Export utilities untuk penggunaan global
window.confirmAction = utilities.confirmAction;
window.formatTanggalIndonesia = utilities.formatTanggalIndonesia;
window.copyToClipboard = utilities.copyToClipboard;