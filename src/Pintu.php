<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Satu pintu login: bagian website yang dijaga (misalnya dashboard), lingkup akses yang diperlukan, dan
 * alamat halaman masuk serta keluar di dalamnya.
 *
 * Modul yang memakai login, seperti dashboard, membuat pintunya sendiri lalu memberikannya ke
 * WajibMasukMiddleware, MasukHandler, KeluarHandler, dan ProfilHandler.
 */
final class Pintu
{
    /** Alamat dasar tanpa garis miring akhir; string kosong berarti akar website. */
    public readonly string $alamatDasar;

    public function __construct(
        public readonly string $lingkup,
        string $alamatDasar = '/',
        public readonly int $batasDiam = 3600,
        public readonly string $layout = 'layout::default',
    ) {
        $this->alamatDasar = self::normalkanAlamat($alamatDasar);
    }

    /** Memeriksa alamat dasar ('/', '/dashboard', '/admin/'); hasilnya tanpa garis miring akhir. */
    public static function normalkanAlamat(mixed $alamat): string
    {
        if (! is_string($alamat) || $alamat === '' || preg_match('#^(/[a-z0-9_-]+)*/?$#', $alamat) !== 1) {
            throw new RuntimeException("Pengaturan 'alamat_dasar' tidak sah.");
        }

        return rtrim($alamat, '/');
    }

    /** Alamat lengkap untuk akhiran seperti '/masuk'; akhiran kosong berarti halaman awal. */
    public function rute(string $akhiran = ''): string
    {
        $alamat = $this->alamatDasar . $akhiran;

        return $alamat === '' ? '/' : $alamat;
    }

    public function alamatMasuk(): string
    {
        return $this->rute('/masuk');
    }

    public function alamatKeluar(): string
    {
        return $this->rute('/keluar');
    }

    public function alamatProfil(): string
    {
        return $this->rute('/profil');
    }

    /**
     * Tujuan setelah login hanya boleh alamat di dalam pintu ini, tanpa host lain (mencegah open
     * redirect), dan bukan halaman masuk atau keluar.
     */
    public function tujuanSah(mixed $tujuan): bool
    {
        if (! is_string($tujuan) || $tujuan === '' || strlen($tujuan) > 2000 || $tujuan[0] !== '/') {
            return false;
        }

        if (str_starts_with($tujuan, '//') || str_contains($tujuan, '\\') || preg_match('/[\x00-\x1f\x7f]/', $tujuan) === 1) {
            return false;
        }

        $jalur = (string) parse_url($tujuan, PHP_URL_PATH);
        $dasar = $this->alamatDasar;
        if ($dasar !== '' && $jalur !== $dasar && ! str_starts_with($jalur, $dasar . '/')) {
            return false;
        }

        return ! in_array($jalur, [$this->alamatMasuk(), $this->alamatKeluar()], true);
    }
}
