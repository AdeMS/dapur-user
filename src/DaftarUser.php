<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Akses data user dan hak aksesnya per lingkup.
 *
 * Peran:
 * - admin super: akses ke lingkup utama ('dashboard'), dan otomatis admin di SEMUA website tanpa perlu diberi
 *   akses satu per satu;
 * - admin web: admin di website yang aksesnya diberikan;
 * - anggota: bisa login dan memakai halaman di lingkupnya, tetapi tidak bisa mengelola user.
 *
 * Versi 1.x memakai satu peran 'admin'. Nilai lama itu tetap dibaca sebagai admin super (di lingkup utama) atau
 * admin web (di website), sampai perintah pasang mengubahnya di database.
 */
final class DaftarUser
{
    public const PERAN_ADMIN_SUPER = 'admin_super';
    public const PERAN_ADMIN_WEB   = 'admin_web';
    public const PERAN_ANGGOTA     = 'anggota';

    /** Peran admin dari versi 1.x, diubah oleh perintah pasang. */
    public const PERAN_LAMA_ADMIN = 'admin';

    /** Peran yang bisa dipilih di lingkup utama (dashboard utama). */
    public const PERAN_UTAMA = [self::PERAN_ADMIN_SUPER, self::PERAN_ANGGOTA];

    /** Peran yang bisa dipilih di lingkup website. */
    public const PERAN_WEBSITE = [self::PERAN_ADMIN_WEB, self::PERAN_ANGGOTA];

    /** Label peran untuk tampilan. */
    private const LABEL = [
        self::PERAN_ADMIN_SUPER => 'Admin Super',
        self::PERAN_ADMIN_WEB   => 'Admin Web',
        self::PERAN_ANGGOTA     => 'Anggota',
    ];

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

    /** Mengganti email login. Pastikan lebih dulu email itu belum dipakai akun lain. */
    public function gantiEmail(int $id, string $email): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET email = ? WHERE id = ?", [self::normalkanEmail($email), $id]);
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

    /** Apakah user ini admin (super atau web) di salah satu lingkup; menentukan syarat kata sandinya. */
    public function adalahAdmin(int $id): bool
    {
        $hasil = $this->jalankan(
            "SELECT 1 FROM {$this->tAkses} WHERE user_id = ? AND peran IN (?, ?, ?)",
            [$id, self::PERAN_ADMIN_SUPER, self::PERAN_ADMIN_WEB, self::PERAN_LAMA_ADMIN],
        );

        return $hasil instanceof ResultSetInterface && $hasil->count() > 0;
    }

    public function adalahAdminSuper(int $id): bool
    {
        return $this->peranTersimpan($id, Konteks::LINGKUP_UTAMA) === self::PERAN_ADMIN_SUPER;
    }

    /** Memberi akses; tanpa peran, user menjadi admin sesuai lingkupnya (admin super atau admin web). */
    public function tambahAkses(int $id, string $lingkup, ?string $peran = null): void
    {
        if (! $this->punyaAkses($id, $lingkup)) {
            $peran ??= self::peranAdminUntuk($lingkup);
            $this->jalankan("INSERT INTO {$this->tAkses} (user_id, lingkup, peran) VALUES (?, ?, ?)", [$id, $lingkup, $peran]);
        }
    }

    public function ubahPeran(int $id, string $lingkup, string $peran): void
    {
        $this->jalankan("UPDATE {$this->tAkses} SET peran = ? WHERE user_id = ? AND lingkup = ?", [$peran, $id, $lingkup]);
    }

    /** Mengaktifkan atau menonaktifkan akun. Akun nonaktif tidak bisa login di lingkup mana pun. */
    public function aturAktif(int $id, bool $aktif): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET aktif = ? WHERE id = ?", [$aktif ? 1 : 0, $id]);
    }

    /** Mencabut akses user dari satu lingkup. Akun user sendiri tidak dihapus. */
    public function cabutAkses(int $id, string $lingkup): void
    {
        $this->jalankan("DELETE FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$id, $lingkup]);
    }

    /**
     * Peran user di satu lingkup, atau null bila tidak punya akses. Admin super selalu admin super di lingkup
     * mana pun, walaupun tidak punya akses tersendiri ke website itu.
     */
    public function peran(int $id, string $lingkup): ?string
    {
        if ($lingkup !== Konteks::LINGKUP_UTAMA && $this->adalahAdminSuper($id)) {
            return self::PERAN_ADMIN_SUPER;
        }

        return $this->peranTersimpan($id, $lingkup);
    }

    /** Peran yang tersimpan untuk satu lingkup saja (tanpa admin super otomatis), dengan nilai lama dibaca baru. */
    public function peranTersimpan(int $id, string $lingkup): ?string
    {
        $baris = $this->baris("SELECT peran FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$id, $lingkup]);

        return $baris === [] ? null : self::normalkanPeran((string) $baris[0]['peran'], $lingkup);
    }

    /** Jumlah admin super; dipakai agar admin super terakhir tidak tercabut. */
    public function jumlahAdminSuper(): int
    {
        $baris = $this->baris(
            "SELECT COUNT(*) AS jumlah FROM {$this->tAkses} WHERE lingkup = ? AND peran IN (?, ?)",
            [Konteks::LINGKUP_UTAMA, self::PERAN_ADMIN_SUPER, self::PERAN_LAMA_ADMIN],
        );

        return (int) ($baris[0]['jumlah'] ?? 0);
    }

    /**
     * Mengubah peran 'admin' dari versi 1.x: di lingkup utama menjadi admin super, di website menjadi admin web.
     * Dijalankan oleh perintah pasang; aman diulang.
     *
     * @return int jumlah akses yang diubah
     */
    public function perbaruiPeranLama(): int
    {
        $jumlah = 0;
        foreach ([
            "UPDATE {$this->tAkses} SET peran = ? WHERE peran = ? AND lingkup = ?" => self::PERAN_ADMIN_SUPER,
            "UPDATE {$this->tAkses} SET peran = ? WHERE peran = ? AND lingkup <> ?" => self::PERAN_ADMIN_WEB,
        ] as $sql => $baru) {
            $hasil = $this->db->query($sql, [$baru, self::PERAN_LAMA_ADMIN, Konteks::LINGKUP_UTAMA]);
            $jumlah += is_object($hasil) && method_exists($hasil, 'getAffectedRows') ? (int) $hasil->getAffectedRows() : 0;
        }

        return $jumlah;
    }

    /** Nilai peran 1.x dibaca sebagai peran baru menurut lingkupnya. */
    public static function normalkanPeran(string $peran, string $lingkup): string
    {
        if ($peran !== self::PERAN_LAMA_ADMIN) {
            return $peran;
        }

        return $lingkup === Konteks::LINGKUP_UTAMA ? self::PERAN_ADMIN_SUPER : self::PERAN_ADMIN_WEB;
    }

    /** Apakah peran ini peran admin (super atau web), termasuk nilai lama 'admin'. */
    public static function peranAdmin(?string $peran): bool
    {
        return in_array($peran, [self::PERAN_ADMIN_SUPER, self::PERAN_ADMIN_WEB, self::PERAN_LAMA_ADMIN], true);
    }

    /** Peran admin yang dipakai untuk lingkup ini: admin super di lingkup utama, admin web di website. */
    public static function peranAdminUntuk(string $lingkup): string
    {
        return $lingkup === Konteks::LINGKUP_UTAMA ? self::PERAN_ADMIN_SUPER : self::PERAN_ADMIN_WEB;
    }

    /** @return list<string> peran yang bisa dipilih di lingkup ini */
    public static function pilihanPeran(string $lingkup): array
    {
        return $lingkup === Konteks::LINGKUP_UTAMA ? self::PERAN_UTAMA : self::PERAN_WEBSITE;
    }

    public static function label(string $peran): string
    {
        return self::LABEL[$peran] ?? $peran;
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
            $hasil[] = ['lingkup' => (string) $b['lingkup'], 'peran' => self::normalkanPeran((string) $b['peran'], (string) $b['lingkup'])];
        }

        return $hasil;
    }

    /**
     * Semua user yang bisa masuk ke satu lingkup, urut email. Waktu terakhir_masuk dalam UTC.
     *
     * Di lingkup website, admin super ikut terdaftar dengan 'otomatis' => true bila tidak punya akses tersendiri
     * ke website itu; perannya selalu admin super.
     *
     * @return list<array{id: int, email: string, nama: string, aktif: bool, peran: string, terakhir_masuk: ?string, otomatis: bool}>
     */
    public function userDiLingkup(string $lingkup): array
    {
        $hasil = [];
        $sql   = "SELECT u.id, u.email, u.nama, u.aktif, u.terakhir_masuk, a.lingkup, a.peran FROM {$this->tUser} u"
            . " JOIN {$this->tAkses} a ON a.user_id = u.id WHERE a.lingkup = ?";
        foreach ($this->baris($sql, [$lingkup]) as $b) {
            $hasil[(int) $b['id']] = self::barisUser($b, false);
        }

        if ($lingkup !== Konteks::LINGKUP_UTAMA) {
            foreach ($this->baris($sql, [Konteks::LINGKUP_UTAMA]) as $b) {
                $b['peran'] = self::normalkanPeran((string) $b['peran'], Konteks::LINGKUP_UTAMA);
                if ($b['peran'] === self::PERAN_ADMIN_SUPER) {
                    $id         = (int) $b['id'];
                    $hasil[$id] = self::barisUser($b, ! isset($hasil[$id]));
                }
            }
        }

        usort($hasil, static fn (array $a, array $b): int => strcmp($a['email'], $b['email']));

        return $hasil;
    }

    /**
     * @param array<string, mixed> $b
     * @return array{id: int, email: string, nama: string, aktif: bool, peran: string, terakhir_masuk: ?string, otomatis: bool}
     */
    private static function barisUser(array $b, bool $otomatis): array
    {
        return [
            'id'             => (int) $b['id'],
            'email'          => (string) $b['email'],
            'nama'           => (string) $b['nama'],
            'aktif'          => (int) $b['aktif'] === 1,
            'peran'          => self::normalkanPeran((string) $b['peran'], (string) $b['lingkup']),
            'terakhir_masuk' => $b['terakhir_masuk'] === null ? null : (string) $b['terakhir_masuk'],
            'otomatis'       => $otomatis,
        ];
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
