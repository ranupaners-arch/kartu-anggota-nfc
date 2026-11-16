import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
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
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#6366f1' },
});
