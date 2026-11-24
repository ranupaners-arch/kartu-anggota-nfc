# Daftar Uji HP

Uji sebelum dipakai di kegiatan nyata. Catat hasil di [catatan-uji.md](catatan-uji.md): versi APK, merek HP &
versi Android, nomor langkah, dan **teks persis di layar** bila gagal. Siapkan: 2 HP ber-NFC (A & B), 3 kartu
NTAG215, server uji versi terbaru, akun Super Admin dan 2 akun Petugas Scan. Pakai data contoh.

## 1. Pembaruan dari APK lama — kerjakan lebih dulu

Sinkronkan semua scan tertunda di APK lama **sebelum** memperbarui.

- [ ] 1.1 HP A masih memakai APK lama dan sudah masuk sebagai petugas → pasang APK baru tanpa uninstall.
- [ ] 1.2 Buka app → **tetap masuk**, tidak diminta login (token dipindah ke penyimpanan terenkripsi).
- [ ] 1.3 Tutup paksa, buka lagi; lalu nyalakan ulang HP, buka lagi → tetap masuk.
- [ ] 1.4 Keluar → masuk lagi → normal.

## 2. Offline

- [ ] 2.1 Online: buka **Beranda**, tunggu ±10 detik, **jangan** buka layar Scan. Mode pesawat (nyalakan lagi NFC),
      tutup paksa, buka app → kegiatan tampil dengan label **Mode offline**.
- [ ] 2.2 Mulai scan → tempel kartu 1 → hasil **Offline**, antrean 1. Tempel lagi → *Sudah absen*.
- [ ] 2.3 Sambungkan internet, tetap di layar Scan → antrean terkirim ±30 detik; dashboard: Hadir/Telat benar.
- [ ] 2.4 Internet menyala, **server/Tailscale mati** → tempel kartu → hasil offline paling lama ±4 detik.
- [ ] 2.5 Scan offline, tunggu check-in **tutup**, baru sambungkan dan buka Beranda → antrean terkirim;
      Alpa di dashboard berubah jadi Hadir/Telat.
- [ ] 2.6 Scan offline lalu tutup paksa app dan nyalakan ulang HP → jumlah antrean tetap.
- [ ] 2.7 Offline dengan mode QR → pesan bahwa QR butuh internet.

## 3. Scan ditolak

- [ ] 3.1 HP A offline. Blokir kartu 2 di dashboard, **lalu** scan kartu 2 di HP A → sambungkan →
      Beranda: **1 scan ditolak server** → rincian berisi nama, kegiatan, alasan.
- [ ] 3.2 *Sudah ditangani* → Batal (tetap ada) → ulangi → Hapus catatan (hilang).

## 4. Ganti akun di satu HP

- [ ] 4.1 HP A, petugas 1: scan offline 1 kartu. Keluar (boleh tetap offline).
- [ ] 4.2 Online, masuk petugas 2 → **1 scan milik akun lain**; antrean **tidak** terkirim.
- [ ] 4.3 Keluar, masuk petugas 1 → terkirim; di dashboard tercatat atas petugas 1.
- [ ] 4.4 Setelah keluar lalu offline: daftar peserta & foto tidak tampil lagi.

## 5. Akses berakhir

Scan offline di HP A dulu (antrean 1), lalu:

- [ ] 5.1 **Cabut perangkat** A di dashboard → A online → kembali ke layar Masuk dengan pesan scan dikirim untuk ditinjau;
      login di A ditolak.
- [ ] 5.2 Dashboard → **Perlu Ditinjau → Akses Berakhir** → scan ada dan belum dihitung hadir → **Terima** → Hadir.
- [ ] 5.3 Di HP B ulangi dengan **ubah peran** petugas → scan masuk daftar yang sama → **Batalkan** → tidak tercatat.
- [ ] 5.4 Ulangi dengan **Cabut tugas** pada anggota yang bertugas → scan masuk daftar yang sama.
- [ ] 5.5 Ulangi dengan **nonaktifkan akun** → scan masuk daftar yang sama.

HP A tetap dicabut setelah 5.1: pakai HP B atau HP lain untuk uji berikutnya.

## 6. Regresi singkat

- [ ] 6.1 Terbitkan kartu 3 dari app → scan online → nama & foto tampil.
- [ ] 6.2 QR Saya (HP anggota) dipindai HP petugas → tercatat.
- [ ] 6.3 Layar anggota: tarik untuk memuat ulang di Kegiatan, Riwayat, Kartu, Notifikasi.
- [ ] 6.4 Dua HP scan bersamaan di satu kegiatan → tidak ada data ganda.

## Lolos bila

Semua langkah bagian 1–5 berhasil dan tidak ada scan hilang atau ganda. Satu saja gagal → jangan dipakai di kegiatan
nyata sebelum diperbaiki. Lanjutkan dengan [checklist hari-H](panduan/checklist-hari-h.md) untuk kegiatan ≥ 30 peserta.
