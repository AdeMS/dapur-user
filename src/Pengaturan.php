<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use DateTimeZone;
use RuntimeException;
use Throwable;

/** Isi $config['user'] yang sudah diperiksa. */
final class Pengaturan
{
    /** Lama riwayat login disimpan bila tidak diatur. */
    public const RIWAYAT_HARI = 180;

    public readonly NamaTabel $tabel;

    /** Riwayat login yang lebih tua dari sekian hari dihapus otomatis; 0 berarti tidak pernah dihapus. */
    public readonly int $riwayatHari;

    /** Zona waktu untuk menampilkan waktu; database tetap menyimpan UTC. Bawaan: zona waktu PHP. */
    public readonly DateTimeZone $zonaWaktu;

    /**
     * Alamat website untuk tautan di email (undangan, lupa kata sandi), misalnya 'https://contoh.com'. Bila kosong,
     * alamat diambil dari request yang sedang berjalan.
     */
    public readonly ?string $alamatWebsite;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->tabel = NamaTabel::dariPengaturan($config);

        $hari = $config['riwayat_hari'] ?? self::RIWAYAT_HARI;
        if (! is_int($hari) || $hari < 0) {
            throw new RuntimeException("Pengaturan 'riwayat_hari' user harus bilangan bulat 0 atau lebih.");
        }

        $this->riwayatHari = $hari;
        $this->zonaWaktu   = self::zona($config['zona_waktu'] ?? null);

        $alamat = $config['alamat_website'] ?? null;
        if ($alamat !== null && (! is_string($alamat) || preg_match('#^https?://[a-z0-9.-]+(:\d+)?/?$#i', $alamat) !== 1)) {
            throw new RuntimeException("Pengaturan 'alamat_website' user tidak sah; contoh: 'https://contoh.com'.");
        }

        $this->alamatWebsite = $alamat === null ? null : rtrim($alamat, '/');
    }

    private static function zona(mixed $nama): DateTimeZone
    {
        if ($nama === null) {
            return new DateTimeZone(date_default_timezone_get());
        }

        try {
            if (! is_string($nama) || $nama === '') {
                throw new RuntimeException();
            }

            return new DateTimeZone($nama);
        } catch (Throwable) {
            throw new RuntimeException("Pengaturan 'zona_waktu' user tidak sah; contoh: 'Asia/Jakarta'.");
        }
    }
}
