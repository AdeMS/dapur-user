<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Nama tabel user, dengan awalan dari pengaturan 'awalan_tabel'.
 *
 * Tanpa awalan, tabelnya bernama user, user_akses, user_percobaan_masuk, user_riwayat_masuk,
 * user_riwayat_akun, dan user_pengaturan. Awalan hanya boleh berisi huruf, angka, dan garis bawah, karena ikut tersusun ke dalam perintah SQL.
 */
final class NamaTabel
{
    public readonly string $awalan;

    public function __construct(string $awalan = '')
    {
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $awalan) !== 1) {
            throw new RuntimeException(
                "Pengaturan 'awalan_tabel' user tidak sah: hanya huruf, angka, dan garis bawah, paling panjang 32 karakter.",
            );
        }

        $this->awalan = $awalan;
    }

    /** @param array<string, mixed> $config isi $config['user'] */
    public static function dariPengaturan(array $config): self
    {
        $awalan = $config['awalan_tabel'] ?? '';
        if (! is_string($awalan)) {
            throw new RuntimeException("Pengaturan 'awalan_tabel' user harus berupa teks.");
        }

        return new self($awalan);
    }

    public function user(): string
    {
        return $this->awalan . 'user';
    }

    public function akses(): string
    {
        return $this->awalan . 'user_akses';
    }

    public function percobaan(): string
    {
        return $this->awalan . 'user_percobaan_masuk';
    }

    public function riwayat(): string
    {
        return $this->awalan . 'user_riwayat_masuk';
    }

    public function riwayatAkun(): string
    {
        return $this->awalan . 'user_riwayat_akun';
    }

    public function pengaturan(): string
    {
        return $this->awalan . 'user_pengaturan';
    }
}
