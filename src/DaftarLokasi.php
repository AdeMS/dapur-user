<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/**
 * Sumber saran untuk isian Lokasi di bagian Tentang.
 *
 * Paket ini membawa satu penerapan, SumberLokasi (membaca tabel kota lewat pengaturan 'user' => ['lokasi' => ...]).
 * Modul lain, misalnya modul wilayah, bisa menyediakan penerapannya sendiri dengan mendaftarkan layanan bernama
 * DaftarLokasi::class di container; layanan itu dipakai lebih dulu daripada pengaturan 'lokasi'.
 */
interface DaftarLokasi
{
    /**
     * Saran tempat yang namanya cocok dengan teks yang diketik, paling banyak $jumlah.
     *
     * @return list<array{id: int, teks: string}> teks = nama lengkap untuk ditampilkan dan disimpan,
     *                                            mis. "Kota Bandung, Jawa Barat, Indonesia"
     */
    public function cari(string $teks, int $jumlah = 5): array;

    /**
     * Satu tempat menurut nomornya, atau null bila tidak ada. Dipakai untuk memastikan pilihan yang dikirim form
     * memang ada dan teksnya sama dengan teks saran.
     *
     * @return array{id: int, teks: string}|null
     */
    public function ambil(int $id): ?array;
}
