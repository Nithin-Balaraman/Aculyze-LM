import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['IBM Plex Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            colors: {
                // Flat brand values for use in custom Blade views (e.g. the
                // Pipeline Pulse widget) via plain Tailwind utilities like
                // `bg-brand-coral/20`. Kept separate from the Filament
                // panel `->colors()` registration in AdminPanelProvider,
                // which drives Filament's own components (badges, buttons)
                // through CSS custom properties instead of Tailwind classes
                // — the two systems don't share config, so both need the
                // same hex values.
                'brand-blue': '#4174B9',
                'brand-navy': '#0E1131',
                'brand-navy-light': '#1B2A63',
                'brand-cyan': '#2DC4ED',
                'brand-coral': '#F0653C',
                'brand-gold': '#C99A3D',
                'brand-slateblue': '#5B7C99',
            },
        },
    },
}
