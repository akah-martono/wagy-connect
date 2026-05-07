# Panduan Penggunaan Plugin Wagy Connect

> Plugin WordPress untuk keamanan dan pengiriman pesan WhatsApp, didukung oleh WAGY API (self-hosted WhatsApp Gateway).

---

## Daftar Isi

1. [Persyaratan Sistem](#1-persyaratan-sistem)
2. [Instalasi](#2-instalasi)
3. [Konfigurasi Awal (Settings → Tab Wagy)](#3-konfigurasi-awal)
4. [Menghubungkan WhatsApp (Status & Quota)](#4-menghubungkan-whatsapp)
5. [Dashboard Kuota](#5-dashboard-kuota)
6. [Fitur Keamanan (Settings → Tab Security)](#6-fitur-keamanan)
   - 6.1 [Custom Login URL & Admin Block](#61-custom-login-url--admin-block)
   - 6.2 [Two-Factor Authentication (2FA)](#62-two-factor-authentication-2fa)
   - 6.3 [Notifikasi Keamanan WhatsApp](#63-notifikasi-keamanan-whatsapp)
7. [Owner Info (Settings → Tab Owner Info)](#7-owner-info)
8. [Messages Log](#8-messages-log)
9. [Broadcast (Pesan Massal)](#9-broadcast)
10. [Integrasi Fluent Forms](#10-integrasi-fluent-forms)
11. [Developer Hooks](#11-developer-hooks)
12. [Troubleshooting / FAQ](#12-troubleshooting--faq)

---

## 1. Persyaratan Sistem

| Komponen | Minimum |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WAGY API Server | Instance yang sudah berjalan (self-hosted) |
| Ekstensi PHP | `openssl` (untuk enkripsi token) |

> **Penting:** Plugin ini adalah *client* untuk WAGY API. Anda **wajib** memiliki server WAGY API yang sudah aktif. Fitur Custom Login URL dan Admin Block dapat berjalan tanpa WAGY API.

---

## 2. Instalasi

1. Upload folder `wagy-connect` ke `/wp-content/plugins/`.
2. Buka **Plugins** di dashboard WordPress, lalu klik **Activate** pada Wagy Connect.
3. Menu **Wagy** akan muncul di sidebar admin.

---

## 3. Konfigurasi Awal

Buka **Wagy → Settings → Tab Wagy**.

### 3.1 API Settings

| Field | Keterangan | Contoh |
|---|---|---|
| **Base URL** | URL lengkap server WAGY API Anda | `https://wagy.example.com` |
| **Device ID** | ID perangkat WhatsApp yang terdaftar di server WAGY | `device_abc123` |
| **Client Token** | Token bearer untuk autentikasi API. **Disimpan terenkripsi** (AES-256-CBC) menggunakan secret key WordPress | `tok_xxxxxxxxxxxx` |

> Token dienkripsi otomatis sebelum disimpan ke database. Prefix `ENC_` menandakan token sudah terenkripsi.

### 3.2 Access Control

Mengatur hak akses per halaman (Status, Messages Log, Broadcast) dengan sistem yang fleksibel:

- **Standard Mode**: Memberikan akses berdasarkan Role (Administrator, Editor, Author) ATAU User spesifik.
- **Strict Mode**: Mengabaikan Role. Akses hanya diberikan secara ketat kepada User spesifik yang dipilih.

> Halaman utama **Settings** tetap hanya dapat dikelola oleh Administrator (`manage_options`). Halaman dengan Strict Mode hanya dapat dikelola oleh User yang ditunjuk.

### 3.3 Menyimpan Settings

Klik **Save Settings**. Setelah menyimpan kredensial API, cache status koneksi akan di-invalidasi otomatis agar admin notice selalu menampilkan status terbaru.

---

## 4. Menghubungkan WhatsApp

Buka **Wagy → Status**.

### Skenario Koneksi

| Kondisi | Tampilan |
|---|---|
| **Belum pernah dipasangkan** | Banner biru "Pair Your WhatsApp" + QR Code |
| **Pernah dipasangkan, tapi logout** | Banner kuning "Device Disconnected" + QR Code |
| **Terhubung & login** | Pesan sukses hijau + Dashboard Kuota |

### Cara Scan QR Code

1. Buka WhatsApp di HP → **Linked Devices** → **Link a Device**.
2. Scan QR code yang tampil di halaman Status.
3. Jika QR expired, klik tombol **Refresh QR**.

### Admin Notice Otomatis

- **API tidak terjangkau:** Notice merah muncul di semua halaman Wagy.
- **WhatsApp disconnect:** Notice kuning dengan tombol **Reconnect / View QR** muncul di halaman Wagy.
- **Belum dikonfigurasi:** Notice kuning mengarahkan ke Settings (muncul di semua halaman kecuali Settings).

---

## 5. Dashboard Kuota

Tampil di halaman **Status** saat WhatsApp terhubung. Menampilkan progress bar berwarna:

| Warna | Sisa Kuota |
|---|---|
| 🟢 Hijau | ≥ 50% |
| 🟡 Kuning | 20% – 49% |
| 🔴 Merah | < 20% |

### Tipe Kuota

**FREE Monthly Quota:**
- Reset otomatis setiap bulan.
- Menampilkan tanggal reset berikutnya.
- Pesan yang dikirim via kuota gratis akan menyertakan teks sponsor.

**PRO Vouchers:**
- Kuota berbasis invoice/voucher.
- Menampilkan kode voucher, sisa hari, dan tanggal kedaluwarsa.
- Dikonsumsi berdasarkan urutan kedaluwarsa (yang paling cepat habis dipakai duluan).
- PRO diprioritaskan sebelum FREE.

> Jika tidak ada kuota aktif, pesan akan tetap berstatus **PENDING** sampai kuota tersedia.

---

## 6. Fitur Keamanan

Buka **Wagy → Settings → Tab Security**.

### 6.1 Custom Login URL & Admin Block

#### Custom Login Slug

Menyembunyikan halaman login default `wp-login.php` dan menggantinya dengan slug kustom.

**Pengaturan:**
- Isi field **Custom Login Slug** dengan slug yang diinginkan, contoh: `my-login`
- URL login menjadi: `https://domain.com/my-login`
- Akses langsung ke `wp-login.php` akan di-redirect ke homepage.
- Biarkan kosong untuk menggunakan `wp-login.php` default.

**Aksi yang diizinkan tetap melewati `wp-login.php`:** `postpass`, `logout`, `confirmaction`.

#### Admin Block

Memblokir akses langsung ke `/wp-admin/` untuk pengguna yang belum login.

**Pengaturan:**
- **Admin Block Message:** Pesan yang ditampilkan (default: "Access denied.").
- Endpoint `admin-ajax.php` dan `admin-post.php` tetap dapat diakses (untuk kompatibilitas plugin/tema).

### 6.2 Two-Factor Authentication (2FA)

Menambahkan verifikasi OTP 6 digit saat login.

#### Mengaktifkan 2FA

1. Centang **Enable WAGY Two-Factor Authentication**.
2. Pilih role yang memerlukan 2FA di tabel **2FA Required Roles**.
3. Untuk setiap role, pilih **metode pengiriman OTP**:

| Metode | Keterangan |
|---|---|
| **WhatsApp (fallback to email)** | Kirim OTP via WhatsApp. Jika gagal atau nomor WA kosong, fallback ke email. |
| **Email only** | Kirim OTP hanya via email WordPress. |

4. Kustomisasi template pesan OTP di **2FA Message Template**. Gunakan placeholder `{otp}` untuk kode OTP.

#### Alur Login dengan 2FA

```
User login (username + password)
    ↓ password benar & role memerlukan 2FA
OTP 6 digit digenerate (valid 5 menit)
    ↓
OTP dikirim via WhatsApp/Email sesuai konfigurasi role
    ↓
User di-redirect ke form OTP
    ↓
User input OTP → jika benar → login berhasil
                → jika salah → 3x percobaan, lalu sesi di-invalidasi
```

#### Nomor WhatsApp User

Setiap user dapat mengisi nomor WhatsApp di profil mereka:
- Buka **Users → Profile** → bagian **WAGY Two-Factor Authentication**.
- Isi **WhatsApp Number** dengan format kode negara tanpa `+` (contoh: `628123456789`).
- Nomor ini disimpan di user meta key `wagy_2fa_whatsapp`.

### 6.3 Notifikasi Keamanan WhatsApp

Mengirim alert real-time ke admin saat terjadi event keamanan.

#### Pengaturan Dasar

| Field | Keterangan |
|---|---|
| **Admin WhatsApp Number** | Nomor WA admin penerima alert (format: `628xxx`) |
| **Admin Email (Fallback)** | Email fallback jika WhatsApp offline |
| **Monitored Roles** | Centang role yang ingin dimonitor |

#### Jenis Notifikasi

**1. Notify on New Login**
- Dikirim saat user dengan role yang dimonitor berhasil login.
- Token template: `{username}`, `{role}`, `{time}`, `{ip}`

**2. Notify on Password Change**
- Dikirim saat user mengubah password (via profil atau reset password).
- Token template: `{username}`, `{role}`, `{time}`

**3. Notify on New User Registration**
- Dikirim saat user baru mendaftar (jika role-nya dimonitor).
- Token template: `{username}`, `{email}`, `{time}`

**4. Notify on Brute-Force Attempts**
- Dikirim saat jumlah login gagal dari satu IP mencapai threshold.
- **Failure threshold:** Jumlah percobaan gagal sebelum alert (default: 5).
- Alert hanya dikirim **sekali** saat threshold tercapai (menghindari spam).
- Counter di-reset otomatis setelah 15 menit.
- Token template: `{username}`, `{ip}`, `{attempts}`, `{time}`

**5. System Update Alerts**
- Dikirim saat terdapat update baru untuk WordPress Core, Plugin, atau Theme yang tersedia, atau saat proses update otomatis telah selesai.
- Pengecekan ketersediaan update dilakukan otomatis menggunakan jadwal Cron.
- Token template: `{site_name}`, `{site_url}`, `{total_updates}`, `{update_list}`

> Semua notifikasi menggunakan mekanisme fallback: WhatsApp → Email. Jika WAGY offline, notifikasi dikirim via email.

---

## 7. Owner Info

Buka **Wagy → Settings → Tab Owner Info**.

Menyimpan informasi pemilik perangkat WhatsApp **di server WAGY** (bukan di WordPress).

| Field | Keterangan |
|---|---|
| **Owner Email** | Email untuk notifikasi inactivity/logout dari server WAGY |
| **Owner WhatsApp** | Nomor WA untuk notifikasi dari server WAGY |

**Kegunaan:**
- Server WAGY mengirim peringatan inactivity otomatis pada hari ke-50 dan ke-55.
- Server WAGY menghapus akun secara otomatis setelah 60 hari tidak aktif.
- Notifikasi logout dikirim langsung dari server WAGY (independen dari WordPress).

Klik **Save Owner Info** untuk menyimpan (menggunakan AJAX, tidak perlu reload halaman).

---

## 8. Messages Log

Buka **Wagy → Messages Log**.

Menampilkan semua pesan WhatsApp yang dikirim melalui WAGY API dalam tabel yang dapat difilter dan dipaginasi.

### Kolom Tabel

| Kolom | Keterangan |
|---|---|
| **Recipient** | Nomor penerima |
| **Message** | Isi pesan (klik untuk expand/collapse) |
| **Media** | Tipe media (image/video/audio/document/file) — klik untuk buka URL |
| **Status** | PENDING, SENT, FAILED, EXPIRED, CANCELLED |
| **Created / Sent At** | Waktu pembuatan dan pengiriman (zona waktu WordPress) |
| **Notes** | Catatan tambahan (error message, dll) |

### Filter

- **Status:** Filter berdasarkan status pesan.
- **Recipient Number:** Filter berdasarkan nomor penerima.
- **From / To:** Filter berdasarkan rentang tanggal.

### Bulk Actions

Tersedia saat memfilter status **PENDING** atau **EXPIRED**:

| Status Filter | Aksi Tersedia |
|---|---|
| PENDING | **Cancel** — Membatalkan pesan yang belum terkirim |
| EXPIRED | **Resend** — Mengirim ulang pesan yang sudah kedaluwarsa |

**Cara menggunakan Bulk Actions:**
1. Filter pesan berdasarkan status PENDING atau EXPIRED.
2. Centang pesan yang diinginkan (atau gunakan "Select All").
3. Pilih aksi dari dropdown **Bulk Actions**.
4. Klik **Apply**.

---

## 9. Broadcast

Buka **Wagy → Broadcast**.

Mengirim satu pesan WhatsApp ke banyak penerima sekaligus.

### Compose Message (Panel Kiri)

| Field | Keterangan |
|---|---|
| **Message** | Isi pesan. Mendukung placeholder dinamis `[1]`, `[2]`, dst. |
| **Media URL** | URL media publik (gambar, video, dokumen) — opsional |
| **Expires In** | Durasi kedaluwarsa: 1 Jam, 6 Jam, 24 Jam, 3 Hari, 7 Hari |
| **Retry Interval** | Jeda retry jika pengiriman gagal (dalam detik, default: 60) |

### Recipients (Panel Kanan)

#### Import Recipients

1. Pilih **Source** (Sumber Data) dari dropdown:
   - **WordPress Users: [Role Name]** — Mengambil user WordPress berdasarkan role tertentu (otomatis mendeteksi custom roles).
   - **WooCommerce Customers** — Muncul otomatis jika plugin WooCommerce aktif.
   - Sumber kustom lainnya yang didaftarkan oleh developer.
2. Klik **Import Numbers** — Nomor telepon dan nama penerima otomatis ditambahkan ke textarea dengan format `nomor;nama`.

#### Input Manual

Format: satu entry per baris.

```
nomor_telepon; field1; field2; ...
```

**Contoh:**
```
628123456789; Budi Santoso; 25 April 2026
628987654321; Siti Rahayu; 30 April 2026
```

Dengan template pesan: `Halo [1], pesanan Anda tanggal [2] sudah siap.`

Hasilnya:
- Budi: "Halo Budi Santoso, pesanan Anda tanggal 25 April 2026 sudah siap."
- Siti: "Halo Siti Rahayu, pesanan Anda tanggal 30 April 2026 sudah siap."

### Proses Pengiriman

1. Klik **Send Broadcast**.
2. Pesan dikirim dalam batch **25 nomor** per request (AJAX) untuk menghindari PHP timeout.
3. Tabel **Send Results** muncul secara live menampilkan status per nomor:
   - ✓ **Queued** — Berhasil masuk antrian
   - ✗ **Failed** — Gagal dikirim

---

## 10. Integrasi Fluent Forms

Mengirim pesan WhatsApp otomatis saat form Fluent Forms disubmit.

### Prasyarat

- Plugin **Fluent Forms** harus terinstal dan aktif.
- WAGY API harus sudah dikonfigurasi dan token valid.

### Konfigurasi

1. Buka form di Fluent Forms → **Settings & Integrations** → **Add New Integration**.
2. Pilih **Wagy WhatsApp**.
3. Isi field:

| Field | Keterangan |
|---|---|
| **Feed Name** | Nama integrasi (untuk identifikasi) |
| **WhatsApp Number** | Nomor penerima. Bisa statis (`628xxx`) atau dinamis (`{inputs.phone}`) |
| **Message** | Isi pesan. Mendukung shortcode Fluent Forms (`{inputs.name}`, dll) |
| **Media URL** | URL media (opsional) |
| **Expired In** | Kedaluwarsa dalam jam (opsional) |
| **Status** | Enable/Disable |

### Fitur Tambahan

- **Multi nomor:** Pisahkan nomor dengan koma untuk kirim ke beberapa penerima.
- **Auto-format:** Nomor yang diawali `0` otomatis dikonversi ke `62`.

---

## 11. Developer Hooks

### Action Hook: Kirim Pesan

```php
do_action( 'wagy_send_message', $phone, $message, $media_url, $args );
```

**Parameter:**

| # | Parameter | Tipe | Keterangan |
|---|---|---|---|
| 1 | `$phone` | string | Nomor penerima (contoh: `628123456789`) |
| 2 | `$message` | string | Isi pesan |
| 3 | `$media_url` | string | URL media (opsional, default: `''`) |
| 4 | `$args` | array | Argumen tambahan (opsional, default: `[]`) |

**Argumen tambahan (`$args`):**

| Key | Tipe | Default | Keterangan |
|---|---|---|---|
| `expired_in` | int | `86400` | Kedaluwarsa dalam detik |
| `expired_at` | string | — | Timestamp ISO 8601 (override `expired_in`) |
| `retry_interval` | int | `5` | Interval retry dalam detik |

**Contoh penggunaan:**

```php
// Kirim pesan sederhana
do_action( 'wagy_send_message', '628123456789', 'Halo dari WordPress!' );

// Kirim dengan media dan kedaluwarsa
do_action( 'wagy_send_message', '628123456789', 'Lihat foto ini', 'https://example.com/photo.jpg', [
    'expired_in' => 3600,
    'retry_interval' => 30,
]);
```

### Filter: Modifikasi Payload

```php
apply_filters( 'wagy_message_payload', $payload );
```

Memungkinkan modifikasi payload sebelum dikirim ke API.

```php
add_filter( 'wagy_message_payload', function( $payload ) {
    // Tambahkan signature di akhir setiap pesan
    $payload['message'] .= "\n\n— Dikirim via MySite";
    return $payload;
});
```

### Filter: Tambah Sumber Import Broadcast

Memungkinkan developer menambahkan sumber data kustom ke dropdown import di halaman Broadcast.

```php
apply_filters( 'wagy_broadcast_import_sources', $sources );
```

**Contoh Penggunaan:**

```php
add_filter( 'wagy_broadcast_import_sources', function( $sources ) {
    $sources[] = [
        'label'    => 'Data Peserta Event',
        'value'    => 'event_participants',
        'callback' => 'my_custom_event_importer'
    ];
    return $sources;
} );

function my_custom_event_importer( $source_value ) {
    // Return array of strings format "phone;name;field1;..."
    return [
        '628123456789;Andi;VIP',
        '628198765432;Budi;Regular',
    ];
}
```

---

## 12. Troubleshooting / FAQ

### "Cannot connect to Wagy API server"

- Pastikan **Base URL** benar dan server WAGY API dapat diakses dari server WordPress.
- Periksa apakah `wp_remote_get` tidak diblokir oleh firewall atau hosting.
- Pastikan **Device ID** dan **Client Token** sudah benar.

### "Wagy has not been configured yet"

- Buka **Wagy → Settings** dan isi semua field API Settings (Base URL, Device ID, Token).

### QR Code tidak muncul / "NOT_READY"

- QR code belum siap dari server. Tunggu beberapa detik lalu klik **Refresh QR**.
- QR code memiliki masa aktif singkat. Jika expired, refresh kembali.

### OTP tidak terkirim via WhatsApp

- Pastikan device WhatsApp dalam status **Connected & Logged In** (cek halaman Status).
- Pastikan user sudah mengisi nomor WhatsApp di profil mereka.
- Jika WhatsApp offline, OTP otomatis dikirim via email sebagai fallback.

### Pesan tetap PENDING

- Periksa kuota di dashboard Status. Jika kuota habis, pesan tidak akan diproses.
- Pastikan device WhatsApp terhubung dan aktif.

### Broadcast timeout

- Broadcast menggunakan sistem batch (25 nomor per request) untuk menghindari timeout.
- Jika tetap timeout, coba kurangi jumlah penerima atau tingkatkan `max_execution_time` PHP.

### Custom Login URL — terkunci dari admin

- Akses langsung ke `wp-login.php?action=logout` tetap diizinkan.
- Untuk menonaktifkan, hapus/rename plugin via FTP/file manager, atau hapus opsi `wagy_custom_login_slug` dari database (tabel `wp_options`).

### Apakah Client Token disimpan aman?

Ya. Token dienkripsi menggunakan **AES-256-CBC** dengan salt unik WordPress (`AUTH_KEY` dan `SECURE_AUTH_KEY`) sebelum disimpan ke database.

### Plugin ini bisa jalan tanpa WAGY API?

Fitur yang **tetap berjalan** tanpa WAGY API:
- Custom Login URL
- Admin Block

Fitur yang **memerlukan** WAGY API:
- WhatsApp 2FA
- Notifikasi keamanan via WhatsApp
- Messages Log
- Broadcast
- Fluent Forms Integration

---

## Struktur File Plugin

```
wagy-connect/
├── wagy-connect.php          # File utama plugin
├── functions.php             # Bootstrap: inisialisasi modul & hooks
├── readme.txt                # Metadata untuk WordPress.org
├── PANDUAN.md                # Panduan ini
├── languages/                # File terjemahan (i18n)
└── includes/
    ├── Autoloader.php        # PSR-4 autoloader
    ├── Wagy.php              # Core API client (enkripsi, kirim pesan, status)
    ├── Admin/
    │   ├── AccessControl.php # Pengaturan hak akses Strict / Standard
    │   ├── StatusPage.php    # Halaman Status & Quota
    │   ├── SettingsPage.php  # Halaman Settings (3 tab)
    │   ├── MessagesLogPage.php # Halaman Messages Log
    │   └── BroadcastPage.php # Halaman Broadcast
    ├── Security/
    │   ├── CustomLoginUrl.php        # Custom login URL & admin block
    │   ├── TwoFactorAuth.php         # WhatsApp/Email 2FA
    │   ├── SecurityNotifications.php # Alert keamanan real-time
    │   └── SystemUpdateNotifier.php  # Pengecekan ketersediaan update WP
    └── Integrations/
        └── FluentForms.php   # Integrasi Fluent Forms
```

---

*Wagy Connect v0.0.1 — Dibuat oleh [Akah](https://www.subarkah.com/)*
