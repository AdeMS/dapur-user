<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Pengaturan kecil milik masing-masing user, disimpan sebagai pasangan kunci dan nilai teks (paling panjang
 * 255 byte), misalnya waktu terakhir user membuka halaman Pesan Error di dashboard.
 *
 * Kunci sebaiknya diawali nama modul, misalnya 'dashboard.error_dibaca.contoh.com', agar tidak bertabrakan.
 */
final class PengaturanUser
{
    private const PANJANG_KUNCI = 190;
    private const PANJANG_NILAI = 255;

    private readonly string $tabel;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $this->tabel = $db->getPlatform()->quoteIdentifier($tabel->pengaturan());
    }

    public function ambil(int $userId, string $kunci): ?string
    {
        $hasil = $this->db->query("SELECT nilai FROM {$this->tabel} WHERE user_id = ? AND kunci = ?", [$userId, self::kunci($kunci)]);
        $baris = $hasil instanceof ResultSetInterface ? array_values($hasil->toArray()) : [];

        return $baris === [] ? null : (string) $baris[0]['nilai'];
    }

    public function simpan(int $userId, string $kunci, string $nilai): void
    {
        $kunci = self::kunci($kunci);
        $nilai = substr($nilai, 0, self::PANJANG_NILAI);

        // Hapus lalu tulis ulang: berjalan sama di MySQL/MariaDB dan SQLite.
        $this->db->getDriver()->getConnection()->beginTransaction();
        try {
            $this->db->query("DELETE FROM {$this->tabel} WHERE user_id = ? AND kunci = ?", [$userId, $kunci]);
            $this->db->query("INSERT INTO {$this->tabel} (user_id, kunci, nilai) VALUES (?, ?, ?)", [$userId, $kunci, $nilai]);
            $this->db->getDriver()->getConnection()->commit();
        } catch (\Throwable $e) {
            $this->db->getDriver()->getConnection()->rollback();

            throw $e;
        }
    }

    public function hapus(int $userId, string $kunci): void
    {
        $this->db->query("DELETE FROM {$this->tabel} WHERE user_id = ? AND kunci = ?", [$userId, self::kunci($kunci)]);
    }

    /** Apakah tabel pengaturan sudah ada (setelah memperbarui dari 1.x, baru ada sesudah perintah pasang). */
    public function tersedia(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM {$this->tabel} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function kunci(string $kunci): string
    {
        return substr($kunci, 0, self::PANJANG_KUNCI);
    }
}
