# Dokumentasi API: BPKB by Dealer & Tanggal Mohon Samsat

Endpoint ini digunakan untuk menghitung (agregasi) jumlah proses BPKB per **Dealer (`fk_dealer`)** dan per **Tanggal Mohon Samsat (`tgl_mohon_samsat`)**, sekaligus menyajikan daftar rincian data unit di setiap tanggalnya yang dikelompokkan berdasarkan dealer.

---

## 1. Informasi Endpoint

| Item | Deskripsi |
| :--- | :--- |
| **Method** | `GET` |
| **Endpoint URL** | `/api/bpkb/by-dealer` |
| **Alias URL** | `/api/bpkb/summary/dealer` |
| **Content-Type** | `application/json` |
| **Autentikasi** | Header `SECRET-KEY` |

---

## 2. Autentikasi

Semua request wajib menyertakan header `SECRET-KEY` yang valid atau query parameter `secret_key`.

```http
SECRET-KEY:
```

> **Catatan:** Disarankan menggunakan penulisan dengan tanda hubung (`SECRET-KEY`), karena web server seperti Nginx/Apache secara default dapat memblokir header yang menggunakan underscore (`_`).

---

## 3. Parameter Request (Query Parameters)

Semua parameter di bawah ini bersifat opsional.

| Parameter | Tipe | Default | Contoh | Keterangan |
| :--- | :--- | :--- | :--- | :--- |
| `fk_dealer` | `string` | - | `DLR01` | Filter hanya untuk kode dealer tertentu. |
| `date` / `tgl_mohon_samsat` | `string` | - | `2026-04-10` | Filter tanggal mohon samsat spesifik (`YYYY-MM-DD`). |
| `start_date` | `string` | - | `2026-04-01` | Batas awal rentang `tgl_mohon_samsat` (`YYYY-MM-DD`). |
| `end_date` | `string` | - | `2026-04-30` | Batas akhir rentang `tgl_mohon_samsat` (`YYYY-MM-DD`). |
| `month` | `string` | - | `2026-04` | Filter berdasarkan bulan tertentu (`YYYY-MM`). |
| `status` | `string` | - | `completed` | Filter status proses: `completed`, `processing`, `queued`, `failed`. |
| `search` | `string` | - | `JME1E` | Pencarian teks pada `no_mesin`, `no_bpkb`, `nama_konsumen`, atau `fk_dealer`. |
| `include_details` | `boolean` | `true` | `false` | Jika `false`, array `details` dihilangkan (hanya menampilkan counter/summary). |
| `sort_by` | `string` | `total` | `fk_dealer` | Urutan dealer: `total` (terbanyak dulu) atau `fk_dealer` (abjad kode dealer). |

---

## 4. Contoh Request

### A. Menggunakan cURL
```bash
curl -X GET "http://localhost:8000/api/bpkb/by-dealer?month=2026-04" \
     -H "SECRET-KEY:" \
     -H "Accept: application/json"
```

### B. Menggunakan JavaScript (Fetch)
```javascript
const response = await fetch('http://localhost:8000/api/bpkb/by-dealer?fk_dealer=DLR01', {
  method: 'GET',
  headers: {
    'SECRET-KEY': 'U2FsdGVkX19jfmhRGNeUJ2TITVhrP0hzNJ9HNv81qSs=',
    'Accept': 'application/json'
  }
});
const result = await response.json();
console.log(result);
```

### C. Menggunakan PHP (Laravel HTTP Client)
```php
use Illuminate\Support\Facades\Http;

$response = Http::withHeaders([
    'SECRET-KEY' => config('app.secret_key'),
])->get('http://localhost:8000/api/bpkb/by-dealer', [
    'month' => '2026-04',
    'status' => 'completed',
]);

$data = $response->json();
```

---

## 5. Struktur Respon

### Contoh Respon Berhasil (`200 OK`)

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
            },
            {
              "track_id": "01a16184-1800-7184-a08c-8fc6a9c456c6",
              "no_mesin": "JME1E2977872",
              "no_bpkb": "W-02371872",
              "nama_konsumen": "Siti Rahma",
              "fk_dealer": "DLR01",
              "tgl_mohon_samsat": "2026-04-10",
              "status": "completed",
              "stage": "completed",
              "pdf_path": "bpkb/pdf/01a16184-1800-7184-a08c-8fc6a9c456c6.pdf",
              "file_url": "http://localhost:8000/api/bpkb/file/01a16184-1800-7184-a08c-8fc6a9c456c6",
              "created_at": "2026-10-22 09:42:15"
            }
          ]
        }
      ]
    },
    {
      "fk_dealer": "DLR02",
      "nm_dealer": "Mitra Abadi Motor",
      "total": 1,
      "dates": [
        {
          "tgl_mohon_samsat": "2026-04-11",
          "total": 1,
          "details": [
            {
              "track_id": "01a16185-1900-7184-a08c-8fc6a9c456c7",
              "no_mesin": "JME1E2977873",
              "no_bpkb": "W-02371873",
              "nama_konsumen": "Agus Pratama",
              "fk_dealer": "DLR02",
              "tgl_mohon_samsat": "2026-04-11",
              "status": "queued",
              "stage": "pending",
              "pdf_path": null,
              "file_url": null,
              "created_at": "2026-10-22 09:45:00"
            }
          ]
        }
      ]
    }
  ]
}
```

---

## 6. Penjelasan Komponen Respon

### Object `summary`
- `total_dealers`: Jumlah dealer unik yang memiliki data pada kriteria filter.
- `total_records`: Jumlah total transaksi/unit BPKB.
- `total_completed`: Jumlah unit yang sudah selesai diproses.
- `total_processing`: Jumlah unit yang sedang diproses worker.
- `total_queued`: Jumlah unit dalam antrian proses.
- `total_failed`: Jumlah unit yang mengalami kegagalan proses.

### Object Item dalam `data[]` (Tingkat Dealer)
- `fk_dealer`: Kode unik dealer (misal: `DLR01`). Bernilai `null` jika tidak terdata pada unit.
- `nm_dealer`: Nama resmi dealer (di-resolve otomatis dari tabel master `public.tbldealer`).
- `total`: Total seluruh transaksi BPKB untuk dealer bersangkutan.
- `dates`: Rincian per kelompok tanggal mohon samsat.

### Object Item dalam `dates[]` (Tingkat Tanggal)
- `tgl_mohon_samsat`: Tanggal mohon samsat dalam format `YYYY-MM-DD` (atau `null` jika belum diisi).
- `total`: Jumlah unit yang memiliki tanggal mohon samsat tersebut pada dealer terkait.
- `details`: Array daftar unit lengkap (tersedia saat `include_details=true`).

---

## 7. Variasi Penggunaan

### 1. Hanya Mengambil Rekap Ringkas (Tanpa Array Details)
Jika ingin performa cepat untuk menampilkan grafik atau rekap tabel tanpa detail:
```http
GET /api/bpkb/by-dealer?include_details=false
```

### 2. Rekap Per Bulan Tertentu
```http
GET /api/bpkb/by-dealer?month=2026-04
```

### 3. Rekap untuk Dealer Tertentu Saja
```http
GET /api/bpkb/by-dealer?fk_dealer=DLR01
```

### 4. Rekap Khusus BPKB yang Sudah Selesai (`completed`)
```http
GET /api/bpkb/by-dealer?status=completed
```

---

## 8. Kode Respon HTTP

| Status Code | Kondisi | Keterangan |
| :--- | :--- | :--- |
| `200 OK` | Sukses | Data berhasil diambil dan dikelompokkan. |
| `401 Unauthorized` | Autentikasi Gagal | Header `SECRET-KEY` tidak disertakan atau salah. |
