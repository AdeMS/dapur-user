<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/** User yang login. Tersedia sebagai atribut request bernama User::class setelah login. */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $nama,
        public readonly string $kataSandi,
        public readonly bool $aktif,
        public readonly string $namaDepan = '',
        public readonly string $namaTengah = '',
        public readonly string $namaBelakang = '',
        /** Kode file foto akun (lihat FotoUser), kosong bila tidak ada foto. */
        public readonly string $foto = '',
    ) {
    }

    /**
     * Nama lengkap dari nama depan, tengah, dan belakang, untuk disimpan di kolom nama.
     */
    public static function gabungNama(string $depan, string $tengah, string $belakang): string
    {
        return implode(' ', array_filter(array_map('trim', [$depan, $tengah, $belakang]), static fn (string $k): bool => $k !== ''));
    }

    /**
     * Inisial untuk avatar: huruf pertama nama depan dan nama belakang ("Ade M Saragih" menjadi "AS").
     * Bila user sudah mengisi nama depan dan belakang sendiri, keduanya yang dipakai. Nama satu kata memakai satu
     * huruf; nama kosong memakai huruf pertama email.
     */
    public function inisial(): string
    {
        if ($this->namaDepan !== '' && $this->namaBelakang !== '') {
            return self::inisialDari($this->namaDepan . ' ' . $this->namaBelakang, $this->email);
        }

        return self::inisialDari($this->nama, $this->email);
    }

    /** Inisial dari sembarang nama (misalnya nama tampilan di website), cadangan huruf pertama email. */
    public static function inisialDari(string $nama, string $email = ''): string
    {
        $kata = preg_split('/\s+/u', trim($nama), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($kata === []) {
            $kata = [$email];
        }

        $huruf = self::hurufPertama($kata[0]);
        if (count($kata) > 1) {
            $huruf .= self::hurufPertama($kata[count($kata) - 1]);
        }

        return function_exists('mb_strtoupper') ? mb_strtoupper($huruf, 'UTF-8') : strtoupper($huruf);
    }

    /**
     * Sidik jari kata sandi untuk session. Bila kata sandi diganti, sidik jari berubah, sehingga session
     * lain milik user ini (di perangkat lain) tidak berlaku lagi. Yang disimpan di session hanya hash dari
     * hash kata sandi, bukan hash itu sendiri.
     */
    public function sidikSandi(): string
    {
        return hash('sha256', 'dapur-user-sesi:' . $this->kataSandi);
    }

    private static function hurufPertama(string $kata): string
    {
        return preg_match('/^\X/u', $kata, $cocok) === 1 ? $cocok[0] : '';
    }
}
