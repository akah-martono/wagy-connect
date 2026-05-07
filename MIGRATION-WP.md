# 🚀 Wagy API Migration Guide for WordPress Plugin (Deep Dive)

Dokumen ini berisi instruksi spesifik untuk memperbarui fitur-fitur utama pada Plugin WordPress agar sesuai dengan Wagy API v1 (SaaS Architecture). Panduan ini membandingkan API lama (`API-ori.md`) dengan implementasi terbaru.

## 1. Parameter Pengiriman Pesan (`POST /:device_id/send`)

**Perubahan Kritis pada Request Body:**

| Parameter Lama (di API-ori) | Parameter Baru (v1) | Status |
| :--- | :--- | :--- |
| `recipient` | `phone` | **BREAKING**: Harus diubah. |
| `expired_in` | `expires_in` | **BREAKING**: Perubahan akhiran dari `-ed` ke `-es`. |
| `expired_at` | `expires_at` | **BREAKING**: Perubahan akhiran dari `-ed` ke `-es`. |
| `media_url` | `media_url` | Aman (Tidak berubah). |
| `retry_interval` | `retry_interval` | Aman (Tidak berubah). |

**Catatan Prioritas**:
- Jika `expires_at` dan `expires_in` dikirim bersamaan, sistem akan memprioritaskan `expires_at`.

## 2. Status Koneksi (`GET /:device_id/status`)
Struktur respons sekarang lebih kaya untuk mendukung pendeteksian akun WhatsApp Business:
- **Paired**: `paired` (boolean).
- **Connected**: `connected` (boolean).
- **Logged In**: `logged_in` (boolean).
- **User**: Nomor WA yang terhubung ada di field `user` (Admin/Global) atau `wa_user` (User-scoped).
- **PENTING**: Jika status device adalah `REJECTED_NOT_BUSINESS`, arahkan user untuk logout dan login kembali menggunakan akun **WhatsApp Business**.

## 3. Scan QR (`GET /:device_id/auth/qr/json`)
Jika plugin Anda menampilkan QR Code dalam UI WordPress:
- Gunakan endpoint `/auth/qr/json` untuk mendapatkan string Base64 gambar QR.
- String ada di `data.qr_code`. Formatnya sudah termasuk prefix data URI (`data:image/png;base64,...`), jadi bisa langsung dipasang di tag `<img>`.

## 4. List Quota (`GET /:device_id/quota`)
Respons kuota kini memisahkan antara kuota Gratis (Free) dan kuota Berbayar (PRO):
- **Struktur**: Respons ada di `data.summary`.
- **Free Quota**: Lihat di `data.summary.free_quota.remaining`.
- **PRO Quota**: Lihat di `data.summary.active_pro_quota.remaining`.
- **Reset At**: Gunakan `free_quota.reset_at` untuk menunjukkan kapan kuota gratis akan diperbarui otomatis.

## 5. Message Log (History) & Operations
- **List Pesan**: `GET /:device_id/messages`. Gunakan query param `page` dan `limit` untuk pagination. Total halaman ada di `meta.pages` (sebelumnya `total_pages`).
- **Cancel Pesan**: `DELETE /:device_id/messages/:id`. Hanya pesan dengan status `PENDING` yang bisa dibatalkan.
- **Resend**: Ambil data pesan lama, lalu kirim ulang menggunakan payload baru ke rute `POST /:device_id/send`.

## 6. Update Owner Info (`PUT /:device_id/owner`)
Digunakan untuk mencatat identitas pemilik instance di database Wagy:
- **Body (JSON)**: `{"email": "...", "whatsapp": "..."}`.
- Endpoint ini penting agar Admin Wagy bisa menghubungi pemilik device jika terjadi gangguan.

## 7. Autentikasi
Pastikan semua request di atas menyertakan header:
`Authorization: Bearer <DEVICE_TOKEN>`

---
**Catatan Teknis untuk Agent**:
Pastikan melakukan pengecekan error `402 Payment Required` pada fitur pengiriman pesan untuk memberi notifikasi kepada user WordPress bahwa kuota mereka telah habis.
