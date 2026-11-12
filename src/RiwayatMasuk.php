<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Riwayat login: masuk berhasil, gagal, dan keluar, beserta IP dan perangkat (user agent browser).
 *
 * Berbeda dari PembatasPercobaan yang isinya sengaja dihapus setelah login berhasil, tabel ini menjadi
 * catatan untuk dibaca user dan admin. Login gagal dengan email yang tidak terdaftar dicatat tanpa user,
 * dan email yang diketik tidak pernah disimpan. Percobaan yang ditolak karena terlalu sering tidak
 * dicatat, agar tabel ini tidak bisa dibanjiri.
 */
final class RiwayatMasuk
{
    public const MASUK  = 'masuk';
    public const GAGAL  = 'gagal';
    public const KELUAR = 'keluar';

    private const PANJANG_PERANGKAT = 255;

    /** Nama tabel yang sudah diberi tanda kutip sesuai database. */
    private readonly string $tabel;

    /** @param int $simpanHari catatan yang lebih tua dari ini dihapus saat login berhasil; 0 = tidak dihapus */
    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
        private readonly int $simpanHari = Pengaturan::RIWAYAT_HARI,
    ) {
        $this->tabel = $db->getPlatform()->quoteIdentifier($tabel->riwayat());
    }

    public function catat(string $peristiwa, ?int $userId, string $lingkup, string $ip, string $perangkat): void
    {
        $this->db->query(
            "INSERT INTO {$this->tabel} (user_id, lingkup, peristiwa, ip, perangkat, waktu) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $lingkup, $peristiwa, substr($ip, 0, 45), self::potong($perangkat), gmdate('Y-m-d H:i:s')],
        );

        if ($peristiwa === self::MASUK && $this->simpanHari > 0) {
            $this->db->query(
                "DELETE FROM {$this->tabel} WHERE waktu < ?",
                [gmdate('Y-m-d H:i:s', time() - $this->simpanHari * 86400)],
            );
        }
    }

    /**
     * Catatan terbaru milik satu user, yang paling baru lebih dulu. Waktu dalam UTC.
     *
     * @return list<array{peristiwa: string, lingkup: string, ip: string, perangkat: string, waktu: string}>
     */
    public function untukUser(int $userId, int $jumlah = 20): array
    {
        $jumlah = max(1, min(500, $jumlah));
        $hasil  = $this->db->query(
            "SELECT peristiwa, lingkup, ip, perangkat, waktu FROM {$this->tabel} WHERE user_id = ? ORDER BY waktu DESC, id DESC LIMIT {$jumlah}",
            [$userId],
        );

        $baris = [];
        foreach ($hasil instanceof ResultSetInterface ? $hasil->toArray() : [] as $b) {
            $baris[] = [
                'peristiwa' => (string) $b['peristiwa'],
                'lingkup'   => (string) $b['lingkup'],
                'ip'        => (string) $b['ip'],
                'perangkat' => (string) $b['perangkat'],
                'waktu'     => (string) $b['waktu'],
            ];
        }

        return $baris;
    }

    /** User agent bisa sangat panjang atau berisi byte aneh; disimpan sebagai teks UTF-8 yang dipotong. */
    private static function potong(string $teks): string
    {
        $teks = preg_replace('/[\x00-\x1f\x7f]/', '', $teks) ?? '';
        if (function_exists('mb_strcut')) {
            return mb_strcut(mb_scrub($teks, 'UTF-8'), 0, self::PANJANG_PERANGKAT, 'UTF-8');
        }

        return substr($teks, 0, self::PANJANG_PERANGKAT);
    }
}
