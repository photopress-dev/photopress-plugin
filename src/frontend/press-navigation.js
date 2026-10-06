/**
 * Exposes the shared click-anywhere navigation to the lightbox
 * (modules/slideshow/assets/js/slideshow.js), which is not built.
 */
import { pressNavigation } from '../shared/press-navigation';

window.photopress = window.photopress || {};
window.photopress.pressNavigation = pressNavigation;
