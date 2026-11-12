<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/** Akses data user dan hak aksesnya per lingkup. */
final class DaftarUser
{
    public const PERAN_ADMIN = 'admin';

    /** Nama tabel yang sudah diberi tanda kutip sesuai database. */
    private readonly string $tUser;
    private readonly string $tAkses;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $platform     = $db->getPlatform();
        $this->tUser  = $platform->quoteIdentifier($tabel->user());
        $this->tAkses = $platform->quoteIdentifier($tabel->akses());
    }

    public function cariEmail(string $email): ?User
    {
        return $this->user("SELECT * FROM {$this->tUser} WHERE email = ?", [self::normalkanEmail($email)]);
    }

    public function cariId(int $id): ?User
    {
        return $this->user("SELECT * FROM {$this->tUser} WHERE id = ?", [$id]);
    }

    public function buat(string $email, string $nama, string $kataSandi): int
    {
        $this->jalankan(
            "INSERT INTO {$this->tUser} (email, nama, kata_sandi, aktif, dibuat_pada) VALUES (?, ?, ?, 1, ?)",
            [self::normalkanEmail($email), $nama, self::hash($kataSandi), gmdate('Y-m-d H:i:s')],
        );

        return (int) $this->db->getDriver()->getLastGeneratedValue();
    }

    public function gantiSandi(int $id, string $kataSandi): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET kata_sandi = ? WHERE id = ?", [self::hash($kataSandi), $id]);
    }

    public function gantiNama(int $id, string $nama): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET nama = ? WHERE id = ?", [$nama, $id]);
    }

    public function catatMasuk(int $id): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET terakhir_masuk = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $id]);
    }

    public function punyaAkses(int $id, string $lingkup): bool
    {
        $hasil = $this->jalankan("SELECT 1 FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$id, $lingkup]);

        return $hasil instanceof ResultSetInterface && $hasil->count() > 0;
    }

    /** Apakah user ini admin di salah satu lingkup; menentukan syarat kata sandinya. */
    public function adalahAdmin(int $id): bool
    {
        $hasil = $this->jalankan("SELECT 1 FROM {$this->tAkses} WHERE user_id = ? AND peran = ?", [$id, self::PERAN_ADMIN]);

        return $hasil instanceof ResultSetInterface && $hasil->count() > 0;
    }

    public function tambahAkses(int $id, string $lingkup, string $peran = self::PERAN_ADMIN): void
    {
        if (! $this->punyaAkses($id, $lingkup)) {
            $this->jalankan("INSERT INTO {$this->tAkses} (user_id, lingkup, peran) VALUES (?, ?, ?)", [$id, $lingkup, $peran]);
        }
    }

    /** Mencabut akses user dari satu lingkup. Akun user sendiri tidak dihapus. */
    public function cabutAkses(int $id, string $lingkup): void
    {
        $this->jalankan("DELETE FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$id, $lingkup]);
    }

    /** Peran user di satu lingkup, atau null bila tidak punya akses. */
    public function peran(int $id, string $lingkup): ?string
    {
        $baris = $this->baris("SELECT peran FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$id, $lingkup]);

        return $baris === [] ? null : (string) $baris[0]['peran'];
    }

    /** Jumlah admin di satu lingkup; dipakai agar admin terakhir tidak tercabut. */
    public function jumlahAdmin(string $lingkup): int
    {
        $baris = $this->baris(
            "SELECT COUNT(*) AS jumlah FROM {$this->tAkses} WHERE lingkup = ? AND peran = ?",
            [$lingkup, self::PERAN_ADMIN],
        );

        return (int) ($baris[0]['jumlah'] ?? 0);
    }

    /**
     * Semua lingkup yang bisa dimasuki satu user, urut nama lingkup.
     *
     * @return list<array{lingkup: string, peran: string}>
     */
    public function aksesUser(int $id): array
    {
        $hasil = [];
        foreach ($this->baris("SELECT lingkup, peran FROM {$this->tAkses} WHERE user_id = ? ORDER BY lingkup", [$id]) as $b) {
            $hasil[] = ['lingkup' => (string) $b['lingkup'], 'peran' => (string) $b['peran']];
        }

        return $hasil;
    }

    /**
     * Semua user yang punya akses ke satu lingkup, urut email. Waktu terakhir_masuk dalam UTC.
     *
     * @return list<array{id: int, email: string, nama: string, aktif: bool, peran: string, terakhir_masuk: ?string}>
     */
    public function userDiLingkup(string $lingkup): array
    {
        $hasil = [];
        $sql   = "SELECT u.id, u.email, u.nama, u.aktif, u.terakhir_masuk, a.peran FROM {$this->tUser} u"
            . " JOIN {$this->tAkses} a ON a.user_id = u.id WHERE a.lingkup = ? ORDER BY u.email";
        foreach ($this->baris($sql, [$lingkup]) as $b) {
            $hasil[] = [
                'id'             => (int) $b['id'],
                'email'          => (string) $b['email'],
                'nama'           => (string) $b['nama'],
                'aktif'          => (int) $b['aktif'] === 1,
                'peran'          => (string) $b['peran'],
                'terakhir_masuk' => $b['terakhir_masuk'] === null ? null : (string) $b['terakhir_masuk'],
            ];
        }

        return $hasil;
    }

    public static function normalkanEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function hash(string $kataSandi): string
    {
        return password_hash($kataSandi, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function perluHashBaru(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** @param list<mixed> $parameter */
    private function user(string $sql, array $parameter): ?User
    {
        $baris = $this->baris($sql, $parameter);
        if ($baris === []) {
            return null;
        }

        return new User(
            (int) $baris[0]['id'],
            (string) $baris[0]['email'],
            (string) $baris[0]['nama'],
            (string) $baris[0]['kata_sandi'],
            (int) $baris[0]['aktif'] === 1,
        );
    }

    /**
     * @param list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    private function baris(string $sql, array $parameter): array
    {
        $hasil = $this->jalankan($sql, $parameter);

        return $hasil instanceof ResultSetInterface ? array_values($hasil->toArray()) : [];
    }

    /** @param list<mixed> $parameter */
    private function jalankan(string $sql, array $parameter): mixed
    {
        return $this->db->query($sql, $parameter);
    }
}
