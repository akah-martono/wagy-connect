# 📖 Wagy API Reference V1 (Ultimate Edition)

Dokumen ini adalah referensi teknis final untuk integrasi pihak ketiga. Gunakan skema JSON di bawah ini sebagai acuan mutlak untuk pengembangan.

---

## 1. Standar Global

*   **Base URL:** `https://api.wagy.web.id/v1`
*   **Authentication:** `Authorization: Bearer <DEVICE_TOKEN>`
*   **Format Waktu:** ISO 8601 / RFC 3339 (Contoh: `2026-05-07T10:00:00Z`)

---

## 2. Endpoint Reference

### 2.1. Kirim Pesan (`POST /:device_id/send`)
Mengirimkan pesan teks atau media ke antrean pengiriman.

**Request Body (JSON):**
| Field | Tipe | Deskripsi |
| :--- | :--- | :--- |
| `phone` | string | **Wajib**. Nomor tujuan (misal: `628123456789`). |
| `message` | string | **Wajib**. Isi teks pesan. |
| `media_url` | string | Opsional. URL publik file. |
| `media_type` | string | Opsional. `image`, `video`, `document`, `audio`. |
| `expires_in` | int | Opsional. Detik dari sekarang sebelum kadaluarsa. |
| `expires_at` | string | Opsional. Waktu absolut kadaluarsa (RFC3339). |
| `retry_interval`| int | Opsional. Detik antar percobaan ulang (Default: 60). |

**Contoh Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "message_id": 12345,
    "quota_type": "PRO",
    "remaining": 499,
    "status": "PENDING"
  },
  "message": "Pesan masuk antrean"
}
```

---

### 2.2. Status Koneksi & Perangkat (`GET /:device_id/status`)
Mengecek kondisi real-time koneksi WhatsApp pada perangkat.

**Contoh Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "device_id": "DEVICE_123",
    "paired": true,
    "active": true,
    "connected": true,
    "logged_in": true,
    "user": "628123456789",
    "owner": {
      "email": "owner@example.com",
      "whatsapp": "62811223344",
      "last_active_at": "2026-05-07T07:00:00Z",
      "created_at": "2026-05-01T12:00:00Z"
    }
  }
}
```
*Note: `user` adalah JID nomor WhatsApp yang terhubung.*

---

### 2.3. Cek Saldo Kuota (`GET /:device_id/quota`)
Melihat rincian kuota GRATIS dan PRO yang aktif.

**Contoh Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "summary": {
      "device_id": "DEVICE_123",
      "free_quota": {
        "remaining": 150,
        "reset_at": "2026-06-01T00:00:00Z"
      },
      "active_pro_quota": {
        "remaining": 5000,
        "expires_at": "2026-12-31T23:59:59Z"
      }
    },
    "all_vouchers": [
      {
        "id": 1,
        "code": "FREE-MONTHLY",
        "type": "free",
        "total_quota": 500,
        "used_quota": 350,
        "expires_at": "2026-06-01T00:00:00Z"
      }
    ]
  }
}
```

---

### 2.4. Validasi Nomor WhatsApp (`POST /:device_id/check/whatsapp`)
Mengecek apakah nomor-nomor tujuan terdaftar di WhatsApp.

**Request Body (JSON):**
```json
{
  "phones": ["628123456789", "62899887766"]
}
```

**Contoh Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "phone": "628123456789",
      "is_registered": true,
      "jid": "628123456789@s.whatsapp.net"
    },
    {
      "phone": "62899887766",
      "is_registered": false,
      "jid": ""
    }
  ]
}
```

---

### 2.5. Log Pesan & Riwayat (`GET /:device_id/messages`)
Mengambil riwayat pengiriman pesan dengan filter dan pagination.

**Query Parameters:**
- `page`: Nomor halaman (Default: 1)
- `limit`: Jumlah data per halaman (Default: 10)
- `status`: Filter status (`PENDING`, `SENT`, `FAILED`, `CANCELLED`)

**Contoh Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 12345,
      "recipient": "628123456789",
      "message": "Halo!",
      "status": "SENT",
      "retry_count": 0,
      "created_at": "2026-05-07T06:00:00Z",
      "updated_at": "2026-05-07T06:00:05Z"
    }
  ],
  "meta": {
    "total": 150,
    "page": 1,
    "limit": 10,
    "pages": 15
  }
}
```

---

### 2.6. Manajemen Antrean & Detail

#### A. Detail Pesan (`GET /:device_id/messages/:id`)
Mengambil status detail satu pesan spesifik.
*Respon sama dengan objek di dalam array Log Pesan.*

#### B. Batalkan Pesan (`DELETE /:device_id/messages/:id`)
Hanya berlaku untuk pesan berstatus `PENDING`.
```json
{ "status": "success", "message": "Pesan berhasil dibatalkan" }
```

---

### 2.7. Fitur Perangkat Lainnya

#### A. Update Owner Info (`PUT /:device_id/owner`)
**Request Body:** `{"email": "new@mail.com", "whatsapp": "6281..."}`

#### B. Redeem Voucher (`POST /:device_id/redeem`)
**Request Body:** `{"code": "WAGY-PRO-CODE"}`

#### C. Get QR (JSON) (`GET /:device_id/auth/qr/json`)
**Response:** `{"status": "success", "data": {"qr_code": "data:image/png;base64,..."}}`

---

## 3. Webhook (Incoming Messages)

Setiap ada pesan masuk, Wagy akan mengirim `POST` ke URL Webhook Anda.

**Payload Webhook:**
```json
{
  "device_id": "DEVICE_123",
  "sender": "628123456789",
  "message": "Isi pesan masuk dari user",
  "timestamp": "2026-05-07T08:00:00Z",
  "push_name": "Nama User",
  "is_group": false
}
```

**Verifikasi Challenge (Handshake):**
Saat pendaftaran, server Anda harus merespon `GET` request yang mengandung query `challenge` dengan mengembalikan isi `challenge` tersebut sebagai plain text.
