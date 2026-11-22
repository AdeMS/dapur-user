<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Struktur tabel user.
 *
 * Tabel user_akses memuat hak akses per lingkup ('dashboard' atau nama domain website), sehingga user yang
 * sama bisa diberi akses ke beberapa website. Nama tabel, indeks, dan constraint memakai awalan dari
 * NamaTabel. Urutan tabel penting: tabel yang dirujuk foreign key dibuat lebih dulu.
 */
final class Skema
{
    /** @return list<string> */
    public static function perintah(string $platform, NamaTabel $tabel = new NamaTabel()): array
    {
        return array_values(self::tabel($platform, $tabel));
    }

    /** @return array<string, string> nama tabel => perintah CREATE TABLE */
    public static function tabel(string $platform, NamaTabel $tabel = new NamaTabel()): array
    {
        return match ($platform) {
            'MySQL'  => self::mysql($tabel),
            'SQLite' => self::sqlite($tabel),
            default  => throw new RuntimeException("Modul user belum mendukung database {$platform}."),
        };
    }

    /**
     * Kolom yang ditambahkan sesudah tabelnya pertama kali dibuat. Perintah pasang menambahkan kolom yang belum
     * ada lewat ALTER TABLE, sehingga instalasi lama ikut lengkap tanpa kehilangan data. Kolom baru selalu boleh
     * kosong (NULL) atau punya nilai bawaan, dan tidak pernah dihapus atau diubah.
     *
     * @return array<string, array<string, string>> nama tabel => [nama kolom => definisi kolom]
     */
    public static function kolom(string $platform, NamaTabel $tabel = new NamaTabel()): array
    {
        return match ($platform) {
            'MySQL'  => [
                // 2.2: email yang dipakai user di lingkup ini; kosong berarti email utama.
                $tabel->akses()   => ['email_id' => 'BIGINT UNSIGNED NULL'],
                // 2.2: email yang dipakai untuk login (akun bisa punya beberapa email).
                $tabel->riwayat() => ['email' => "VARCHAR(190) NOT NULL DEFAULT ''"],
                // 2.3: nama yang diisi sendiri oleh user (nama lengkap tetap di kolom nama) dan foto akun.
                $tabel->user()    => [
                    'nama_depan'    => "VARCHAR(100) NOT NULL DEFAULT ''",
                    'nama_tengah'   => "VARCHAR(100) NOT NULL DEFAULT ''",
                    'nama_belakang' => "VARCHAR(100) NOT NULL DEFAULT ''",
                    'foto'          => "VARCHAR(64) NOT NULL DEFAULT ''",
                ],
            ],
            'SQLite' => [
                $tabel->akses()   => ['email_id' => 'INTEGER NULL'],
                $tabel->riwayat() => ['email' => "TEXT NOT NULL DEFAULT ''"],
                $tabel->user()    => [
                    'nama_depan'    => "TEXT NOT NULL DEFAULT ''",
                    'nama_tengah'   => "TEXT NOT NULL DEFAULT ''",
                    'nama_belakang' => "TEXT NOT NULL DEFAULT ''",
                    'foto'          => "TEXT NOT NULL DEFAULT ''",
                ],
            ],
            default  => throw new RuntimeException("Modul user belum mendukung database {$platform}."),
        };
    }

    /** @return array<string, string> */
    private static function mysql(NamaTabel $t): array
    {
        $opsi = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $q    = static fn (string $nama): string => '`' . $nama . '`';
        $a    = $t->awalan;

        return [
            $t->user() => "CREATE TABLE IF NOT EXISTS {$q($t->user())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                nama VARCHAR(190) NOT NULL,
                kata_sandi VARCHAR(255) NOT NULL,
                aktif TINYINT(1) NOT NULL DEFAULT 1,
                dibuat_pada DATETIME NOT NULL,
                terakhir_masuk DATETIME NULL,
                nama_depan VARCHAR(100) NOT NULL DEFAULT '',
                nama_tengah VARCHAR(100) NOT NULL DEFAULT '',
                nama_belakang VARCHAR(100) NOT NULL DEFAULT '',
                foto VARCHAR(64) NOT NULL DEFAULT '',
                UNIQUE KEY {$q("uq_{$a}user_email")} (email)
            ) {$opsi}",
            $t->akses() => "CREATE TABLE IF NOT EXISTS {$q($t->akses())} (
                user_id BIGINT UNSIGNED NOT NULL,
                lingkup VARCHAR(190) NOT NULL,
                peran VARCHAR(50) NOT NULL,
                email_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (user_id, lingkup),
                CONSTRAINT {$q("fk_{$a}user_akses_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->percobaan() => "CREATE TABLE IF NOT EXISTS {$q($t->percobaan())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kunci CHAR(64) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                waktu DATETIME NOT NULL,
                KEY {$q("idx_{$a}user_percobaan_kunci")} (kunci, ip, waktu),
                KEY {$q("idx_{$a}user_percobaan_ip")} (ip, waktu),
                KEY {$q("idx_{$a}user_percobaan_waktu")} (waktu)
            ) {$opsi}",
            $t->riwayat() => "CREATE TABLE IF NOT EXISTS {$q($t->riwayat())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                lingkup VARCHAR(190) NOT NULL,
                peristiwa VARCHAR(20) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                perangkat VARCHAR(255) NOT NULL DEFAULT '',
                waktu DATETIME NOT NULL,
                email VARCHAR(190) NOT NULL DEFAULT '',
                KEY {$q("idx_{$a}user_riwayat_user")} (user_id, waktu),
                KEY {$q("idx_{$a}user_riwayat_waktu")} (waktu),
                CONSTRAINT {$q("fk_{$a}user_riwayat_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->riwayatAkun() => "CREATE TABLE IF NOT EXISTS {$q($t->riwayatAkun())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                pelaku_id BIGINT UNSIGNED NULL,
                sasaran_id BIGINT UNSIGNED NOT NULL,
                lingkup VARCHAR(190) NOT NULL,
                peristiwa VARCHAR(30) NOT NULL,
                keterangan VARCHAR(255) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                perangkat VARCHAR(255) NOT NULL DEFAULT '',
                waktu DATETIME NOT NULL,
                KEY {$q("idx_{$a}user_riwayat_akun_sasaran")} (sasaran_id, waktu),
                KEY {$q("idx_{$a}user_riwayat_akun_lingkup")} (lingkup, waktu),
                CONSTRAINT {$q("fk_{$a}user_riwayat_akun_pelaku")} FOREIGN KEY (pelaku_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE SET NULL,
                CONSTRAINT {$q("fk_{$a}user_riwayat_akun_sasaran")} FOREIGN KEY (sasaran_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->token() => "CREATE TABLE IF NOT EXISTS {$q($t->token())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                jenis VARCHAR(30) NOT NULL,
                hash CHAR(64) NOT NULL,
                data VARCHAR(255) NOT NULL DEFAULT '',
                kedaluwarsa DATETIME NOT NULL,
                dipakai DATETIME NULL,
                dibuat_pada DATETIME NOT NULL,
                UNIQUE KEY {$q("uq_{$a}user_token_hash")} (hash),
                KEY {$q("idx_{$a}user_token_user")} (user_id, jenis),
                CONSTRAINT {$q("fk_{$a}user_token_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->pengaturan() => "CREATE TABLE IF NOT EXISTS {$q($t->pengaturan())} (
                user_id BIGINT UNSIGNED NOT NULL,
                kunci VARCHAR(190) NOT NULL,
                nilai VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (user_id, kunci),
                CONSTRAINT {$q("fk_{$a}user_pengaturan_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->email() => "CREATE TABLE IF NOT EXISTS {$q($t->email())} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                terverifikasi DATETIME NOT NULL,
                dibuat_pada DATETIME NOT NULL,
                UNIQUE KEY {$q("uq_{$a}user_email_email")} (email),
                KEY {$q("idx_{$a}user_email_user")} (user_id),
                CONSTRAINT {$q("fk_{$a}user_email_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
            $t->profil() => "CREATE TABLE IF NOT EXISTS {$q($t->profil())} (
                user_id BIGINT UNSIGNED NOT NULL,
                lingkup VARCHAR(190) NOT NULL DEFAULT '',
                kunci VARCHAR(50) NOT NULL,
                nilai TEXT NOT NULL,
                diubah_pada DATETIME NOT NULL,
                PRIMARY KEY (user_id, lingkup, kunci),
                CONSTRAINT {$q("fk_{$a}user_profil_user")} FOREIGN KEY (user_id)
                    REFERENCES {$q($t->user())} (id) ON DELETE CASCADE
            ) {$opsi}",
        ];
    }

    /** @return array<string, string> */
    private static function sqlite(NamaTabel $t): array
    {
        $q = static fn (string $nama): string => '"' . $nama . '"';

        return [
            $t->user() => "CREATE TABLE IF NOT EXISTS {$q($t->user())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                nama TEXT NOT NULL,
                kata_sandi TEXT NOT NULL,
                aktif INTEGER NOT NULL DEFAULT 1,
                dibuat_pada TEXT NOT NULL,
                terakhir_masuk TEXT NULL,
                nama_depan TEXT NOT NULL DEFAULT '',
                nama_tengah TEXT NOT NULL DEFAULT '',
                nama_belakang TEXT NOT NULL DEFAULT '',
                foto TEXT NOT NULL DEFAULT ''
            )",
            $t->akses() => "CREATE TABLE IF NOT EXISTS {$q($t->akses())} (
                user_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                lingkup TEXT NOT NULL,
                peran TEXT NOT NULL,
                email_id INTEGER NULL,
                PRIMARY KEY (user_id, lingkup)
            )",
            $t->percobaan() => "CREATE TABLE IF NOT EXISTS {$q($t->percobaan())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kunci TEXT NOT NULL,
                ip TEXT NOT NULL,
                waktu TEXT NOT NULL
            )",
            $t->riwayat() => "CREATE TABLE IF NOT EXISTS {$q($t->riwayat())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                lingkup TEXT NOT NULL,
                peristiwa TEXT NOT NULL,
                ip TEXT NOT NULL,
                perangkat TEXT NOT NULL DEFAULT '',
                waktu TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT ''
            )",
            $t->riwayatAkun() => "CREATE TABLE IF NOT EXISTS {$q($t->riwayatAkun())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pelaku_id INTEGER NULL REFERENCES {$q($t->user())} (id) ON DELETE SET NULL,
                sasaran_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                lingkup TEXT NOT NULL,
                peristiwa TEXT NOT NULL,
                keterangan TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '',
                perangkat TEXT NOT NULL DEFAULT '',
                waktu TEXT NOT NULL
            )",
            $t->token() => "CREATE TABLE IF NOT EXISTS {$q($t->token())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                jenis TEXT NOT NULL,
                hash TEXT NOT NULL UNIQUE,
                data TEXT NOT NULL DEFAULT '',
                kedaluwarsa TEXT NOT NULL,
                dipakai TEXT NULL,
                dibuat_pada TEXT NOT NULL
            )",
            $t->pengaturan() => "CREATE TABLE IF NOT EXISTS {$q($t->pengaturan())} (
                user_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                kunci TEXT NOT NULL,
                nilai TEXT NOT NULL DEFAULT '',
                PRIMARY KEY (user_id, kunci)
            )",
            $t->email() => "CREATE TABLE IF NOT EXISTS {$q($t->email())} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                email TEXT NOT NULL UNIQUE,
                terverifikasi TEXT NOT NULL,
                dibuat_pada TEXT NOT NULL
            )",
            $t->profil() => "CREATE TABLE IF NOT EXISTS {$q($t->profil())} (
                user_id INTEGER NOT NULL REFERENCES {$q($t->user())} (id) ON DELETE CASCADE,
                lingkup TEXT NOT NULL DEFAULT '',
                kunci TEXT NOT NULL,
                nilai TEXT NOT NULL,
                diubah_pada TEXT NOT NULL,
                PRIMARY KEY (user_id, lingkup, kunci)
            )",
        ];
    }
}
