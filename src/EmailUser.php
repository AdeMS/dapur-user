<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Email milik akun. Satu akun bisa punya beberapa email, misalnya email pribadi dan email kantor, tetapi tetap satu
 * profil: nama, kata sandi, dan riwayat yang sama.
 *
 * Tabel user_email menyimpan SEMUA email akun yang sudah dikonfirmasi, termasuk email utama. Setiap email unik di
 * seluruh Dapur, jadi satu email tidak bisa dimiliki dua akun. Kolom email di tabel user tetap menyimpan email
 * utama, agar kode yang membaca email user tetap berjalan.
 *
 * Email yang belum dikonfirmasi tidak disimpan di sini, tetapi di token konfirmasi (TokenUser), sehingga email
 * yang hanya diminta tidak menghalangi orang lain.
 *
 * Setiap akses (user_akses) bisa menunjuk satu email lewat email_id: email yang dipakai di lingkup itu, yang
 * dilihat rekan di website tersebut. Kosong berarti email utama.
 */
final class EmailUser
{
    public const PESAN_BELUM_DIPASANG = 'Tabel email user belum dibuat. Jalankan "vendor/bin/dapur-user pasang" dari folder inti Dapur.';

    private readonly string $tEmail;
    private readonly string $tUser;
    private readonly string $tAkses;

    private ?bool $tersedia = null;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $platform     = $db->getPlatform();
        $this->tEmail = $platform->quoteIdentifier($tabel->email());
        $this->tUser  = $platform->quoteIdentifier($tabel->user());
        $this->tAkses = $platform->quoteIdentifier($tabel->akses());
    }

    /** Apakah tabel email dan kolom email_id sudah dibuat oleh perintah pasang. */
    public function tersedia(): bool
    {
        // Hanya hasil "sudah tersedia" yang diingat, karena tabel bisa dibuat sesudah objek ini dibuat.
        if ($this->tersedia !== true) {
            try {
                $this->db->query("SELECT id FROM {$this->tEmail} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);
                $this->db->query("SELECT email_id FROM {$this->tAkses} WHERE 1 = 0", Adapter::QUERY_MODE_EXECUTE);
                $this->tersedia = true;
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Menyalin email utama akun yang belum tercatat ke tabel email. Dijalankan oleh perintah pasang; aman diulang.
     *
     * @return int jumlah email yang disalin
     */
    public function salinEmailUtama(): int
    {
        $hasil = $this->db->query(
            "INSERT INTO {$this->tEmail} (user_id, email, terverifikasi, dibuat_pada)"
            . " SELECT u.id, u.email, u.dibuat_pada, u.dibuat_pada FROM {$this->tUser} u"
            . " WHERE NOT EXISTS (SELECT 1 FROM {$this->tEmail} e WHERE e.email = u.email)",
            [],
        );

        return is_object($hasil) && method_exists($hasil, 'getAffectedRows') ? (int) $hasil->getAffectedRows() : 0;
    }

    /**
     * Email milik satu akun, email utama lebih dulu.
     *
     * @return list<array{id: int, email: string, utama: bool, terverifikasi: string}>
     */
    public function daftar(int $userId): array
    {
        $baris = $this->baris(
            "SELECT e.id, e.email, e.terverifikasi, CASE WHEN e.email = u.email THEN 1 ELSE 0 END AS utama"
            . " FROM {$this->tEmail} e JOIN {$this->tUser} u ON u.id = e.user_id"
            . " WHERE e.user_id = ? ORDER BY utama DESC, e.email",
            [$userId],
        );

        return array_map(static fn (array $b): array => [
            'id'            => (int) $b['id'],
            'email'         => (string) $b['email'],
            'utama'         => (int) $b['utama'] === 1,
            'terverifikasi' => (string) $b['terverifikasi'],
        ], $baris);
    }

    /** Id akun pemilik email, atau null bila email itu belum dimiliki siapa pun. */
    public function pemilik(string $email): ?int
    {
        $baris = $this->baris("SELECT user_id FROM {$this->tEmail} WHERE email = ?", [DaftarUser::normalkanEmail($email)]);

        return $baris === [] ? null : (int) $baris[0]['user_id'];
    }

    /** Id baris email milik akun ini, atau null. */
    public function id(int $userId, string $email): ?int
    {
        $baris = $this->baris(
            "SELECT id FROM {$this->tEmail} WHERE user_id = ? AND email = ?",
            [$userId, DaftarUser::normalkanEmail($email)],
        );

        return $baris === [] ? null : (int) $baris[0]['id'];
    }

    /** Menambahkan email yang sudah dikonfirmasi. Pastikan lebih dulu email itu belum dimiliki akun lain. */
    public function tambah(int $userId, string $email): int
    {
        $sekarang = gmdate('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO {$this->tEmail} (user_id, email, terverifikasi, dibuat_pada) VALUES (?, ?, ?, ?)",
            [$userId, DaftarUser::normalkanEmail($email), $sekarang, $sekarang],
        );

        return (int) $this->db->getDriver()->getLastGeneratedValue();
    }

    /**
     * Menghapus email tambahan. Email utama tidak bisa dihapus. Website yang memakai email itu kembali memakai
     * email utama.
     *
     * @return string|null email yang dihapus, atau null bila tidak ada atau email utama
     */
    public function hapus(int $userId, int $emailId): ?string
    {
        $email = $this->milik($userId, $emailId);
        if ($email === null || $email === $this->emailUtama($userId)) {
            return null;
        }

        $this->db->query("UPDATE {$this->tAkses} SET email_id = NULL WHERE user_id = ? AND email_id = ?", [$userId, $emailId]);
        $this->db->query("DELETE FROM {$this->tEmail} WHERE id = ? AND user_id = ?", [$emailId, $userId]);

        return $email;
    }

    /** @return string|null email baru, atau null bila email itu bukan milik akun ini */
    public function jadikanUtama(int $userId, int $emailId): ?string
    {
        $email = $this->milik($userId, $emailId);
        if ($email !== null) {
            $this->db->query("UPDATE {$this->tUser} SET email = ? WHERE id = ?", [$email, $userId]);
        }

        return $email;
    }

    /** Email yang dipakai user di satu lingkup: email pilihannya untuk lingkup itu, atau email utama. */
    public function emailLingkup(int $userId, string $lingkup): string
    {
        $baris = $this->baris(
            "SELECT e.email FROM {$this->tAkses} a JOIN {$this->tEmail} e ON e.id = a.email_id AND e.user_id = a.user_id"
            . " WHERE a.user_id = ? AND a.lingkup = ?",
            [$userId, $lingkup],
        );

        return $baris === [] ? $this->emailUtama($userId) : (string) $baris[0]['email'];
    }

    /** Id email pilihan user untuk satu lingkup, atau null (email utama). */
    public function emailIdLingkup(int $userId, string $lingkup): ?int
    {
        $baris = $this->baris("SELECT email_id FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$userId, $lingkup]);

        return $baris === [] || $baris[0]['email_id'] === null ? null : (int) $baris[0]['email_id'];
    }

    /**
     * Memilih email yang dipakai di satu lingkup; null kembali ke email utama. Hanya berlaku bila user punya akses
     * tersendiri ke lingkup itu.
     *
     * @return bool false bila email itu bukan milik akun ini
     */
    public function aturEmailLingkup(int $userId, string $lingkup, ?int $emailId): bool
    {
        if ($emailId !== null && $this->milik($userId, $emailId) === null) {
            return false;
        }

        $this->db->query("UPDATE {$this->tAkses} SET email_id = ? WHERE user_id = ? AND lingkup = ?", [$emailId, $userId, $lingkup]);

        return true;
    }

    /** Email baris ini bila milik akun tersebut. */
    public function milik(int $userId, int $emailId): ?string
    {
        $baris = $this->baris("SELECT email FROM {$this->tEmail} WHERE id = ? AND user_id = ?", [$emailId, $userId]);

        return $baris === [] ? null : (string) $baris[0]['email'];
    }

    private function emailUtama(int $userId): string
    {
        $baris = $this->baris("SELECT email FROM {$this->tUser} WHERE id = ?", [$userId]);

        return $baris === [] ? '' : (string) $baris[0]['email'];
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    private function baris(string $sql, array $parameter): array
    {
        $hasil = $this->db->query($sql, $parameter);

        return $hasil instanceof ResultSetInterface ? array_values($hasil->toArray()) : [];
    }
}
