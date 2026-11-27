<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Tempat foto profil disimpan. Bawaannya FotoUser (folder data/upload/avatar, satu file per foto, tanpa riwayat).
 * Modul lain bisa menggantinya dengan mendaftarkan layanan `PenyimpanFoto::class` di container, misalnya
 * adems/dapur-berkas yang menyimpan foto asli di folder "Foto Profil" milik akun dan menyimpan riwayat fotonya.
 *
 * Kode foto adalah kode acak yang tidak bisa ditebak: 32 huruf heksadesimal (FotoUser) atau 22 huruf/angka (berkas).
 * Kode yang sama dipakai di kolom foto akun, di profil per website, dan di alamat `<alamat dasar>/foto/<kode>`.
 */
interface PenyimpanFoto
{
    /** Apakah foto bisa diunggah sekarang (fungsi gambar PHP tersedia dan tempat penyimpanan bisa ditulis). */
    public function siap(): bool;

    /**
     * Menyimpan foto dari file unggahan milik akun ini.
     *
     * @return string kode foto baru
     * @throws RuntimeException dengan pesan yang bisa ditampilkan kepada user
     */
    public function simpan(int $userId, string $fileUnggahan): string;

    /** File gambar avatar (persegi, WebP) untuk kode ini, atau null bila tidak ada. */
    public function file(string $kode): ?string;

    /** Foto ini tidak dipakai lagi (diganti atau dihapus dari profil). Penyimpan tanpa riwayat menghapus filenya. */
    public function lepas(string $kode): void;

    /** Nomor akun pemilik foto di riwayat, atau null bila kode ini bukan foto dari penyimpan ini. */
    public function pemilik(string $kode): ?int;

    /** Apakah penyimpan ini menyimpan riwayat foto (foto lama bisa dipilih lagi). */
    public function adaRiwayat(): bool;

    /**
     * Foto-foto milik akun, terbaru dulu.
     *
     * @return list<array{kode: string, nama: string, waktu: string}> waktu dalam UTC (Y-m-d H:i:s)
     */
    public function riwayat(int $userId): array;

    /**
     * Membuang foto dari riwayat (ke tempat sampah bila penyimpannya punya). Pemanggil memastikan foto tidak sedang dipakai.
     *
     * @throws RuntimeException dengan pesan yang bisa ditampilkan kepada user
     */
    public function buang(int $userId, string $kode): void;
}
