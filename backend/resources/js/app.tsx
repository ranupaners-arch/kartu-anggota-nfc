import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { ThemeProvider } from 'next-themes';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => (title ? `${title} · Kartu Anggota` : 'Kartu Anggota'),
    // Tiap halaman dimuat terpisah (code-splitting) agar bundle awal kecil.
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx');
        const load = pages[`./pages/${name}.tsx`];
        if (!load) throw new Error(`Halaman tidak ditemukan: ${name}`);
        return load() as never;
    },
    setup({ el, App, props }) {
        // Tema: Terang / Gelap / Ikuti sistem (bawaan), disimpan per perangkat (localStorage 'theme').
        createRoot(el).render(
            <ThemeProvider attribute="class" defaultTheme="system" enableSystem storageKey="theme" disableTransitionOnChange>
                <App {...props} />
            </ThemeProvider>,
        );
    },
    progress: { color: '#6366f1' },
});
