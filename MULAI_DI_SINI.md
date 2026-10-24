# sorerunapp — Tahap 06

Baca **docs/PANDUAN_TAHAP_06.md** terlebih dahulu dan ikuti urutannya.

Paket ini adalah perubahan untuk repository sorerunapp branch dev, berdasarkan commit `0aeb7e04ac36c67cfe1acfa6fe68681d015622c8`. Gabungkan folder backend, frontend, postman, dan docs ke root project setelah menyimpan perubahan lokal Anda. Jangan membuat ulang project, mengganti .env, atau meregenerasi APP_KEY.

MANIFEST.json mencatat file source dalam paket dan checksum-nya. Empat dokumen final utama tidak diubah. Dependency dan build output tidak disertakan; jalankan npm ci sesuai panduan.

Frontend dan test browser dengan fixture sudah diperiksa. PHPUnit/MySQL/SMTP nyata perlu dijalankan pada Laragon; lihat docs/VERIFICATION_STAGE_06.md. Paket ini tidak melakukan commit/push/deploy.
