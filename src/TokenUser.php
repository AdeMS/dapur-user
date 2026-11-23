<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Token sekali pakai yang dikirim lewat email: undangan, lupa kata sandi, dan verifikasi email baru.
 *
 * Token asli hanya ada di tautan email; database hanya menyimpan hash SHA-256-nya, jadi orang yang bisa membaca
 * database tidak bisa memakai token itu. Token punya batas waktu, langsung tidak berlaku setelah dipakai, dan token
 * baru dengan jenis yang sama membatalkan token lama milik user itu.
 */
final class TokenUser
{
    public const UNDANGAN    = 'undangan';
    public const LUPA_SANDI  = 'lupa_sandi';
    public const GANTI_EMAIL = 'ganti_email';

    /** Konfirmasi email tambahan untuk akun (2.2); datanya email yang ditambahkan. */
    public const TAMBAH_EMAIL = 'tambah_email';

    /**
     * Konfirmasi penggabungan akun (2.4), dikirim ke email akun yang akan ditutup. Token milik akun yang dipertahankan;
     * datanya "<id akun yang ditutup>|<email itu>".
     */
    public const GABUNG_AKUN = 'gabung_akun';

    /** Masa berlaku token, dalam detik. */
    public const MASA_BERLAKU = [
        self::UNDANGAN    => 48 * 3600,
        self::LUPA_SANDI  => 3600,
        self::GANTI_EMAIL => 24 * 3600,
        self::TAMBAH_EMAIL => 24 * 3600,
        self::GABUNG_AKUN  => 24 * 3600,
    ];

    public const PESAN_BELUM_DIPASANG = 'Tabel token belum dibuat. Jalankan "vendor/bin/dapur-user pasang" dari folder inti Dapur.';

    private readonly string $tabel;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $this->tabel = $db->getPlatform()->quoteIdentifier($tabel->token());
    }

    /**
     * Membuat token baru dan membatalkan token lama dengan jenis yang sama milik user ini.
     *
     * @param string $data keterangan tambahan, misalnya email baru untuk jenis ganti_email
     * @return string token asli untuk dimasukkan ke tautan (hanya dikembalikan sekali)
     */
    public function buat(int $userId, string $jenis, string $data = ''): string
    {
        $token    = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $sekarang = time();

        $this->db->query(
            "UPDATE {$this->tabel} SET dipakai = ? WHERE user_id = ? AND jenis = ? AND dipakai IS NULL",
            [gmdate('Y-m-d H:i:s', $sekarang), $userId, $jenis],
        );
        $this->db->query("DELETE FROM {$this->tabel} WHERE kedaluwarsa < ?", [gmdate('Y-m-d H:i:s', $sekarang - 7 * 86400)]);
        $this->db->query(
            "INSERT INTO {$this->tabel} (user_id, jenis, hash, data, kedaluwarsa, dipakai, dibuat_pada) VALUES (?, ?, ?, ?, ?, NULL, ?)",
            [
                $userId,
                $jenis,
                self::hash($token),
                substr($data, 0, 255),
                gmdate('Y-m-d H:i:s', $sekarang + (self::MASA_BERLAKU[$jenis] ?? 3600)),
                gmdate('Y-m-d H:i:s', $sekarang),
            ],
        );

        return $token;
    }

    /**
     * Token yang masih berlaku untuk salah satu jenis yang diizinkan, atau null.
     *
     * @param list<string> $jenis
     * @return array{id: int, user_id: int, jenis: string, data: string}|null
     */
    public function periksa(string $token, array $jenis): ?array
    {
        if ($token === '' || strlen($token) > 100 || $jenis === []) {
            return null;
        }

        $tanda = implode(', ', array_fill(0, count($jenis), '?'));
        $hasil = $this->db->query(
            "SELECT id, user_id, jenis, data FROM {$this->tabel}"
            . " WHERE hash = ? AND jenis IN ({$tanda}) AND dipakai IS NULL AND kedaluwarsa >= ?",
            [self::hash($token), ...$jenis, gmdate('Y-m-d H:i:s')],
        );
        $baris = $hasil instanceof ResultSetInterface ? array_values($hasil->toArray()) : [];
        if ($baris === []) {
            return null;
        }

        return [
            'id'      => (int) $baris[0]['id'],
            'user_id' => (int) $baris[0]['user_id'],
            'jenis'   => (string) $baris[0]['jenis'],
            'data'    => (string) $baris[0]['data'],
        ];
    }

    /** Menandai token sudah dipakai, sehingga tautan yang sama tidak bisa dipakai lagi. */
    public function pakai(int $id): void
    {
        $this->db->query("UPDATE {$this->tabel} SET dipakai = ? WHERE id = ? AND dipakai IS NULL", [gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Apakah tabel token sudah ada (setelah memperbarui dari 2.0, baru ada sesudah perintah pasang). */
    public function tersedia(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM {$this->tabel} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
