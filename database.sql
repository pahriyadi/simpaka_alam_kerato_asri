-- SIMPAKA (Sistem Informasi Manajemen Perumahan Alam Kerato Asri)
-- Database Schema & Initial Seed Data
-- Pilih database tujuan di phpMyAdmin sebelum melakukan Import.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `log_aktivitas`;
DROP TABLE IF EXISTS `kunjungan`;
DROP TABLE IF EXISTS `push_subscriptions`;
DROP TABLE IF EXISTS `referensi_blok`;
DROP TABLE IF EXISTS `referensi_rt_rw`;
DROP TABLE IF EXISTS `notifikasi_baca`;
DROP TABLE IF EXISTS `notifikasi`;
DROP TABLE IF EXISTS `aspirasi`;
DROP TABLE IF EXISTS `struktur_organisasi`;
DROP TABLE IF EXISTS `pengumuman`;
DROP TABLE IF EXISTS `keuangan_kas`;
DROP TABLE IF EXISTS `pembayaran`;
DROP TABLE IF EXISTS `jenis_iuran`;
DROP TABLE IF EXISTS `warga_profil`;
DROP TABLE IF EXISTS `blok_rumah`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_lengkap` VARCHAR(120) NOT NULL,
  `no_hp` VARCHAR(20) NOT NULL,
  `username` VARCHAR(60) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('super_admin', 'admin', 'warga') NOT NULL DEFAULT 'warga',
  `rt` VARCHAR(10) DEFAULT NULL,
  `rw` VARCHAR(10) DEFAULT NULL,
  `status_aktif` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_no_hp` (`no_hp`),
  UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blok_rumah` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_blok` VARCHAR(20) NOT NULL,
  `nomor_rumah` VARCHAR(20) NOT NULL,
  `rt` VARCHAR(10) NOT NULL DEFAULT '01',
  `rw` VARCHAR(10) NOT NULL DEFAULT '01',
  `status_hunian` ENUM('dihuni', 'kosong', 'dikontrakkan') NOT NULL DEFAULT 'dihuni',
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blok_nomor` (`nama_blok`, `nomor_rumah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warga_profil` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `blok_rumah_id` INT UNSIGNED DEFAULT NULL,
  `rt` VARCHAR(10) NOT NULL DEFAULT '01',
  `rw` VARCHAR(10) NOT NULL DEFAULT '01',
  `status_ktp` ENUM('penduduk_asli_perumahan', 'domisili_luar') NOT NULL DEFAULT 'domisili_luar',
  `asal_domisili` VARCHAR(120) DEFAULT NULL,
  `status_penghuni` ENUM('pemilik', 'penyewa') NOT NULL DEFAULT 'pemilik',
  `status_tinggal` ENUM('menetap', 'tidak_tinggal', 'sesekali', 'dikontrakkan') NOT NULL DEFAULT 'menetap',
  `jumlah_anggota_keluarga` INT UNSIGNED NOT NULL DEFAULT 1,
  `tanggal_masuk` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_warga_user` (`user_id`),
  KEY `fk_warga_blok` (`blok_rumah_id`),
  CONSTRAINT `fk_warga_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warga_blok` FOREIGN KEY (`blok_rumah_id`) REFERENCES `blok_rumah` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jenis_iuran` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_iuran` VARCHAR(100) NOT NULL,
  `nominal_default` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `periode` ENUM('bulanan', 'insidental') NOT NULL DEFAULT 'bulanan',
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pembayaran` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_transaksi` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `blok_rumah_id` INT UNSIGNED DEFAULT NULL,
  `jenis_iuran_id` INT UNSIGNED NOT NULL,
  `bulan` TINYINT UNSIGNED NOT NULL,
  `tahun` SMALLINT UNSIGNED NOT NULL,
  `nominal` DECIMAL(12,2) NOT NULL,
  `metode_bayar` ENUM('tunai', 'transfer', 'qris') DEFAULT 'transfer',
  `bukti_bayar` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('belum_bayar', 'menunggu_verifikasi', 'lunas', 'ditolak') NOT NULL DEFAULT 'belum_bayar',
  `catatan` VARCHAR(255) DEFAULT NULL,
  `verified_by` INT UNSIGNED DEFAULT NULL,
  `tanggal_bayar` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kode_transaksi` (`kode_transaksi`),
  KEY `idx_pembayaran_periode` (`tahun`, `bulan`, `status`),
  CONSTRAINT `fk_bayar_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bayar_blok` FOREIGN KEY (`blok_rumah_id`) REFERENCES `blok_rumah` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bayar_jenis` FOREIGN KEY (`jenis_iuran_id`) REFERENCES `jenis_iuran` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `keuangan_kas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tanggal` DATE NOT NULL,
  `jenis` ENUM('pemasukan', 'pengeluaran') NOT NULL,
  `kategori` VARCHAR(80) NOT NULL,
  `deskripsi` TEXT NOT NULL,
  `nominal` DECIMAL(14,2) NOT NULL,
  `bukti_nota` VARCHAR(255) DEFAULT NULL,
  `pembayaran_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kas_tanggal` (`tanggal`, `jenis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pengumuman` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `judul` VARCHAR(180) NOT NULL,
  `isi` TEXT NOT NULL,
  `kategori` ENUM('pengumuman', 'kegiatan', 'darurat') NOT NULL DEFAULT 'pengumuman',
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `struktur_organisasi` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama` VARCHAR(120) NOT NULL,
  `jabatan` VARCHAR(100) NOT NULL,
  `rt` VARCHAR(10) DEFAULT NULL,
  `rw` VARCHAR(10) DEFAULT NULL,
  `no_hp` VARCHAR(20) DEFAULT NULL,
  `foto` VARCHAR(255) DEFAULT NULL,
  `urutan` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifikasi` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL berarti broadcast untuk semua warga',
  `judul` VARCHAR(150) NOT NULL,
  `pesan` TEXT NOT NULL,
  `tipe` ENUM('tagihan_bulanan', 'pengeluaran_bendahara', 'pengumuman', 'pembayaran') NOT NULL DEFAULT 'pengumuman',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifikasi_baca` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notifikasi_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notif_user_baca` (`notifikasi_id`, `user_id`),
  CONSTRAINT `fk_nb_notif` FOREIGN KEY (`notifikasi_id`) REFERENCES `notifikasi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `aspirasi` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `judul` VARCHAR(150) NOT NULL,
  `isi` TEXT NOT NULL,
  `kategori` ENUM('keluhan', 'saran', 'keamanan', 'kebersihan') NOT NULL DEFAULT 'saran',
  `status` ENUM('baru', 'diproses', 'selesai') NOT NULL DEFAULT 'baru',
  `tanggapan` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_aspirasi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `log_aktivitas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `aksi` VARCHAR(100) NOT NULL,
  `detail` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `push_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `endpoint` TEXT NOT NULL,
  `p256dh` VARCHAR(255) DEFAULT NULL,
  `auth` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ps_user` (`user_id`),
  CONSTRAINT `fk_ps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kunjungan` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tanggal` DATE NOT NULL,
  `ip_hash` VARCHAR(64) NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tgl_ip` (`tanggal`, `ip_hash`),
  KEY `idx_kunjungan_tgl` (`tanggal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referensi_rt_rw` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rt` VARCHAR(10) NOT NULL,
  `rw` VARCHAR(10) NOT NULL,
  `cakupan_blok` VARCHAR(120) DEFAULT NULL,
  `keterangan` VARCHAR(120) DEFAULT NULL,
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rt_rw` (`rt`, `rw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referensi_blok` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_blok` VARCHAR(10) NOT NULL,
  `nomor_blok` VARCHAR(20) NOT NULL,
  `rt` VARCHAR(10) NOT NULL,
  `rw` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blok_nomor` (`kode_blok`, `nomor_blok`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- SEED DATA AWAL BERSIH (Hanya Akun Super Admin & Settings)
-- Password default superadmin: password123 (segera ubah setelah login)
-- ========================================================
INSERT INTO `users` (`id`, `nama_lengkap`, `no_hp`, `username`, `password_hash`, `role`, `rt`, `rw`, `status_aktif`) VALUES
(1, 'Albiandra (Pengembang)', '081100000001', 'superadmin', '$2y$10$xzqhVq6sEdTWCTuplPDtbuExsCk7Z9lOAxxKka8tD7zUP2mZdqHFu', 'super_admin', NULL, NULL, 1);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'SIMPAKA'),
('app_subtitle', 'Sistem Informasi Manajemen Perumahan Alam Kerato Asri'),
('nama_perumahan', 'Perumahan Alam Kerato Asri (AKA)'),
('alamat_perumahan', 'Jl. Alam Kerato Asri, Kel. Kerato, Kec. Unter Iwes, Kab. Sumbawa'),
('maps_embed_url', ''),
('maps_link_url', ''),
('sambutan_index', 'Portal resmi informasi, iuran warga, dan transparansi kas Perumahan Alam Kerato Asri berbasis mobile.'),
('info_rekening', ''),
('qris_image', ''),
('favicon_image', 'assets/img/logo-aka.jpg'),
('og_image', 'assets/img/logo-aka.jpg'),
('seo_description', 'SIMPAKA - Sistem Informasi Manajemen Perumahan Alam Kerato Asri. Portal informasi warga, pembayaran iuran, dan transparansi kas perumahan berbasis mobile.'),
('allow_bayar_bulanan', '1'),
('footer_branding', 'albiandra-project (perumahan alam kerato asri blok D.17)'),
('tampilkan_kas_publik', '0'),
('auto_notif_enabled', '1'),
('auto_notif_day', '1'),
('wa_gateway_url', ''),
('wa_gateway_token', ''),
('onesignal_app_id', ''),
('onesignal_api_key', ''),
('last_auto_billing_ym', '');
