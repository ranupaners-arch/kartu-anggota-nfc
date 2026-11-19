# Kebijakan keamanan

## Melaporkan celah keamanan

**Jangan** membuka issue publik untuk celah keamanan. Laporkan secara privat lewat
**[Security → Report a vulnerability](https://github.com/ranupaners-arch/kartu-anggota-nfc/security/advisories/new)**
di repo ini.

Sertakan:
- bagian yang terdampak (backend/API, dashboard, portal anggota, app Android, deploy);
- langkah untuk mereproduksi dan dampaknya (mis. data anggota terbaca organisasi lain, kartu palsu diterima);
- versi/commit yang diuji.

Laporan akan dijawab secepatnya; perbaikan dirilis lewat pull request dan dicatat di advisory setelah aman.

## Versi yang didukung

Hanya versi terbaru di cabang `main` yang menerima perbaikan keamanan.

## Cakupan yang penting

- Pemalsuan kartu NFC atau QR anggota (tanda tangan HMAC, masa berlaku QR).
- Kebocoran data antarorganisasi atau antardivisi.
- Lolosnya pembatasan peran (Anggota / Petugas Scan / Admin Divisi / Super Admin), 2FA, atau wajib ganti password.
- Kebocoran data pribadi anggota (foto, bukti izin, ekspor).
