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
    private readonly string $tEmail;

    /** Email tambahan (2.2) tersedia sesudah perintah pasang; sebelum itu hanya email utama yang dikenal. */
    private readonly EmailUser $email;

    /** Profil (2.3): Tentang, nama depan/tengah/belakang, foto, dan profil per website; tersedia sesudah pasang. */
    private readonly ProfilUser $profil;

    public function __construct(
        private readonly Adapter $db,
        NamaTabel $tabel = new NamaTabel(),
    ) {
        $platform     = $db->getPlatform();
        $this->tUser  = $platform->quoteIdentifier($tabel->user());
        $this->tAkses = $platform->quoteIdentifier($tabel->akses());
        $this->tEmail = $platform->quoteIdentifier($tabel->email());
        $this->email  = new EmailUser($db, $tabel);
        $this->profil = new ProfilUser($db, $tabel);
    }

    /** Mencari akun lewat email utama atau email tambahannya. */
    public function cariEmail(string $email): ?User
    {
        $email = self::normalkanEmail($email);
        $user  = $this->user("SELECT * FROM {$this->tUser} WHERE email = ?", [$email]);
        if ($user !== null || ! $this->email->tersedia()) {
            return $user;
        }

        return $this->user(
            "SELECT u.* FROM {$this->tUser} u JOIN {$this->tEmail} e ON e.user_id = u.id WHERE e.email = ?",
            [$email],
        );
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

        $id = (int) $this->db->getDriver()->getLastGeneratedValue();
        if ($this->email->tersedia()) {
            $this->email->tambah($id, $email);
        }

        return $id;
    }

    public function gantiSandi(int $id, string $kataSandi): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET kata_sandi = ? WHERE id = ?", [self::hash($kataSandi), $id]);
    }

    /**
     * Mengganti email utama dengan email baru, dan melepas email utama yang lama dari akun (email tambahan lain
     * tetap). Pastikan lebih dulu email baru itu belum dimiliki akun lain.
     */
    public function gantiEmail(int $id, string $email): void
    {
        $email = self::normalkanEmail($email);
        if (! $this->email->tersedia()) {
            $this->jalankan("UPDATE {$this->tUser} SET email = ? WHERE id = ?", [$email, $id]);

            return;
        }

        $lama  = $this->cariId($id)?->email;
        $baru  = $this->email->id($id, $email) ?? $this->email->tambah($id, $email);
        $this->email->jadikanUtama($id, $baru);
        $idLama = $lama === null || $lama === $email ? null : $this->email->id($id, $lama);
        if ($idLama !== null) {
            $this->email->hapus($id, $idLama);
        }
    }

    /** Pengelola email tambahan akun. */
    public function email(): EmailUser
    {
        return $this->email;
    }

    public function profil(): ProfilUser
    {
        return $this->profil;
    }

    /**
     * Menyimpan nama yang diisi sendiri oleh user; kolom nama berisi gabungannya (nama lengkap yang dipakai di
     * mana-mana). Hanya setelah pasang 2.3 (lihat ProfilUser::tersedia()).
     */
    public function gantiNamaLengkap(int $id, string $depan, string $tengah, string $belakang): void
    {
        $this->jalankan(
            "UPDATE {$this->tUser} SET nama = ?, nama_depan = ?, nama_tengah = ?, nama_belakang = ? WHERE id = ?",
            [User::gabungNama($depan, $tengah, $belakang), trim($depan), trim($tengah), trim($belakang), $id],
        );
    }

    /** Kode foto akun (FotoUser), kosong berarti tanpa foto. */
    public function aturFoto(int $id, string $kode): void
    {
        $this->jalankan("UPDATE {$this->tUser} SET foto = ? WHERE id = ?", [$kode, $id]);
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

    /**
     * Memberi akses; tanpa peran, user menjadi admin sesuai lingkupnya (admin super atau admin web). Dengan $email,
     * email itu (milik akun ini) menjadi email yang dipakai di lingkup tersebut.
     */
    public function tambahAkses(int $id, string $lingkup, ?string $peran = null, ?string $email = null): void
    {
        if (! $this->punyaAkses($id, $lingkup)) {
            $peran ??= self::peranAdminUntuk($lingkup);
            $this->jalankan("INSERT INTO {$this->tAkses} (user_id, lingkup, peran) VALUES (?, ?, ?)", [$id, $lingkup, $peran]);
        }

        if ($email !== null && $this->email->tersedia()) {
            $emailId = $this->email->id($id, $email);
            if ($emailId !== null && $email !== $this->cariId($id)?->email) {
                $this->email->aturEmailLingkup($id, $lingkup, $emailId);
            }
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
        // Email yang tampil adalah email pilihan user untuk lingkup itu (2.2), atau email utama.
        $sql   = $this->email->tersedia()
            ? "SELECT u.id, COALESCE(e.email, u.email) AS email, u.nama, u.aktif, u.terakhir_masuk, a.lingkup, a.peran"
                . " FROM {$this->tUser} u JOIN {$this->tAkses} a ON a.user_id = u.id"
                . " LEFT JOIN {$this->tEmail} e ON e.id = a.email_id AND e.user_id = u.id WHERE a.lingkup = ?"
            : "SELECT u.id, u.email, u.nama, u.aktif, u.terakhir_masuk, a.lingkup, a.peran FROM {$this->tUser} u"
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
     * Semua akun di database, untuk admin super di dashboard utama: email utama, jumlah email, dan akses ke setiap
     * lingkup. Waktu terakhir_masuk dalam UTC.
     *
     * Setiap akses memuat email yang dipakai di lingkup itu bila berbeda dari email utama (null = email utama).
     *
     * @return list<array{id: int, email: string, nama: string, aktif: bool, terakhir_masuk: ?string, jumlahEmail: int, semuaEmail: list<string>, akses: list<array{lingkup: string, peran: string, email: ?string}>}>
     */
    public function semuaAkun(): array
    {
        $tersedia = $this->email->tersedia();
        $sql      = $tersedia
            ? "SELECT a.user_id, a.lingkup, a.peran, e.email FROM {$this->tAkses} a"
                . " LEFT JOIN {$this->tEmail} e ON e.id = a.email_id AND e.user_id = a.user_id ORDER BY a.lingkup"
            : "SELECT user_id, lingkup, peran, NULL AS email FROM {$this->tAkses} ORDER BY lingkup";
        $akses    = [];
        foreach ($this->baris($sql, []) as $b) {
            $akses[(int) $b['user_id']][] = [
                'lingkup' => (string) $b['lingkup'],
                'peran'   => self::normalkanPeran((string) $b['peran'], (string) $b['lingkup']),
                'email'   => $b['email'] === null ? null : (string) $b['email'],
            ];
        }

        $semuaEmail = [];
        if ($tersedia) {
            foreach ($this->baris("SELECT user_id, email FROM {$this->tEmail} ORDER BY email", []) as $b) {
                $semuaEmail[(int) $b['user_id']][] = (string) $b['email'];
            }
        }

        $hasil = [];
        foreach ($this->baris("SELECT id, email, nama, aktif, terakhir_masuk FROM {$this->tUser} ORDER BY email", []) as $b) {
            $id      = (int) $b['id'];
            $utama   = (string) $b['email'];
            // Email utama lebih dulu, lalu email tambahan urut abjad.
            $email   = array_values(array_unique([$utama, ...($semuaEmail[$id] ?? [])]));
            $hasil[] = [
                'id'             => $id,
                'email'          => $utama,
                'nama'           => (string) $b['nama'],
                'aktif'          => (int) $b['aktif'] === 1,
                'terakhir_masuk' => $b['terakhir_masuk'] === null ? null : (string) $b['terakhir_masuk'],
                'jumlahEmail'    => count($email),
                'semuaEmail'     => $email,
                'akses'          => array_map(
                    static fn (array $x): array => ['email' => $x['email'] === $utama ? null : $x['email']] + $x,
                    $akses[$id] ?? [],
                ),
            ];
        }

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
            // Kolom 2.3; sebelum pasang belum ada.
            (string) ($baris[0]['nama_depan'] ?? ''),
            (string) ($baris[0]['nama_tengah'] ?? ''),
            (string) ($baris[0]['nama_belakang'] ?? ''),
            (string) ($baris[0]['foto'] ?? ''),
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
