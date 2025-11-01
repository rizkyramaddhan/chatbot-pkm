# CHATBOT-PKM — CSV Knowledge Base Chatbot with LLM Fallback

Chatbot layanan yang menjawab menggunakan **basis pengetahuan CSV** terlebih dahulu. Jika tidak ada kecocokan yang memadai, bot akan menggunakan **LLM eksternal** sebagai fallback. Termasuk widget chat sederhana yang bisa di-embed ke situs mana pun.

## Fitur

* **Knowledge-first**: pencocokan fuzzy ke contoh pertanyaan di CSV.
* **Fallback ke LLM** bila skor kemiripan di bawah ambang batas.
* **Widget** siap pakai (`public/`) + script `embed.js` untuk tombol mengambang.
* **Tanpa DB**: cukup unggah/ubah `intents.csv`.
* **Konfigurasi aman** via environment variables.

## Struktur Proyek

```
.
├─ backend/
│  ├─ chatbot.php      # endpoint API (KB + fallback LLM)
│  ├─ config.php       # konfigurasi threshold, CORS, dan LLM
│  └─ intents.csv      # basis pengetahuan (intent, samples, answer, keywords)
└─ public/
   ├─ css/
   ├─ embed.js         # tombol mengambang yang memuat widget via iframe
   ├─ widget.html      # UI chat
   └─ widget.js        # logic front-end, memanggil backend/chatbot.php
```

## Prasyarat

* PHP 8.1+ (disarankan 8.2).
* Web server apa pun (untuk produksi); dev bisa pakai PHP built-in server.
* Koneksi internet untuk fallback LLM.

## Instalasi Cepat

1. Clone repo ini.
2. Pastikan `backend/intents.csv` memiliki header yang benar (lihat bagian **Format CSV**).
3. Set environment variables untuk LLM (opsional jika hanya ingin KB lokal).

### Konfigurasi LLM via Environment Variables

Siapkan variabel berikut di environment server kamu:

* `LLM_ENDPOINT`
  Contoh: `https://provider-llm.example/v1/models/<model>:generateContent`
* `LLM_API_KEY`
  Kunci akses ke LLM.
* `LLM_MODEL`
  Nama/ID model yang dipakai.
* `LLM_AUTH`
  `query` untuk `?key=<API_KEY>` atau `bearer` untuk header `Authorization: Bearer`.
* `LLM_TEMPERATURE`
  Default `0.2`.

Di Linux/macOS:

```bash
export LLM_ENDPOINT="https://provider-llm.example/v1/models/xxx:generateContent"
export LLM_API_KEY="xxxxxxxx"
export LLM_MODEL="model-anda"
export LLM_AUTH="query"
export LLM_TEMPERATURE="0.2"
```

Windows (PowerShell):

```powershell
setx LLM_ENDPOINT "https://provider-llm.example/v1/models/xxx:generateContent"
setx LLM_API_KEY "xxxxxxxx"
setx LLM_MODEL "model-anda"
setx LLM_AUTH "query"
setx LLM_TEMPERATURE "0.2"
```

> Nilai ambang kecocokan dapat diubah di `backend/config.php` (`similarity_threshold`, default `0.72`).

## Menjalankan Secara Lokal (Dev)

**Opsi A: Dua server (paling sederhana)**

```bash
# Terminal 1: backend (API)
php -S 127.0.0.1:9000 -t backend

# Terminal 2: frontend (widget)
php -S 127.0.0.1:8080 -t public
```

Lalu buka `http://127.0.0.1:8080/widget.html`.
Pastikan `public/widget.js` memakai `API_URL = "http://127.0.0.1:9000/chatbot.php"`.

**Opsi B: Satu server web (produksi)**
Set `public/` sebagai document root, dan ekspose `backend/chatbot.php` sebagai endpoint API (misal via alias/route). Aktifkan CORS bila front-end di domain berbeda. CORS default di `config.php` mengizinkan semua origin (`*`).

## Menyematkan Widget ke Situs

Tambahkan script ini di halaman web kamu:

```html
<script src="https://your-domain/path/to/public/embed.js" defer></script>
```

Tombol mengambang akan muncul di kanan bawah. Klik untuk membuka jendela chat.

## API

**Endpoint**
`POST /backend/chatbot.php`

**Body**

```json
{
  "message": "tulis pertanyaanmu di sini",
  "session_id": "opsional"
}
```

**Response (KB match)**

```json
{
  "answer": "Jawaban dari CSV...",
  "source": "kb",
  "score": 0.86,
  "intent_id": "1",
  "session": "sess_ab12cd34"
}
```

**Response (Fallback LLM)**

```json
{
  "answer": "Jawaban dari LLM eksternal...",
  "source": "llm",
  "score": 0.41,
  "intent_id": null,
  "session": "sess_ab12cd34"
}
```

**Response (Tidak ada jawaban)**

```json
{
  "answer": "Maaf, informasi itu belum tersedia di basis data kami.",
  "source": "none",
  "score": 0.21,
  "intent_id": null,
  "session": "sess_ab12cd34"
}
```

## Format CSV

File: `backend/intents.csv`
Header wajib: `id,intent_name,samples,answer,keywords`

* **samples**: daftar contoh pertanyaan, dipisah `||`
* **keywords**: kata kunci bantu pencocokan, dipisah `|` (opsional)

Contoh:

```csv
id,intent_name,samples,answer,keywords
1,Jam Layanan,"jam buka berapa||kapan buka||operasionalnya kapan","Layanan buka Senin–Jumat 08.00–16.00 WITA, istirahat 12.00–13.00.","jam|buka|operasional|waktu"
2,Alamat Kantor,"alamat kantor dimana||lokasi kantor||kemana saya harus datang","Alamat kami di Jl. Contoh No. 123, Palu.","alamat|lokasi|maps"
3,Kontak,"hubungi siapa||nomor telepon||cs berapa","Hubungi CS di 0812-3456-7890 (WhatsApp aktif).","kontak|telepon|wa|whatsapp|cs"
```

Tips:

* Gunakan **3–5 sample** yang beragam per intent.
* Masukkan sinonim umum di `samples` atau `keywords`.
* Escape koma dengan tanda kutip jika ada koma di `answer`.

## Konfigurasi & Tuning

* `similarity_threshold` di `config.php`:

  * Naikkan jika jawaban salah sering muncul dari KB.
  * Turunkan jika fallback LLM terlalu sering dipakai.
* Tambahkan kata kunci penting ke kolom `keywords` untuk bonus skor kecil.

## Keamanan

* Jangan commit API key ke repository.
* Batasi CORS pada domain tepercaya di `config.php` saat produksi.
* Pertimbangkan rate-limiting di layer server/reverse proxy.
* Logging minimal dianjurkan untuk audit: pertanyaan, skor, sumber.

## Roadmap

* [ ] Import/validasi CSV via UI admin.
* [ ] Cache CSV ke JSON untuk mempercepat I/O.
* [ ] Saran intent baru saat skor rendah.
* [ ] Rate limit + basic auth opsional untuk API.
* [ ] Testing otomatis (PHPUnit) dan linter.

## Kontribusi

-

## Lisensi

-

## Kredit

Arsitektur: **Knowledge Base (CSV) → Fallback LLM**.
UI: widget sederhana HTML/CSS/JS yang dapat di-embed.

