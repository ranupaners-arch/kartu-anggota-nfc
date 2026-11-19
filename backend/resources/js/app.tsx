import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { ThemeProvider } from 'next-themes';
import { createRoot } from 'react-dom/client';

// Formulir gagal validasi server: fokus ke kolom bergalat pertama (di dialog yang terbuka bila ada),
// agar pengguna keyboard/pembaca layar langsung tahu apa yang salah.
router.on('error', () => {
    setTimeout(() => {
        const scope = document.querySelector<HTMLElement>('[role=dialog], [role=alertdialog]') ?? document;
        scope.querySelector<HTMLElement>('[aria-invalid=true]')?.focus();
    });
});

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
