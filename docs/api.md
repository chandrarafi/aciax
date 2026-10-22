# ACIAX API Documentation

Dokumentasi lengkap seluruh Application Programming Interface (API) pada sistem backend **ACIAX (BPKB Management & Processing System)**.

---

## Daftar Isi

1. [Informasi Umum & Arsitektur](#1-informasi-umum--arsitektur)
2. [Autentikasi & Keamanan](#2-autentikasi--keamanan)
3. [Ringkasan Daftar Route (Route Index)](#3-ringkasan-daftar-route-route-index)
4. [Katalog Endpoint Lengkap](#4-katalog-endpoint-lengkap)
   - [4.1 Dealer & Validasi Kendaraan](#41-dealer--validasi-kendaraan)
     - [`GET /api/get-dealer`](#get-apiget-dealer)
   - [4.2 Pemrosesan Dokumen BPKB](#42-pemrosesan-dokumen-bpkb)
     - [`POST /api/bpkb/process`](#post-apibpkbprocess)
     - [`GET /api/bpkb/track/{track}`](#get-apibpkbtracktrack)
     - [`GET /api/bpkb/track/{track}/stream`](#get-apibpkbtracktrackstream)
   - [4.3 Unduh & Manajemen File PDF BPKB](#43-unduh--manajemen-file-pdf-bpkb)
     - [`GET /api/bpkb/file`](#get-apibpkbfile)
     - [`GET /api/bpkb/file/{track}`](#get-apibpkbfiletrack)
     - [`GET /api/bpkb/file-clean`](#get-apibpkbfile-clean)
     - [`GET /api/bpkb/file/{track}/clean`](#get-apibpkbfiletrackclean)
     - [`GET|POST /api/bpkb/remove-watermark`](#getpost-apibpkbremove-watermark)
     - [`GET /api/bpkb/file-info`](#get-apibpkbfile-info)
     - [`GET /api/bpkb/file-info/{track}`](#get-apibpkbfile-infotrack)
     - [`GET /bpkb/download/{track}`](#get-bpkbdownloadtrack)
   - [4.4 Retry / Mengulang Proses Gagal](#44-retry--mengulang-proses-gagal)
     - [`POST /api/bpkb/retry`](#post-apibpkbretry)
     - [`POST /api/bpkb/retry/{track}`](#post-apibpkbretrytrack)
     - [`POST /api/bpkb/retry-all-failed`](#post-apibpkbretry-all-failed)
   - [4.5 Monitoring & Riwayat](#45-monitoring--riwayat)
     - [`GET /api/bpkb/monitoring`](#get-apibpkbmonitoring)
     - [`GET /api/bpkb/recent`](#get-apibpkbrecent)
     - [`GET /api/activity`](#get-apiactivity)
     - [`GET /api/activity/today`](#get-apiactivitytoday)
   - [4.6 Pengaturan Dokumen Scan](#46-pengaturan-dokumen-scan)
     - [`GET /api/bpkb/settings/scan-effect`](#get-apibpkbsettingsscan-effect)
     - [`POST /api/bpkb/settings/scan-effect`](#post-apibpkbsettingsscan-effect)
   - [4.7 User / Profil](#47-user--profil)
     - [`GET /api/user`](#get-apiuser)
   - [4.8 Rekap & Agregasi Dealer](#48-rekap--agregasi-dealer)
     - [`GET /api/bpkb/by-dealer`](#get-apibpkbby-dealer)
   - [4.9 Data BASTD Detail](#49-data-bastd-detail)
     - [`GET /api/bastd-detail`](#get-apibastd-detail)
     - [`GET /api/bastd-detail/{no_mesin}`](#get-apibastd-detailno_mesin)
5. [Algoritma Cleansing Nomor Mesin & Akurasi Levenshtein](#5-algoritma-cleansing-nomor-mesin--akurasi-levenshtein)
6. [Siklus Hidup Status & Tahapan (Life Cycle)](#6-siklus-hidup-status--tahapan-life-cycle)
7. [Panduan Integrasi & Kode Sampel](#7-panduan-integrasi--kode-sampel)
8. [Tabel Kode Respon & Error Handling](#8-tabel-kode-respon--error-handling)

---

## 1. Informasi Umum & Arsitektur

* **Base URL:** `http://<server-ip-or-domain>:8000/api`
* **Content Type (Default):** `application/json`
* **File Uploads:** `multipart/form-data`
* **Realtime Streaming:** `text/event-stream` (Server-Sent Events)
* **Koneksi Database Terintegrasi:**
  * `pgsql_nms`: Database Master NMS (stok unit, dealer, master tipe kendaraan)
  * `pgsql_meta`: Database Operasional BPKB (`tblstock_unit`, `tblterima_bpkb`, `tblterima_bpkb_detail`)
  * `default`: Database lokal ACIAX (`aciax.bpkb_process_tracks`, `aciax.bpkb_settings`)

---

## 2. Autentikasi & Keamanan

Hampir seluruh API di bawah grup route `/api/*` dilindungi oleh middleware `check-secret-key` (`App\Http\Middleware\CheckSecretKey`).

### 2.1 Header Autentikasi (Direkomendasikan)
Kirimkan header `SECRET-KEY` pada setiap HTTP request:

```http
SECRET-KEY: <SECRET_KEY_DARI_ENV>
```

> **Catatan Penting:** Gunakan format dengan tanda minus/hyphen (`SECRET-KEY`), bukan underscore (`SECRET_KEY`). Sebagian besar reverse proxy web server (Nginx/Apache) memfilter header yang mengandung underscore.

### 2.2 Query Parameter (Khusus SSE / Download URL)
Khusus koneksi yang tidak mendukung custom header (seperti HTML5 `EventSource` atau link download langsung pada tag `<a>` browser):
```
?secret_key=<SECRET_KEY_DARI_ENV>
```

Jika secret key tidak valid atau tidak disertakan, server akan merespon:
```json
// HTTP 401 Unauthorized
{
    "message": "Unauthorized"
}
```

---

## 3. Ringkasan Daftar Route (Route Index)

| Method | Endpoint | Middleware | Deskripsi Singkat |
|:---|:---|:---|:---|
| `GET` | `/api/get-dealer` | `check-secret-key` | Cek dealer & bentuk penjualan via no. mesin (auto-cleansed + skor akurasi). |
| `POST` | `/api/bpkb/process` | `check-secret-key` | Upload 4 foto BPKB & antrikan pembuatan PDF BPKB. |
| `GET` | `/api/bpkb/track/{track}` | `check-secret-key` | Cek status & stage pemrosesan BPKB terkini (polling). |
| `GET` | `/api/bpkb/track/{track}/stream` | `check-secret-key` | Stream progress BPKB realtime melalui Server-Sent Events (SSE). |
| `GET` | `/api/bpkb/file` | `check-secret-key` | Unduh / preview file PDF BPKB (cari via track_id / nomesin / nobpkb). |
| `GET` | `/api/bpkb/file/{track}` | `check-secret-key` | Unduh / preview file PDF BPKB berdasarkan ID tracking pada URL. |
| `GET` | `/api/bpkb/file-clean` | `check-secret-key` | Unduh versi PDF BPKB bersih tanpa watermark. |
| `GET` | `/api/bpkb/file/{track}/clean` | `check-secret-key` | Unduh versi PDF BPKB bersih tanpa watermark via ID tracking. |
| `GET\|POST`| `/api/bpkb/remove-watermark` | `check-secret-key` | Alias endpoint untuk mengambil PDF clean tanpa watermark. |
| `GET` | `/api/bpkb/file-info` | `check-secret-key` | Cek status fisik file, ukuran byte, tanggal modifikasi, & direct URL. |
| `GET` | `/api/bpkb/file-info/{track}` | `check-secret-key` | Cek status fisik file berdasarkan ID tracking pada URL. |
| `POST` | `/api/bpkb/retry` | `check-secret-key` | Mengulang proses BPKB yang gagal (pencarian via body/query). |
| `POST` | `/api/bpkb/retry/{track}` | `check-secret-key` | Mengulang proses BPKB yang gagal berdasarkan UUID di URL. |
| `POST` | `/api/bpkb/retry-all-failed` | `check-secret-key` | Mengulang seluruh proses BPKB yang berstatus gagal secara massal. |
| `GET` | `/api/bpkb/monitoring` | `check-secret-key` | Monitoring data BPKB (search, filter tanggal/status/stage, pagination). |
| `GET` | `/api/bpkb/recent` | `check-secret-key` | Mengambil N data BPKB terbaru yang diproses. |
| `GET` | `/api/activity` | `check-secret-key` | Statistik bulanan: target, selesai, dan pending BPKB. |
| `GET` | `/api/activity/today` | `check-secret-key` | Statistik harian: jumlah tercapai vs target hari ini. |
| `GET` | `/api/bpkb/settings/scan-effect` | `check-secret-key` | Ambil konfigurasi efek scanner (kontras & kecerahan). |
| `POST` | `/api/bpkb/settings/scan-effect` | `check-secret-key` | Perbarui konfigurasi efek scanner (toggle on/off, set nilai). |
| `GET` | `/api/bpkb/settings/compress` | `check-secret-key` | Ambil konfigurasi persen kualitas kompresi PDF saat ini. |
| `POST` | `/api/bpkb/settings/compress` | `check-secret-key` | Perbarui persen kualitas kompresi PDF (10% - 100%). |
| `GET` | `/api/user` | `auth:sanctum` | Mengambil data pengguna yang login via token Sanctum. |
| `GET` | `/bpkb/download/{track}` | Web | Direct download attachment PDF BPKB. |

---

## 4. Katalog Endpoint Lengkap

### 4.1 Dealer & Validasi Kendaraan

#### `GET /api/get-dealer`
Mengambil data kepemilikan dealer dan bentuk penjualan unit kendaraan berdasarkan nomor mesin. Nomor mesin yang dimasukkan akan dibersihkan terlebih dahulu secara otomatis oleh algoritma `cleansingNoMesin` untuk mengoreksi typo OCR / keyboard.

* **Headers:** `SECRET-KEY: <key>`
* **Query Parameters:**
  | Parameter | Tipe | Wajib | Keterangan |
  |---|---|---|---|
  | `nomesin` | string | Ya | Nomor mesin mentah (contoh: `JFV1E173484O` atau `JMEIE2g77865`). |

* **Contoh Request:**
  ```http
  GET /api/get-dealer?nomesin=JFV1E1734840 HTTP/1.1
  Host: localhost:8000
  SECRET-KEY: your-secret-key
  ```

* **Respon Sukses (200 OK):**
  ```json
  {
      "fk_dealer": "10876",
      "nm_dealer": "CV. AYAKO",
      "no_mesin": "JFV1E1734840",
      "bentuk_penjualan": "Kredit",
      "akurasi": "100%"
  }
  ```

* **Respon Jika Data Unit Tidak Ditemukan di Database (200 OK):**
  ```json
  {
      "akurasi": "91.67%"
  }
  ```

---

### 4.2 Pemrosesan Dokumen BPKB

#### `POST /api/bpkb/process`
Menerima nomor mesin, nomor BPKB, dan 4 file foto BPKB. Permintaan ini disimpan ke antrian (*queue job*) secara asinkron agar tidak memblokir klien.

* **Headers:**
  * `SECRET-KEY: <key>`
  * `Content-Type: multipart/form-data`
* **Form-Data Body:**
  | Field | Tipe | Wajib | Keterangan |
  |---|---|---|---|
  | `nomesin` | string | Ya | Nomor mesin kendaraan (maks 16 karakter). Otomatis di-cleansing. |
  | `nobpkb` | string | Ya | Nomor BPKB. Otomatis diformat (contoh: `W-02371856`). |
  | `images[]` | file array | Ya | Tepat 4 file gambar (`jpeg, png, jpg`). |

* **Respon Sukses (202 Accepted):**
  ```json
  {
      "message": "BPKB sedang diproses.",
      "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8"
  }
  ```

* **Respon Validasi Gagal (422 Unprocessable Content):**
  ```json
  {
      "message": "Harus mengunggah tepat 4 gambar.",
      "errors": {
          "images": [
              "Harus mengunggah tepat 4 gambar."
          ]
      }
  }
  ```

---

#### `GET /api/bpkb/track/{track}`
Melihat status dan tahapan pengerjaan BPKB satu kali (metode polling).

* **Headers:** `SECRET-KEY: <key>`
* **URL Parameter:**
  * `track`: UUID dari tracking BPKB (didapat dari respon `/api/bpkb/process`).

* **Respon Sukses (200 OK):**
  ```json
  {
      "id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
      "no_mesin": "JFV1E1734840",
      "no_bpkb": "V-02371856",
      "nama_konsumen": "BUDI SANTOSO",
      "pdf_path": "bpkb/pdf/BUDI SANTOSO.pdf",
      "image_paths": null,
      "stage": "completed",
      "status": "completed",
      "error_message": null,
      "akurasi": "100%",
      "created_at": "2026-10-19T08:10:00.000000Z",
      "updated_at": "2026-10-19T08:10:15.000000Z"
  }
  ```

---

#### `GET /api/bpkb/track/{track}/stream`
Membuka koneksi Server-Sent Events (SSE) untuk menerima event perubahan tahapan pengerjaan dokumen BPKB secara *realtime*.

* **URL Parameter:** `track` (UUID)
* **Query Parameter:** `?secret_key=<key>` (direkomendasikan untuk SSE)
* **Content-Type Respon:** `text/event-stream`

* **Contoh Data Stream yang Diterima Klien:**
  ```
  data: {"stage":"create_pdf","status":"processing","pdf_url":null,"error_message":null}

  data: {"stage":"update_stok_unit","status":"processing","pdf_url":null,"error_message":null}

  data: {"stage":"update_tgl_bpkb_siap","status":"processing","pdf_url":null,"error_message":null}

  data: {"stage":"completed","status":"completed","pdf_url":"http://localhost:8000/bpkb/download/9e5c468a-b44c-47ea-a2f2-19e486ea7fa8","error_message":null}
  ```

---

### 4.3 Unduh & Manajemen File PDF BPKB

#### `GET /api/bpkb/file`
#### `GET /api/bpkb/file/{track}`
Mengambil atau mengunduh file PDF BPKB dari penyimpanan aman (*secure storage*).

* **Headers:** `SECRET-KEY: <key>`
* **Parameter Fleksibel:**
  * Route parameter: `{track}` (UUID)
  * Atau Query parameter:
    * `track_id`: UUID
    * `nomesin`: Nomor mesin (otomatis cleansing)
    * `nobpkb`: Nomor BPKB
    * `filename` / `path`: Path relatif file di storage
    * `disposition`: `inline` (tampilkan di browser, default) atau `attachment` (download paksa)
    * `download`: `true` / `1` (sama dengan disposition=attachment)
    * `without_wm` / `clean` / `watermark=0`: Ambil versi bersih tanpa watermark Menara Agung

* **Respon Sukses (200 OK):**
  * Binary PDF Stream (`Content-Type: application/pdf`).

* **Respon Jika Dokumen Masih Dalam Proses (422 Unprocessable):**
  ```json
  {
      "status": false,
      "code": "PROCESS_PROCESSING",
      "message": "Proses BPKB sedang berlangsung (tahap: create_pdf). Silakan coba beberapa saat lagi.",
      "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
      "stage": "create_pdf",
      "track_status": "processing",
      "error_message": null
  }
  ```

* **Respon Jika File Belum Ditemukan di Disk (404 Not Found):**
  ```json
  {
      "status": false,
      "code": "FILE_NOT_FOUND",
      "message": "File BPKB tidak ditemukan pada storage path secure."
  }
  ```

---

#### `GET /api/bpkb/file-clean`
#### `GET /api/bpkb/file/{track}/clean`
#### `GET|POST /api/bpkb/remove-watermark`
Shortcut untuk langsung mengambil file PDF BPKB versi tanpa watermark (clean).

* Parameter sama dengan endpoint `/api/bpkb/file`.

---

#### `GET /api/bpkb/file-info`
#### `GET /api/bpkb/file-info/{track}`
Mengecek status ketersediaan fisik file di server, ukuran byte, format ukuran manusiawi, serta URL unduh resmi sebelum klien melakukan download.

* **Headers:** `SECRET-KEY: <key>`
* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "data": {
          "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
          "no_mesin": "JFV1E1734840",
          "no_bpkb": "V-02371856",
          "nama_konsumen": "BUDI SANTOSO",
          "stage": "completed",
          "status": "completed",
          "error_message": null,
          "akurasi": "100%",
          "pdf_path": "bpkb/pdf/BUDI SANTOSO.pdf",
          "file_saved_on_server": true,
          "file_size_bytes": 1048576,
          "file_size_formatted": "1 MB",
          "last_modified": "2026-10-19 08:15:30",
          "file_url": "http://localhost:8000/api/bpkb/file/9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
          "download_url": "http://localhost:8000/api/bpkb/file/9e5c468a-b44c-47ea-a2f2-19e486ea7fa8?disposition=attachment"
      }
  }
  ```

---

### 4.4 Retry / Mengulang Proses Gagal

#### `POST /api/bpkb/retry`
#### `POST /api/bpkb/retry/{track}`
Mengulang proses dokumen BPKB yang gagal (`status: failed`).

* **Headers:** `SECRET-KEY: <key>`
* **Pencarian Target Dokumen (Fleksibel):**
  * Route parameter `{track}` (UUID)
  * Atau Body/Query JSON: `track_id`, `nomesin`, atau `nobpkb`
* **Parameter Opsional:**
  | Field | Tipe | Keterangan |
  |---|---|---|
  | `nomesin` / `no_mesin` | string | **Koreksi Nomor Mesin:** Mengubah nomor mesin pada track yang dipilih. Sistem otomatis menghitung ulang akurasi, serta mengambil ulang nama konsumen, dealer, dan tanggal samsat. |
  | `nobpkb` / `no_bpkb` | string | **Koreksi Nomor BPKB:** Mengubah nomor BPKB jika terjadi salah ketik. |
  | `force` | boolean | Set `true` jika ingin memaksa antri ulang meskipun status saat ini `processing` atau `completed`. |
  | `images[]` | file array | Sertakan file gambar baru jika sebelumnya proses gagal karena gambar hilang/rusak. |

* **Contoh Request:**
  ```http
  POST /api/bpkb/retry HTTP/1.1
  Host: localhost:8000
  SECRET-KEY: your-secret-key
  Content-Type: application/json

  {
      "nomesin": "JFV1E1734840"
  }
  ```

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "message": "Proses BPKB berhasil diantrikan ulang.",
      "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
      "data": {
          "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
          "no_mesin": "JFV1E1734840",
          "no_bpkb": "V-02371856",
          "nama_konsumen": "BUDI SANTOSO",
          "fk_dealer": "DLR01",
          "tgl_mohon_samsat": "2026-04-10",
          "stage": "pending",
          "status": "queued"
      }
  }
  ```

> 💡 **Auto-Backfill Metadata:** Saat proses retry dipicu, sistem otomatis memeriksa dan melengkapi kembali data `nama_konsumen` & `fk_dealer` dari tabel `tblstock_unit`, serta `tgl_mohon_samsat` dari `tblbastd_detail` jika sebelumnya masih kosong.

---

#### `POST /api/bpkb/retry-all-failed`
Mengulang seluruh antrian BPKB yang berstatus gagal (`status = 'failed'`) secara otomatis.

* **Headers:** `SECRET-KEY: <key>`
* **Request Body (JSON, Opsional):**
  | Field | Tipe | Default | Keterangan |
  |---|---|---|---|
  | `limit` | integer | `50` | Jumlah maksimal data gagal yang ingin diantrikan ulang (maks 200). |

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "message": "2 proses BPKB gagal berhasil diantrikan ulang.",
      "total_failed": 2,
      "retried_count": 2,
      "skipped_count": 0,
      "retried": [
          {
              "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
              "no_mesin": "JFV1E1734840",
              "no_bpkb": "V-02371856",
              "nama_konsumen": "BUDI SANTOSO"
          }
      ],
      "skipped": []
  }
  ```

---

### 4.5 Monitoring & Riwayat

#### `GET /api/bpkb/monitoring`
Mengambil data riwayat pemrosesan BPKB yang tersimpan di sistem, lengkap dengan fitur filter pencarian komprehensif, ringkasan statistik (stats), dan penomoran halaman (pagination).

* **Headers:** `SECRET-KEY: <key>`
* **Query Parameters:**
  | Parameter | Tipe | Keterangan |
  |---|---|---|
  | `search` | string | Pencarian kata kunci pada `no_mesin`, `no_bpkb`, `nama_konsumen`, atau `id`. |
  | `status` | string | Filter status: `completed`, `processing`, `queued`, `failed`. |
  | `stage` | string | Filter stage: `pending`, `create_pdf`, `update_stok_unit`, `update_tgl_bpkb_siap`, `completed`. |
  | `date` | string (YYYY-MM-DD) | Filter tanggal pengerjaan spesifik. |
  | `start_date` | string (YYYY-MM-DD) | Batas awal rentang tanggal pengerjaan. |
  | `end_date` | string (YYYY-MM-DD) | Batas akhir rentang tanggal pengerjaan. |
  | `month` | string (YYYY-MM) | Filter berdasarkan bulan tertentu (contoh: `2026-10`). |
  | `has_file` | boolean (`1`/`true`) | Hanya tampilkan data yang memiliki file PDF fisik di server. |
  | `sort_by` | string | Urutkan berdasarkan: `created_at`, `updated_at`, `nama_konsumen`, `no_mesin`, `no_bpkb` (default: `created_at`). |
  | `sort_direction` | string | `asc` atau `desc` (default: `desc`). |
  | `per_page` | integer | Jumlah data per halaman (default: `15`, maks: `100`). |
  | `page` | integer | Halaman saat ini (default: `1`). |

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "stats": {
          "total_all": 120,
          "total_completed": 115,
          "total_processing": 2,
          "total_queued": 1,
          "total_failed": 2,
          "total_with_pdf": 115
      },
      "pagination": {
          "total": 120,
          "per_page": 15,
          "current_page": 1,
          "last_page": 8,
          "from": 1,
          "to": 15
      },
      "data": [
          {
              "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
              "no_mesin": "JFV1E1734840",
              "no_bpkb": "V-02371856",
              "nama_konsumen": "BUDI SANTOSO",
              "stage": "completed",
              "status": "completed",
              "error_message": null,
              "akurasi": "100%",
              "pdf_path": "bpkb/pdf/BUDI SANTOSO.pdf",
              "file_saved_on_server": true,
              "file_size_bytes": 984520,
              "file_size_formatted": "961.45 KB",
              "last_modified": "2026-10-19 08:20:10",
              "file_url": "http://localhost:8000/api/bpkb/file/9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
              "download_url": "http://localhost:8000/api/bpkb/file/9e5c468a-b44c-47ea-a2f2-19e486ea7fa8?disposition=attachment",
              "created_at": "2026-10-19 08:19:50",
              "updated_at": "2026-10-19 08:20:10"
          }
      ]
  }
  ```

---

#### `GET /api/bpkb/recent`
Mengambil sejumlah N transaksi BPKB terakhir yang diproses.

* **Query Parameters:** `limit` (default: 5).
* **Respon Sukses (200 OK):**
  ```json
  [
      {
          "track_id": "9e5c468a-b44c-47ea-a2f2-19e486ea7fa8",
          "nobpkb": "V-02371856",
          "nomesin": "JFV1E1734840",
          "status": "completed",
          "created_at": "2026-10-19 08:19:50"
      }
  ]
  ```

---

#### `GET /api/activity`
Mendapatkan statistik ringkas bulanan.

* **Respon Sukses (200 OK):**
  ```json
  {
      "target_bpkb": 150,
      "completed_bpkb": 120,
      "pending_bpkb": 30
  }
  ```

---

#### `GET /api/activity/today`
Mendapatkan progres harian pengerjaan BPKB terhadap target harian yang dikonfigurasi.

* **Respon Sukses (200 OK):**
  ```json
  {
      "count": 42,
      "target": 100,
      "date": "2026-10-19"
  }
  ```

---

### 4.6 Pengaturan Dokumen Scan

Sistem dilengkapi filter visual (CamScanner style) yang dapat mengoptimalkan kualitas foto hasil kamera smartphone menjadi seperti hasil flatbed scanner (menaikkan kontras teks, memutihkan background kertas, dan mempertajam tinta).

#### `GET /api/bpkb/settings/scan-effect`
Melihat status aktif filter scanner, nilai kontras, dan kecerahan saat ini.

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "enabled": true,
      "contrast": -15,
      "brightness": 20
  }
  ```

---

#### `POST /api/bpkb/settings/scan-effect`
Mengubah konfigurasi efek scan gambar.

* **Headers:** `SECRET-KEY: <key>`
* **Body Parameters (JSON / Form):**
  | Parameter | Tipe | Keterangan |
  |---|---|---|
  | `enabled` | boolean | Aktifkan (`true`) atau nonaktifkan (`false`) efek scan. |
  | `toggle` | boolean | Jika dikirim, akan membalik status aktif saat ini (on -> off / off -> on). |
  | `contrast` | integer | Nilai kontras filter (misal: `-15` s/d `-30`). |
  | `brightness` | integer | Nilai kecerahan filter (misal: `10` s/d `30`). |

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "message": "Pengaturan efek scan dokumen berhasil diperbarui.",
      "enabled": true,
      "contrast": -20,
      "brightness": 25
  }
  ```

---

#### `GET /api/bpkb/settings/compress`
Melihat persentase kualitas kompresi gambar PDF BPKB saat ini.

* **Headers:** `SECRET-KEY: <key>`
* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "quality": 72,
      "quality_percent": "72%",
      "max_target_width": 1000,
      "description": "Kualitas kompresi gambar PDF BPKB (10% - 100%). Nilai lebih kecil menghasilkan file lebih ringan."
  }
  ```

---

#### `POST /api/bpkb/settings/compress`
Mengubah persentase kualitas kompresi gambar PDF BPKB. Nilai yang lebih kecil menghasilkan ukuran file PDF yang lebih kecil/ringan.

* **Headers:** `SECRET-KEY: <key>`
* **Body Parameters (JSON / Form):**
  | Parameter | Tipe | Wajib | Keterangan |
  |---|---|---|---|
  | `quality` / `persen` / `percent` | integer | Ya | Angka persentase kualitas kompresi (`10` s/d `100`). Rekomendasi: `65` - `75` untuk hasil di bawah 1MB. |

* **Contoh Request Body:**
  ```json
  {
      "persen": 70
  }
  ```

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "message": "Pengaturan persen kompresi PDF berhasil disimpan.",
      "quality": 70,
      "quality_percent": "70%"
  }
  ```

---

### 4.7 User / Profil

#### `GET /api/user`
Mengambil data identitas user yang sedang aktif.

* **Headers:** `Authorization: Bearer <sanctum_personal_access_token>`
* **Respon Sukses (200 OK):**
  ```json
  {
      "id": 1,
      "name": "Operator BPKB",
      "email": "operator@menaragung.com",
      "email_verified_at": null,
      "created_at": "2026-01-01T00:00:00.000000Z",
      "updated_at": "2026-01-01T00:00:00.000000Z"
  }
  ```

---

### 4.8 Rekap & Agregasi Dealer

#### `GET /api/bpkb/by-dealer`
*(Alias: `GET /api/bpkb/summary/dealer`)*

Menghitung jumlah data proses BPKB per **Dealer (`fk_dealer`)** dan per **Tanggal Mohon Samsat (`tgl_mohon_samsat`)**, sekaligus mengembalikan daftar rincian unit dokumennya yang dikelompokkan berdasarkan dealer. Nama dealer (`nm_dealer`) otomatis di-resolve dari tabel `public.tbldealer` di database `pgsql_nms`.

* **Headers:** `SECRET-KEY: <key>`
* **Query Parameters (Opsional):**
  | Parameter | Tipe | Keterangan |
  |---|---|---|
  | `fk_dealer` | string | Filter kode dealer tertentu (contoh: `DLR01`). |
  | `date` / `tgl_mohon_samsat` | string | Filter tanggal spesifik (`YYYY-MM-DD`). |
  | `start_date` & `end_date` | string | Filter rentang tanggal (`YYYY-MM-DD`). |
  | `month` | string | Filter bulan (`YYYY-MM`). |
  | `status` | string | Filter status: `completed`, `processing`, `queued`, `failed`. |
  | `search` | string | Pencarian pada nomor mesin, BPKB, nama, atau dealer. |
  | `include_details` | boolean | Default `true`. Set `false` jika hanya ingin summary counter tanpa array rincian unit. |
  | `sort_by` | string | `total` (default: terbanyak) atau `fk_dealer` (abjad kode dealer). |

* **Respon Sukses (200 OK):**
  ```json
  {
      "status": true,
      "message": "Data berhasil dikelompokkan per fk_dealer dan tgl_mohon_samsat.",
      "summary": {
          "total_dealers": 2,
          "total_records": 3,
          "total_completed": 2,
          "total_processing": 0,
          "total_queued": 1,
          "total_failed": 0
      },
      "data": [
          {
              "fk_dealer": "DLR01",
              "nm_dealer": "Nusantara Motor",
              "total": 2,
              "dates": [
                  {
                      "tgl_mohon_samsat": "2026-04-10",
                      "total": 2,
                      "details": [
                          {
                              "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5",
                              "no_mesin": "JME1E2977871",
                              "no_bpkb": "W-02371871",
                              "nama_konsumen": "Budi Santoso",
                              "fk_dealer": "DLR01",
                              "tgl_mohon_samsat": "2026-04-10",
                              "status": "completed",
                              "stage": "completed",
                              "pdf_path": "bpkb/pdf/01a16183-1699-7184-a08c-8fc6a9c456c5.pdf",
                              "file_url": "http://localhost:8000/api/bpkb/file/01a16183-1699-7184-a08c-8fc6a9c456c5",
                              "created_at": "2026-10-22 09:40:00"
                          }
                      ]
                  }
              ]
          }
      ]
  }
  ```

---

### 4.9 Data BASTD Detail

#### `GET /api/bastd-detail`
*(Alias: `GET /api/tblbastd-detail`)*

Mengambil data dari tabel `data_unit.tblbastd_detail` pada database PostgreSQL NMS (`pgsql_nms`).

* **Headers:** `SECRET-KEY: <key>`
* **Query Parameters (Opsional):**
  | Parameter | Tipe | Keterangan |
  |---|---|---|
  | `no_mesin` / `nomesin` / `fk_mesin` | string | Filter nomor mesin (mencocokkan kolom `fk_mesin`). |
  | `like` | boolean | Set `true` untuk pencarian partial (ILIKE `%keyword%`). |
  | `fk_bastd` | string | Filter nomor BASTD. |
  | `no_bpkb` | string | Filter nomor BPKB. |
  | `no_stnk` | string | Filter nomor STNK. |
  | `no_polisi` | string | Filter plat polisi. |
  | `paginate` | boolean | Default `true`. Set `false` atau `all=true` untuk mengambil data langsung tanpa wrapper pagination. |
  | `per_page` | integer | Jumlah data per halaman (default: `50`, maks: `500`). |

#### `GET /api/bastd-detail/{no_mesin}`
Shortcut untuk mengambil langsung data BASTD detail berdasarkan nomor mesin pada path URL.

---

## 5. Algoritma Cleansing Nomor Mesin & Akurasi Levenshtein

Seringkali input nomor mesin dari OCR kamera atau ketikan keyboard mengandung kesalahan karakter (typo). ACIAX memiliki fungsi bawaan `cleansingNoMesinWithDetails()` pada `BpkbController`.

### 5.1 Struktur Nomor Mesin (12 Karakter)
```
  J  M  E  1  E  │  2  9  7  7  8  6  5
├───────────────┤ ├─────────────────────┤
   5 Digit Prefix        7 Digit Serial
   (Tipe Kendaraan)    (Harus Berupa Angka)
```

### 5.2 Dua Tahapan Koreksi
1. **Tahap 1 — Koreksi Prefix (5 Karakter Pertama):**
   * Mengambil seluruh kode prefix resmi dari tabel `public.tbltipe_kendaraan` (kolom `digit_no_mesin`).
   * Menghitung **Levenshtein Distance** (jumlah perubahan karakter) antara input dengan database.
   * Prefix terdekat dengan selisih $\le 2$ karakter otomatis dipilih sebagai hasil koreksi.
   * Skor akurasi prefix dihitung: $\text{Akurasi} = \left(1 - \frac{\text{Distance}}{5}\right) \times 100\%$.

2. **Tahap 2 — Koreksi Serial (7 Karakter Berikutnya):**
   * Karakter huruf yang sering salah dibaca oleh OCR dipetakan menjadi digit angka yang sesuai:
     * `O`, `o` $\rightarrow$ `0`
     * `I`, `i`, `L`, `l` $\rightarrow$ `1`
     * `Z`, `z` $\rightarrow$ `2`
     * `E`, `e` $\rightarrow$ `3`
     * `A`, `a` $\rightarrow$ `4`
     * `S`, `s` $\rightarrow$ `5`
     * `T`, `t` $\rightarrow$ `7`
     * `B`, `b` $\rightarrow$ `8`
     * `G`, `g`, `Q`, `q` $\rightarrow$ `9`
   * Skor akurasi serial: $\text{Akurasi} = \left(1 - \frac{\text{Karakter yang Diubah}}{7}\right) \times 100\%$.

3. **Total Skor Akurasi:**
   $$\text{Akurasi Keseluruhan} = \left(1 - \frac{\text{Distance Prefix} + \text{Koreksi Serial}}{12}\right) \times 100\%$$
   Nilai ini yang dikembalikan pada endpoint `/api/get-dealer` dalam format persentase (contoh: `"akurasi": "91.67%"` atau `"akurasi": "100%"`).

---

## 6. Siklus Hidup Status & Tahapan (Life Cycle)

```
[ POST /api/bpkb/process ]
           │
           ▼
     status: 'queued'
     stage:  'pending'
           │
           ▼ (Worker ProcessBpkbJob Mengambil Antrian)
     status: 'processing'
           │
           ├─► stage: 'create_pdf'
           │     ├─ Scale resolusi foto (max target lebar 1400px)
           │     ├─ Terapkan Scan Effect (Contrast & Sharpening jika aktif)
           │     ├─ Simpan versi Clean (tanpa watermark) ke secure disk
           │     └─ Buat Watermark 'Menara Agung' miring & simpan PDF default
           │
           ├─► stage: 'update_stok_unit'
           │     └─ Update no_bpkb pada database pgsql_meta (tblstock_unit)
           │
           ├─► stage: 'update_tgl_bpkb_siap'
           │     └─ Update tgl_bpkb_siap pada database pgsql_meta (tblterima_bpkb)
           │
           ▼
     status: 'completed'
     stage:  'completed'
```

* Jika terjadi *error* pada tahap mana pun:
  * `status`: `'failed'`
  * `error_message`: Pesan kegagalan / exception
  * Klien dapat memanggil `POST /api/bpkb/retry/{track}` untuk mengulang kembali dari titik aman tanpa merusak data.

---

## 7. Panduan Integrasi & Kode Sampel

### 7.1 cURL

#### Cek Dealer:
```bash
curl -X GET "http://localhost:8000/api/get-dealer?nomesin=JFV1E1734840" \
     -H "SECRET-KEY: your-secret-key"
```

#### Upload & Proses BPKB:
```bash
curl -X POST "http://localhost:8000/api/bpkb/process" \
     -H "SECRET-KEY: your-secret-key" \
     -F "nomesin=JFV1E1734840" \
     -F "nobpkb=V-02371856" \
     -F "images[]=@/path/to/foto1.jpg" \
     -F "images[]=@/path/to/foto2.jpg" \
     -F "images[]=@/path/to/foto3.jpg" \
     -F "images[]=@/path/to/foto4.jpg"
```

#### Retry Proses Gagal:
```bash
curl -X POST "http://localhost:8000/api/bpkb/retry" \
     -H "SECRET-KEY: your-secret-key" \
     -H "Content-Type: application/json" \
     -d '{"nomesin":"JFV1E1734840"}'
```

---

### 7.2 JavaScript / TypeScript (Axios)

```typescript
import axios from 'axios';

const api = axios.create({
    baseURL: 'http://localhost:8000/api',
    headers: {
        'SECRET-KEY': 'your-secret-key',
        'Accept': 'application/json',
    },
});

// 1. Dapatkan info dealer
export async function fetchDealer(noMesin: string) {
    const response = await api.get('/get-dealer', {
        params: { nomesin: noMesin },
    });
    return response.data; // { fk_dealer, nm_dealer, no_mesin, bentuk_penjualan, akurasi }
}

// 2. Submit BPKB
export async function submitBpkb(noMesin: string, noBpkb: string, imageFiles: File[]) {
    const formData = new FormData();
    formData.append('nomesin', noMesin);
    formData.append('nobpkb', noBpkb);
    imageFiles.forEach((file) => formData.append('images[]', file));

    const response = await api.post('/bpkb/process', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data.track_id;
}

// 3. Retry jika gagal
export async function retryBpkb(trackId: string) {
    const response = await api.post(`/bpkb/retry/${trackId}`);
    return response.data;
}
```

---

### 7.3 Realtime Tracking SSE di Browser / React

```javascript
function listenBpkbProgress(trackId, onUpdate, onComplete, onError) {
    const secretKey = 'your-secret-key';
    const eventSource = new EventSource(
        `http://localhost:8000/api/bpkb/track/${trackId}/stream?secret_key=${secretKey}`
    );

    eventSource.onmessage = (event) => {
        const data = JSON.parse(event.data);
        onUpdate(data);

        if (data.status === 'completed') {
            eventSource.close();
            onComplete(data.pdf_url);
        } else if (data.status === 'failed') {
            eventSource.close();
            onError(data.error_message);
        }
    };

    eventSource.onerror = (err) => {
        eventSource.close();
        onError('Koneksi stream terputus');
    };

    return () => eventSource.close();
}
```

---

## 8. Tabel Kode Respon & Error Handling

| HTTP Status | Kode Respon | Arti & Solusi |
|---|---|---|
| `200 OK` | `SUCCESS` | Permintaan berhasil diproses. |
| `202 Accepted` | `QUEUED` | Dokumen diterima dan sedang diantrikan pada background worker. |
| `401 Unauthorized` | `UNAUTHORIZED` | Header `SECRET-KEY` salah, kosong, atau tidak cocok dengan `.env`. |
| `404 Not Found` | `TRACK_NOT_FOUND` / `FILE_NOT_FOUND` | Data tracking atau file PDF fisik tidak ditemukan di storage. |
| `409 Conflict` | `ALREADY_PROCESSING` | Proses BPKB sedang berjalan. Kirim `force=true` jika ingin memaksa antri ulang. |
| `422 Unprocessable` | `PROCESS_PROCESSING` / `VALIDATION_ERROR` | File belum selesai di-generate atau input data form tidak valid (misal jumlah foto bukan 4). |
| `500 Internal Error`| `SERVER_ERROR` | Terjadi kesalahan internal di server (periksa `storage/logs/laravel.log`). |
