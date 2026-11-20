// Public landing page — lightweight bundle (Alpine only, no Echo / websockets).
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('themeToggle', () => ({
    dark: document.documentElement.classList.contains('dark'),
    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);
        try {
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        } catch (e) {}
    },
}));

Alpine.data('landingNav', () => ({
    open: false,
    scrolled: false,
    init() {
        const onScroll = () => (this.scrolled = window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    },
}));

Alpine.start();
