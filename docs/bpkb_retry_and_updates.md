# Dokumentasi API: Panduan Retry & Rangkuman Update Sistem BPKB

Dokumen ini memuat panduan lengkap mengenai fitur **Retry Dokumen BPKB** serta rangkuman seluruh pembaruan sistem backend terbaru, termasuk integrasi kolom baru (`fk_dealer`, `tgl_mohon_samsat`), sinkronisasi database, dan endpoint rekap dealer.

---

## Daftar Isi

1. [Rangkuman Pembaruan Sistem (Changelog)](#1-rangkuman-pembaruan-sistem-changelog)
2. [Panduan & Spesifikasi Endpoint Retry](#2-panduan--spesifikasi-endpoint-retry)
   - [2.1 Logika & Cara Kerja Retry](#21-logika--cara-kerja-retry)
   - [2.2 Endpoint Single Retry: POST /api/bpkb/retry](#22-endpoint-single-retry-post-apibpkbretry)
   - [2.3 Endpoint Bulk Retry: POST /api/bpkb/retry-all-failed](#23-endpoint-bulk-retry-post-apibpkbretry-all-failed)
   - [2.4 Auto-Backfill Data Dealer & Tanggal saat Retry](#24-auto-backfill-data-dealer--tanggal-saat-retry)
3. [Integrasi Kolom Baru: fk_dealer & tgl_mohon_samsat](#3-integrasi-kolom-baru-fk_dealer--tgl_mohon_samsat)
   - [3.1 Skema & Casting Model](#31-skema--casting-model)
   - [3.2 Alur Pengisian pada POST /api/bpkb/process](#32-alur-pengisian-pada-post-apibpkbprocess)
   - [3.3 Alur Sinkronisasi pada ProcessBpkbJob](#33-alur-sinkronisasi-pada-processbpkbjob)
   - [3.4 Pembaruan Output Monitoring & File Info](#34-pembaruan-output-monitoring--file-info)
4. [Endpoint Rekap Dealer: GET /api/bpkb/by-dealer](#4-endpoint-rekap-dealer-get-apibpkbby-dealer)
5. [Endpoint BASTD Detail: GET /api/bastd-detail](#5-endpoint-bastd-detail-get-apibastd-detail)
6. [Contoh Kode Integrasi Frontend / Client](#6-contoh-kode-integrasi-frontend--client)

---

## 1. Rangkuman Pembaruan Sistem (Changelog)

| Fitur / Komponen | Keterangan Pembaruan |
| :--- | :--- |
| **Retry Enhancements** | Penambahan auto-backfill otomatis untuk `nama_konsumen`, `fk_dealer`, dan `tgl_mohon_samsat` saat antri ulang BPKB yang gagal. |
| **Kolom Baru Track** | Penambahan kolom `fk_dealer` (varchar 10) dan `tgl_mohon_samsat` (date) pada tabel `aciax.bpkb_process_tracks`. |
| **BPKB Process Flow** | `fk_dealer` otomatis diambil dari `tblstock_unit`, dan `tgl_mohon_samsat` dihubungkan dari `tblbastd_detail`. |
| **Worker Queue (Job)** | Step `updateTglBpkbSiap()` kini mengupdate `tgl_bpkb_siap` di BASTD dengan `tgl_mohon_samsat` dan menyimpannya ke riwayat track. |
| **Monitoring & File Info** | Output API `GET /api/bpkb/monitoring` dan `GET /api/bpkb/file-info` kini menyertakan `fk_dealer` dan `tgl_mohon_samsat`. |
| **Endpoint Rekap Dealer** | Endpoint baru `GET /api/bpkb/by-dealer` untuk menghitung agregat unit per dealer dan per tanggal mohon samsat beserta detailnya. |
| **Endpoint BASTD Detail** | Endpoint baru `GET /api/bastd-detail` untuk mengambil data `data_unit.tblbastd_detail` dengan filter `no_mesin`. |

---

## 2. Panduan & Spesifikasi Endpoint Retry

Fitur Retry dirancang untuk memudahkan operator mengantrikan kembali proses BPKB yang mengalami kegagalan (misalnya karena gangguan koneksi DB NMS, timeout PDF, atau error sementara) tanpa harus mengulang proses upload jika file sudah tersimpan.

### 2.1 Logika & Cara Kerja Retry

1. **Pengecekan File Sumber**:
   - Sistem memeriksa apakah gambar fisik asli masih ada di storage (`public`, `secure`, atau `local`).
   - Jika gambar sudah dibersihkan karena proses sebelumnya sempat berhasil membuat PDF sebelum gagal pada update DB, sistem **dapat menggunakan file PDF yang sudah ada** di secure disk tanpa perlu upload ulang.
   - Jika file fisik gambar maupun PDF tidak ditemukan sama sekali, sistem akan mengembalikan kode error `IMAGES_NOT_FOUND` (422) dan meminta upload gambar baru.
2. **Auto-Recovery Metadata**:
   - Jika `nama_konsumen` atau `fk_dealer` masih kosong, sistem otomatis mencari dari `tblstock_unit` berdasarkan `no_mesin`.
   - Jika `tgl_mohon_samsat` kosong, sistem otomatis mengambil dari `tblbastd_detail`.
3. **Reset Antrian**:
   - Mengubah status track kembali menjadi `status: 'queued'`, `stage: 'pending'`, dan menghapus `error_message`.
   - Menjalankan kembali queue worker `ProcessBpkbJob::dispatch($track)`.

---

### 2.2 Endpoint Single Retry: `POST /api/bpkb/retry`

Mengulang proses satu dokumen BPKB yang gagal.

#### URL & Method
```http
POST /api/bpkb/retry
POST /api/bpkb/retry/{track}
```

#### Headers
```http
SECRET-KEY: <your-secret-key>
Content-Type: application/json
Accept: application/json
```

#### Parameter Pencarian Dokumen (Pilih Salah Satu)
Target track dapat ditentukan melalui:
1. URL Path: `POST /api/bpkb/retry/{uuid_track_id}`
2. Request Body / Query String:
   - `track_id`: UUID dari track proses
   - `nomesin` / `no_mesin`: Nomor mesin kendaraan
   - `nobpkb` / `no_bpkb`: Nomor BPKB

#### Parameter Tambahan (Opsional)
| Field | Tipe | Keterangan |
| :--- | :--- | :--- |
| `nomesin` / `no_mesin` | string | **Koreksi Nomor Mesin:** Mengubah nomor mesin lama dengan nomor mesin yang baru/benar. Sistem otomatis mengupdate akurasi, mengambil ulang `nama_konsumen` & `fk_dealer` dari `tblstock_unit`, dan `tgl_mohon_samsat` dari `tblbastd_detail`. |
| `nobpkb` / `no_bpkb` | string | **Koreksi Nomor BPKB:** Mengubah nomor BPKB jika sebelumnya salah ketik. |
| `force` | boolean | Set `true` jika ingin memaksa retry meskipun status dokumen saat ini bukan `failed` (misal ingin generate ulang dokumen `completed`). |
| `images[]` | file array | Sertakan tepat 4 file gambar baru jika file gambar lama pada storage terhapus. |

#### Contoh Request Body untuk Koreksi Nomor Mesin (JSON)
```json
{
  "track_id": "01a161f9-e0b8-703f-bb0a-3a6e76b7476b",
  "nomesin": "JMF2E1838648"
}
```

#### Contoh Respon Sukses (`200 OK`)
```json
{
  "status": true,
  "message": "Proses BPKB berhasil diantrikan ulang.",
  "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5",
  "data": {
    "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5",
    "no_mesin": "JME1E2977865",
    "no_bpkb": "W-02371856",
    "nama_konsumen": "BUDI SANTOSO",
    "fk_dealer": "DLR01",
    "tgl_mohon_samsat": "2026-04-10",
    "stage": "pending",
    "status": "queued"
  }
}
```

#### Contoh Respon Gambar Hilang (`422 Unprocessable Entity`)
```json
{
  "status": false,
  "code": "IMAGES_NOT_FOUND",
  "message": "File gambar BPKB tidak ditemukan pada storage untuk diproses ulang. Silakan sertakan file images pada request.",
  "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5"
}
```

---

### 2.3 Endpoint Bulk Retry: `POST /api/bpkb/retry-all-failed`

Mengulang **seluruh dokumen BPKB yang berstatus gagal (`status = 'failed'`)** secara otomatis dalam satu panggilan API.

#### URL & Method
```http
POST /api/bpkb/retry-all-failed
```

#### Headers
```http
SECRET-KEY: <your-secret-key>
Content-Type: application/json
Accept: application/json
```

#### Request Body (JSON, Opsional)
| Field | Tipe | Default | Keterangan |
| :--- | :--- | :--- | :--- |
| `limit` | integer | `50` | Batas maksimal dokumen gagal yang diproses sekaligus (minimal `1`, maksimal `200`). |

#### Contoh Request
```bash
curl -X POST "http://localhost:8000/api/bpkb/retry-all-failed" \
     -H "SECRET-KEY: <your-secret-key>" \
     -H "Content-Type: application/json" \
     -d '{"limit": 25}'
```

#### Contoh Respon Sukses (`200 OK`)
```json
{
  "status": true,
  "message": "2 proses BPKB gagal berhasil diantrikan ulang.",
  "total_failed": 2,
  "retried_count": 2,
  "skipped_count": 0,
  "retried": [
    {
      "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5",
      "no_mesin": "JME1E2977865",
      "no_bpkb": "W-02371856",
      "nama_konsumen": "BUDI SANTOSO"
    },
    {
      "track_id": "01a16184-1800-7184-a08c-8fc6a9c456c6",
      "no_mesin": "JME1E2977866",
      "no_bpkb": "W-02371857",
      "nama_konsumen": "SITI RAHMA"
    }
  ],
  "skipped": []
}
```

> **Catatan:** Dokumen yang masuk kategori `skipped` adalah dokumen berstatus `failed` yang file gambar fisik maupun PDF-nya sudah tidak tersedia di server.

---

### 2.4 Auto-Backfill Data Dealer & Tanggal saat Retry

Saat proses retry dipicu (baik single maupun bulk), sistem otomatis menjalankan pengecekan metadata:
1. Jika `nama_konsumen` atau `fk_dealer` pada track bernilai `null` atau kosong, sistem mengambil nilai terbaru dari `data_unit.tblstock_unit`.
2. Jika `tgl_mohon_samsat` masih `null`, sistem mengambil data dari `data_unit.tblbastd_detail`.
Hal ini memastikan data BPKB lama yang pernah gagal sebelum adanya pembaruan kolom akan otomatis terlengkapi.

---

## 3. Integrasi Kolom Baru: fk_dealer & tgl_mohon_samsat

### 3.1 Skema & Casting Model

Kolom ditambahkan pada tabel `aciax.bpkb_process_tracks`:
- `fk_dealer`: `VARCHAR(10)`, indexed, nullable. Menyimpan kode dealer (misal: `DLR01`, `01234`).
- `tgl_mohon_samsat`: `DATE`, indexed, nullable. Menyimpan tanggal permohonan samsat (format: `YYYY-MM-DD`).

Pada model [BpkbProcessTrack.php](file:///c:/Users/AKU%20BANGGA%20JADI%20IT%202/Documents/RafiChandra/laravel/aciax/app/Models/BpkbProcessTrack.php):
```php
protected $fillable = [
    'no_mesin',
    'no_bpkb',
    'nama_konsumen',
    'pdf_path',
    'image_paths',
    'stage',
    'status',
    'error_message',
    'akurasi',
    'fk_dealer',
    'tgl_mohon_samsat',
];

protected function casts(): array
{
    return [
        'image_paths'      => 'array',
        'tgl_mohon_samsat' => 'date:Y-m-d',
    ];
}
```

### 3.2 Alur Pengisian pada `POST /api/bpkb/process`

Ketika request masuk ke `BpkbController::process`:
1. Sistem mengambil `nm_customer` dan `fk_dealer` dari database NMS:
   ```php
   $stokUnit = StokUnit::select('nm_customer', 'fk_dealer')
       ->where('no_mesin', $cleansedNoMesin)
       ->first();
   ```
2. Sistem mengambil `tgl_mohon_samsat` dari tabel BASTD detail:
   ```php
   $bastd = DB::connection('pgsql_nms')
       ->table('data_unit.tblbastd_detail')
       ->where('fk_mesin', $cleansedNoMesin)
       ->select('tgl_mohon_samsat')
       ->first();
   ```
3. Data disimpan langsung saat inisialisasi record `BpkbProcessTrack::create([...])`.

### 3.3 Alur Sinkronisasi pada `ProcessBpkbJob`

Di dalam worker queue `ProcessBpkbJob.php` pada tahap `updateTglBpkbSiap()`:
1. Mengambil record BASTD detail via `fk_mesin`.
2. Menyimpan/memperbarui `tgl_mohon_samsat` pada track.
3. Melakukan backfill pada `fk_dealer` jika sebelumnya belum terisi.
4. Mengupdate tabel `data_unit.tblbastd_detail`:
   - `no_bpkb` diisi nomor BPKB yang baru diinput.
   - `tgl_input_bpkb` diisi timestamp saat ini.
   - `tgl_bpkb_siap` disamakan dengan `tgl_mohon_samsat`.

### 3.4 Pembaruan Output Monitoring & File Info

Kedua endpoint berikut sekarang secara otomatis menyertakan `fk_dealer` dan `tgl_mohon_samsat` pada payload JSON:
- `GET /api/bpkb/monitoring`: Mendukung filter baru `?fk_dealer=<kode_dealer>`.
- `GET /api/bpkb/file-info` dan `GET /api/bpkb/file-info/{track}`.

Contoh cuplikan item monitoring:
```json
{
  "track_id": "01a16183-1699-7184-a08c-8fc6a9c456c5",
  "no_mesin": "JME1E2977865",
  "no_bpkb": "W-02371856",
  "nama_konsumen": "BUDI SANTOSO",
  "fk_dealer": "DLR01",
  "tgl_mohon_samsat": "2026-04-10",
  "stage": "completed",
  "status": "completed"
}
```

---

## 4. Endpoint Rekap Dealer: `GET /api/bpkb/by-dealer`

Endpoint ini menghitung data unit per dealer dan per tanggal mohon samsat, sekaligus menyediakan detail data unitnya.

* **URL**: `/api/bpkb/by-dealer` *(Alias: `/api/bpkb/summary/dealer`)*
* **Method**: `GET`
* **Filter Utama**:
  - `fk_dealer`: Filter kode dealer tertentu.
  - `date` / `tgl_mohon_samsat`: Filter tanggal spesifik (`YYYY-MM-DD`).
  - `month`: Filter bulan (`YYYY-MM`).
  - `status`: Filter status (`completed`, `processing`, `queued`, `failed`).
  - `include_details`: Set `false` jika hanya ingin rekap counter (tanpa array data unit).

Dokumentasi detail dan contoh respon lengkap untuk endpoint ini dapat dilihat pada file:
📄 **[docs/bpkb_by_dealer.md](file:///c:/Users/AKU%20BANGGA%20JADI%20IT%202/Documents/RafiChandra/laravel/aciax/docs/bpkb_by_dealer.md)**

---

## 5. Endpoint BASTD Detail: `GET /api/bastd-detail`

Mengambil data dari tabel `data_unit.tblbastd_detail` di database `pgsql_nms`.

* **URL**: `/api/bastd-detail` *(Alias: `/api/tblbastd-detail`)*
* **Method**: `GET`
* **Filter Utama**:
  - `no_mesin` (atau `nomesin`, `fk_mesin`): Mencocokkan kolom `fk_mesin`.
  - `like`: Set `true` untuk pencarian partial (ILIKE `%keyword%`).
  - `fk_bastd`: Filter nomor BASTD.
  - `paginate`: Set `false` atau `all=true` untuk mengambil data tanpa pagination.
  - `per_page`: Jumlah data per halaman (default 50, maks 500).

---

