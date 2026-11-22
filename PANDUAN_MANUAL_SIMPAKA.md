# Panduan Manual Aplikasi **SIMPAKA**
**Sistem Informasi Manajemen Perumahan Alam Kerato Asri (AKA)**

---

## 1. Tentang Aplikasi
**SIMPAKA** adalah aplikasi manajemen perumahan berbasis web **Mobile-First (PWA)** yang dibangun dengan **PHP Native** dan database **MySQL/MariaDB**. Aplikasi ini dirancang agar ringan diakses melalui HP Android maupun iPhone, dapat di-install ke layar utama layaknya aplikasi native, serta menggunakan URL bersih tanpa ekstensi `.php`.

### Pembagian Hak Akses (Role)
1. **Super Admin (Pengembang):** Mengatur seluruh konfigurasi website, referensi wilayah RT/RW, akun pengurus RT/RW, jenis iuran, struktur pengurus, QRIS, favicon, footer branding, API WhatsApp/OneSignal, serta memiliki akses penuh ke seluruh wilayah.
2. **Admin (Pengurus RT/RW):** Mengelola data blok rumah, data warga, verifikasi pembayaran, pencatatan kas & pengeluaran bendahara, pengumuman, serta aspirasi warga **khusus di wilayah RT/RW masing-masing**.
3. **Warga AKA:** Melihat informasi perumahan, status iuran pribadi, melakukan pembayaran + upload bukti setor/transfer, memantau transparansi kas & laporan, menyapa blok yang belum bayar, serta mengirim aspirasi/aduan.

---

## 2. Panduan Login
- Buka halaman **Login** (`/login`).
- **Warga AKA:** Masuk menggunakan **Nomor HP Aktif** yang didaftarkan oleh pengurus + Password.
- **Pengurus RT/RW & Super Admin:** Masuk menggunakan **Username** atau **Nomor HP Aktif** + Password.
- *Catatan Keamanan:* Aplikasi tidak menyediakan pendaftaran mandiri. Seluruh akun warga dan pengurus didaftarkan melalui backend agar data wilayah tetap valid.

---

## 3. Panduan Super Admin (Pengembang)

### A. Pengaturan Website (`/admin/settings`)
Menu ini hanya dapat diakses oleh **Super Admin**:
1. **Identitas & Footer Branding:** Mengubah nama aplikasi, nama perumahan, subjudul, serta teks **Footer Branding** (default: `albiandra-project (perumahan alam kerato asri blok D.17)`).
2. **Rekening, QRIS & Favicon:** Mengisi nomor rekening/e-wallet resmi bendahara, mengunggah gambar **QRIS**, serta mengunggah **Favicon Website** (`PNG/JPG/ICO`).
3. **Kebijakan Iuran & Notifikasi Otomatis:**
   - Mengaktifkan/menonaktifkan **Pembayaran Iuran Bulanan** (default: Aktif).
   - Mengatur tanggal terbit tagihan & notifikasi otomatis bulanan (`1`–`28`).
   - Mengisi **URL & Token API WhatsApp Gateway** (opsional) serta **OneSignal App ID & REST API Key** (opsional, untuk push notification latar belakang).
4. **Referensi Wilayah RT & RW:** Menambah/mengedit daftar nomor RT & RW resmi perumahan (sebagai acuan dropdown di seluruh sistem agar tidak ada salah ketik).
5. **Jenis & Nominal Iuran:** Mengelola daftar iuran `bulanan` atau `insidental` beserta nominal default dan status aktifnya.
6. **Struktur Pengurus:** Mengatur pengurus RT/RW maupun paguyuban yang tampil di Beranda, lengkap dengan pilihan wilayah RT/RW dari referensi dan foto profil.
7. **Akun Pengurus (Admin / Super Admin):** Membuat akun pengurus RT/RW dan menetapkan wilayah tugas **RT & RW** masing-masing.

---

## 4. Panduan Admin (Pengurus RT/RW)

### A. Mengatur Cakupan Blok Wilayah (`/admin/wilayah`)
1. Klik **Tambah**, lalu masukkan cakupan blok **satu per satu**: pilih **Abjad Blok (`A`–`Z`)**, isi **Nomor Blok** (misal `01`, `17`), dan pilih **RT / RW** dari referensi.
2. Centang opsi sinkronisasi jika ingin sekaligus mendaftarkan unit tersebut ke data **Blok Rumah**.
3. Keterangan cakupan blok pada tiap RT/RW akan terangkum otomatis.

### B. Mengelola Blok Rumah (`/admin/blok_rumah`)
- Menambah, mengedit, mencari, dan menghapus unit rumah (`Dihuni`, `Kosong`, `Dikontrakkan`).
- Saat memilih Abjad & Nomor Rumah yang sudah terdaftar di cakupan blok, wilayah **RT & RW** otomatis terisi.

### C. Mengelola Data Pemilik & Warga (`/admin/warga`)
1. Klik **Tambah** pada modal Data Warga.
2. Isi Nama Lengkap, No. HP Aktif (untuk login & WhatsApp), lalu pilih **Blok & Nomor Rumah** — field **Wilayah RT / RW** akan **terisi otomatis** tanpa perlu dipilih manual.
3. Pilih **Status KTP** (tanpa menyimpan NIK): *Penduduk Asli Perumahan* atau *Masih Domisili Asal / Luar* beserta daerah asalnya.
4. Pilih **Status Penghuni** (*Pemilik* / *Penyewa*) dan **Status Tinggal** (*Menetap*, *Tidak Tinggal / Pemilik Tidak Ada*, *Sesekali / Pulang Pergi*, atau *Rumah Dikontrakkan*).

### D. Tracking & Verifikasi Faktual Pembayaran (`/admin/pembayaran`)
1. **Generate Tagihan Bulanan:** Klik **Generate Bln Ini** untuk menerbitkan tagihan bulanan massal sekaligus mengirim notifikasi ke warga yang belum membayar.
2. **Verifikasi Faktual:** Ketika warga mengirim bukti transfer, Admin RT/RW menerima notifikasi otomatis. Klik **Cek Bukti** pada transaksi berstatus *Menunggu Verifikasi* untuk melihat preview screenshot bukti transfer secara langsung, lalu klik **Setujui & Lunaskan** atau **Tolak**.
3. **Masuk Laporan Otomatis:** Setiap transaksi yang berstatus **Lunas** otomatis tercatat sebagai pemasukan di tabel kas (`keuangan_kas`) dan langsung tampil di halaman Laporan.
4. **Kebijakan Iuran Bulanan:** Pengurus dapat mengaktifkan/menonaktifkan pembayaran bulanan melalui switch di bagian atas halaman jika ada kesepakatan khusus warga.

### E. Pencatatan Kas & Pengeluaran Bendahara (`/admin/keuangan`)
- Mencatat pemasukan lainnya atau **Pengeluaran Bendahara** lengkap dengan foto bukti nota.
- Jika opsi notifikasi dicentang saat mencatat pengeluaran, sistem otomatis mengirim **Notifikasi Pengeluaran Bendahara** ke seluruh warga sebagai bentuk transparansi.

### F. Laporan Keuangan (`/admin/laporan`)
- Tersedia dalam 3 mode: **Bulanan**, **Triwulan (Q1–Q4)**, dan **Tahunan (Jan–Des)**.
- Format tabel **Pemasukan Iuran Warga** menampilkan: `NO | Nama Warga | Blok | RT | RW | Bulan | Jumlah | Keterangan`, diikuti **Daftar Pengeluaran Bendahara**.
- Tersedia tombol **Ekspor Excel (`.xls`)** dan **Cetak / PDF** untuk masing-masing mode.

### G. Info Index (`/admin/cms_info`) & Aspirasi (`/admin/aspirasi`)
- Mengatur pengumuman perumahan, sambutan, alamat, serta link/embed Google Maps pada Beranda.
- Memantau dan menanggapi keluhan atau saran dari warga.

---

## 5. Panduan Warga AKA

1. **Beranda (`/`):**
   - Melihat pengumuman terbaru, kartu wilayah RT/RW (klik **Detail Blok** untuk melihat daftar blok tanpa data pribadi), struktur pengurus, lokasi peta, serta grafik kas 6 bulan terakhir (setelah login).
   - Pada kartu **Blok Belum Bayar**, warga dapat melihat blok di wilayahnya yang belum melunasi iuran bulan berjalan dan menekan tombol **Tegur / Sapa** untuk mengirim sapaan pengingat secara sopan.
2. **Membayar Iuran (`/warga/bayar` atau tombol Tengah "Bayar"):**
   - Salin **Nomor Rekening** atau scan **QRIS Bendahara AKA** yang tampil di bagian atas.
   - Unggah foto bukti transfer/setor, lalu gunakan tombol **Konfirmasi via WA** untuk mengirim konfirmasi langsung ke WhatsApp Admin RT/RW wilayahnya.
3. **Riwayat (`/warga/riwayat`), Kas AKA (`/warga/kas_transparan`), & Laporan (`/admin/laporan`):**
   - Memeriksa riwayat pembayaran pribadi beserta detail bukti transfer, serta memantau laporan keuangan bulanan, triwulan, dan tahunan secara transparan.
4. **Install Aplikasi di HP (PWA) & Notifikasi:**
   - **Android (Chrome):** Aplikasi otomatis menawarkan pemasangan saat disentuh atau melalui menu **Download App** &rarr; ketuk **Install**.
   - **iPhone (Safari):** Ketuk menu **Download App** untuk melihat panduan (*Share* &rarr; *Tambahkan ke Layar Utama*). Setelah terpasang, ajakan download otomatis hilang.

---

## 6. Panduan Deploy ke Hosting cPanel & GitHub

1. **Siapkan Database:** Buat database MySQL di cPanel dan import file `database.sql` (berisi struktur tabel bersih dan 1 akun `superadmin`).
2. **Kredensial Lokal Hosting:** Buat file `config/database.local.php` di server hosting berisi `define('DB_HOST', ...)`, `DB_NAME`, `DB_USER`, `DB_PASS` agar tidak tertimpa saat `git pull`.
3. **Aktifkan SSL (HTTPS):** Pastikan domain sudah menggunakan HTTPS agar fitur `mod_rewrite` (URL tanpa `.php`), install PWA, dan notifikasi HP berjalan optimal.
4. **Auto-Deploy GitHub:** Gunakan `.cpanel.yml` (cPanel Git Version Control) atau arahkan GitHub Webhook ke `https://domain-anda.com/deploy` dengan secret `SIMPAKA_AKA_AutoDeploy_2026!`.

---
*Dokumentasi resmi SIMPAKA — albiandra-project (perumahan alam kerato asri blok D.17)*
