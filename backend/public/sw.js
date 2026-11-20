// Service worker portal anggota: hanya untuk notifikasi push (tidak menyimpan cache halaman).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'Kartu Anggota', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'Kartu Anggota', {
        body: data.body || '',
        icon: '/apple-touch-icon.png',
        badge: '/apple-touch-icon.png',
        tag: data.tag || undefined,
        data: { url: data.url || '/saya' },
    }));
});

// Ketuk notifikasi → buka (atau fokuskan) portal di halaman terkait.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/saya', self.location.origin).href;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const w of windows) {
            if (w.url.startsWith(self.location.origin)) {
                await w.focus();
                return w.navigate(url);
            }
        }
        return self.clients.openWindow(url);
    })());
});
