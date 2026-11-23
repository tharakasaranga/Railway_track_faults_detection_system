import './bootstrap';
import 'bootstrap';

const themeToggle = document.querySelector('#theme-toggle');
const themeToggleIcon = document.querySelector('#theme-toggle-icon');
const themeToggleLabel = document.querySelector('#theme-toggle-label');

if (themeToggle && themeToggleIcon && themeToggleLabel) {
    const updateThemeToggle = () => {
        const isDark = document.documentElement.dataset.bsTheme === 'dark';

        themeToggle.setAttribute('aria-pressed', String(isDark));
        themeToggle.setAttribute('aria-label', `Enable ${isDark ? 'light' : 'dark'} mode`);
        themeToggleIcon.textContent = isDark ? '☀️' : '🌙';
        themeToggleLabel.textContent = isDark ? 'Light mode' : 'Dark mode';
    };

    updateThemeToggle();

    themeToggle.addEventListener('click', () => {
        const theme = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.bsTheme = theme;

        try {
            localStorage.setItem('railway-theme', theme);
        } catch {
            // The selected theme still applies for this page when browser storage is unavailable.
        }

        updateThemeToggle();
    });
}