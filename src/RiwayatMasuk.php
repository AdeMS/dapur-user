<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Riwayat login: masuk berhasil, gagal, keluar, dan ganti kata sandi, beserta IP dan perangkat (user agent
 * browser).
 *
 * Berbeda dari PembatasPercobaan yang isinya sengaja dihapus setelah login berhasil, tabel ini menjadi
 * catatan untuk dibaca user dan admin. Login gagal dengan email yang tidak terdaftar dicatat tanpa user,
 * dan email yang diketik tidak pernah disimpan. Percobaan yang ditolak karena terlalu sering tidak
 * dicatat, agar tabel ini tidak bisa dibanjiri.
 */
final class RiwayatMasuk
{
    public const MASUK       = 'masuk';
    public const GAGAL       = 'gagal';
    public const KELUAR      = 'keluar';
    public const GANTI_SANDI = 'ganti_sandi';

    /** Email dan kata sandi benar, tetapi website ini hanya menerima email domain tertentu (2.2). */
    public const DITOLAK_DOMAIN = 'ditolak_domain';

    /** Label peristiwa untuk tampilan. */
    private const LABEL = [
        self::MASUK       => 'Masuk',
        self::GAGAL       => 'Gagal',
        self::KELUAR      => 'Keluar',
        self::GANTI_SANDI => 'Ganti kata sandi',
        self::DITOLAK_DOMAIN => 'Ditolak (domain)',
    ];

    private const PANJANG_PERANGKAT = 255;

    /** Nama tabel yang sudah diberi tanda kutip sesuai database. */
    private readonly string $tabel;

    /** Kolom email (2.2) ada sesudah perintah pasang; sebelum itu email tidak dicatat. */
    private ?bool $adaEmail = null;

    /** @param int $simpanHari catatan yang lebih tua dari ini dihapus saat login berhasil; 0 = tidak dihapus */
    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
        private readonly int $simpanHari = Pengaturan::RIWAYAT_HARI,
    ) {
        $this->tabel = $db->getPlatform()->quoteIdentifier($tabel->riwayat());
    }

    /**
     * @param string $email email yang dipakai untuk login, bila milik akun ini (akun bisa punya beberapa email).
     *                      Email yang tidak terdaftar tidak dicatat, agar riwayat tidak menimbun alamat orang lain.
     */
    public function catat(string $peristiwa, ?int $userId, string $lingkup, string $ip, string $perangkat, string $email = ''): void
    {
        $nilai = [$userId, $lingkup, $peristiwa, substr($ip, 0, 45), self::potong($perangkat), gmdate('Y-m-d H:i:s')];
        if ($this->adaEmail()) {
            $this->db->query(
                "INSERT INTO {$this->tabel} (user_id, lingkup, peristiwa, ip, perangkat, waktu, email) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [...$nilai, $userId === null ? '' : substr(DaftarUser::normalkanEmail($email), 0, 190)],
            );
        } else {
            $this->db->query(
                "INSERT INTO {$this->tabel} (user_id, lingkup, peristiwa, ip, perangkat, waktu) VALUES (?, ?, ?, ?, ?, ?)",
                $nilai,
            );
        }

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
     * @return list<array{peristiwa: string, lingkup: string, ip: string, perangkat: string, waktu: string, email: string}>
     */
    public function untukUser(int $userId, int $jumlah = 20): array
    {
        $jumlah = max(1, min(500, $jumlah));
        $email  = $this->adaEmail() ? ', email' : '';
        $hasil  = $this->db->query(
            "SELECT peristiwa, lingkup, ip, perangkat, waktu{$email} FROM {$this->tabel} WHERE user_id = ? ORDER BY waktu DESC, id DESC LIMIT {$jumlah}",
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
                'email'     => (string) ($b['email'] ?? ''),
            ];
        }

        return $baris;
    }

    private function adaEmail(): bool
    {
        if ($this->adaEmail !== true) {
            try {
                $this->db->query("SELECT email FROM {$this->tabel} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);
                $this->adaEmail = true;
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    public static function label(string $peristiwa): string
    {
        return self::LABEL[$peristiwa] ?? $peristiwa;
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
