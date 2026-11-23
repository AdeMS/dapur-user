<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSetInterface;

/**
 * Menggabungkan dua akun milik orang yang sama: akun B (yang ditutup) digabung ke akun A (yang dipertahankan).
 *
 * Misalnya seseorang didaftarkan di website kantor dengan email kantor dan di website lain dengan email pribadi, sehingga
 * punya dua akun. Setelah digabung:
 * - semua email akun B menjadi email tambahan akun A, jadi tetap bisa dipakai untuk masuk;
 * - akses B ke setiap website pindah ke A. Bila keduanya punya akses ke website yang sama, yang dipakai adalah PERAN
 *   YANG LEBIH RENDAH (demi keamanan); peran bisa dinaikkan lagi oleh admin website itu;
 * - riwayat masuk dan riwayat akun B pindah ke A;
 * - nama, kata sandi, foto akun, dan isian Tentang milik A tetap. Isian Tentang A yang masih kosong diisi dari B;
 *   profil khusus website (nama dan foto) milik B dipakai hanya untuk website yang belum punya profil khusus dari A;
 * - akun B dihapus, sehingga semua session akun B tidak berlaku lagi.
 *
 * Penggabungan tidak bisa dibatalkan. Semua perubahan database dijalankan dalam satu transaksi.
 *
 * Kelas ini tidak memeriksa siapa yang meminta: halaman Pengaturan & Privasi memastikan pemohon membuktikan memiliki
 * kedua akun dan bahwa tidak ada admin super yang terlibat; perintah terminal dipakai admin server.
 */
final class GabungAkun
{
    /** Urutan peran dari yang paling rendah; dipakai bila kedua akun punya akses ke lingkup yang sama. */
    private const URUTAN_PERAN = [
        DaftarUser::PERAN_ANGGOTA     => 1,
        DaftarUser::PERAN_ADMIN_WEB   => 2,
        DaftarUser::PERAN_ADMIN_SUPER => 3,
    ];

    private readonly string $tUser;
    private readonly string $tAkses;
    private readonly string $tEmail;
    private readonly string $tRiwayat;
    private readonly string $tRiwayatAkun;
    private readonly string $tToken;
    private readonly string $tPengaturan;
    private readonly string $tProfil;

    public function __construct(
        private readonly Adapter $db,
        private readonly DaftarUser $daftar,
        NamaTabel $tabel = new NamaTabel(),
        private readonly ?FotoUser $foto = null,
    ) {
        $q                  = static fn (string $nama): string => $db->getPlatform()->quoteIdentifier($nama);
        $this->tUser        = $q($tabel->user());
        $this->tAkses       = $q($tabel->akses());
        $this->tEmail       = $q($tabel->email());
        $this->tRiwayat     = $q($tabel->riwayat());
        $this->tRiwayatAkun = $q($tabel->riwayatAkun());
        $this->tToken       = $q($tabel->token());
        $this->tPengaturan  = $q($tabel->pengaturan());
        $this->tProfil      = $q($tabel->profil());
    }

    /**
     * Alasan dua akun tidak bisa digabung, atau null bila bisa.
     *
     * @param bool $lewatWeb dari halaman Pengaturan & Privasi: akun admin super hanya bisa digabung lewat terminal
     */
    public function alasanDitolak(User $a, User $b, bool $lewatWeb): ?string
    {
        if ($a->id === $b->id) {
            return 'Email itu sudah milik akun Anda.';
        }

        if (! $this->daftar->email()->tersedia() || ! $this->daftar->profil()->tersedia()) {
            return 'Penggabungan akun belum bisa dipakai. Jalankan "vendor/bin/dapur-user pasang" dari folder inti Dapur.';
        }

        if (! $b->aktif) {
            return 'Akun itu sedang nonaktif dan tidak bisa digabungkan. Hubungi admin.';
        }

        if ($lewatWeb && ($this->daftar->adalahAdminSuper($a->id) || $this->daftar->adalahAdminSuper($b->id))) {
            return 'Akun admin super hanya bisa digabungkan oleh admin server lewat terminal.';
        }

        return null;
    }

    /**
     * Ringkasan perubahan sebelum digabung, untuk ditampilkan saat meminta konfirmasi.
     *
     * @return array{email: list<string>, akses: list<array{lingkup: string, peranB: string, peranA: ?string, peranAkhir: string}>}
     */
    public function ringkasan(User $a, User $b): array
    {
        $aksesA = [];
        foreach ($this->daftar->aksesUser($a->id) as $x) {
            $aksesA[$x['lingkup']] = $x['peran'];
        }

        $akses = [];
        foreach ($this->daftar->aksesUser($b->id) as $x) {
            $peranA  = $aksesA[$x['lingkup']] ?? null;
            $akses[] = [
                'lingkup'    => $x['lingkup'],
                'peranB'     => $x['peran'],
                'peranA'     => $peranA,
                'peranAkhir' => $peranA === null ? $x['peran'] : self::peranGabungan($peranA, $x['peran']),
            ];
        }

        return [
            'email' => array_column($this->daftar->email()->daftar($b->id), 'email'),
            'akses' => $akses,
        ];
    }

    /**
     * Menggabungkan akun B ke akun A. Panggil alasanDitolak() lebih dulu.
     *
     * @return array{email: list<string>, akses: list<array{lingkup: string, peranB: string, peranA: ?string, peranAkhir: string}>}
     *         ringkasan yang sudah dijalankan
     */
    public function gabung(User $a, User $b): array
    {
        $ringkasan     = $this->ringkasan($a, $b);
        $fotoHapus     = [];
        $adaAdminSuper = $this->daftar->jumlahAdminSuper() > 0;
        $koneksi   = $this->db->getDriver()->getConnection();
        $koneksi->beginTransaction();
        try {
            $this->pindahEmail($a, $b);
            $this->pindahAkses($a->id, $b->id);
            $this->jalankan("UPDATE {$this->tRiwayat} SET user_id = ? WHERE user_id = ?", [$a->id, $b->id]);
            $this->jalankan("UPDATE {$this->tRiwayatAkun} SET sasaran_id = ? WHERE sasaran_id = ?", [$a->id, $b->id]);
            $this->jalankan("UPDATE {$this->tRiwayatAkun} SET pelaku_id = ? WHERE pelaku_id = ?", [$a->id, $b->id]);
            $this->jalankan("DELETE FROM {$this->tToken} WHERE user_id = ?", [$b->id]);
            $this->pindahPengaturan($a->id, $b->id);
            $fotoHapus = $this->pindahProfil($a->id, $b->id);
            if ($b->foto !== '') {
                $fotoHapus[] = $b->foto;
            }

            $this->jalankan("DELETE FROM {$this->tUser} WHERE id = ?", [$b->id]);

            // Pengaman terakhir: Dapur tidak boleh kehilangan semua admin supernya karena penggabungan.
            if ($adaAdminSuper && $this->daftar->jumlahAdminSuper() === 0) {
                throw new \RuntimeException('Penggabungan dibatalkan: tidak akan ada lagi admin super.');
            }

            $koneksi->commit();
        } catch (\Throwable $e) {
            $koneksi->rollback();

            throw $e;
        }

        // File foto dihapus sesudah database selesai, agar penggabungan yang batal tidak kehilangan foto.
        foreach ($fotoHapus as $kode) {
            $this->foto?->hapus($kode);
        }

        return $ringkasan;
    }

    public static function peranLebihRendah(string $x, string $y): string
    {
        return (self::URUTAN_PERAN[$x] ?? 0) <= (self::URUTAN_PERAN[$y] ?? 0) ? $x : $y;
    }

    /**
     * Peran di lingkup yang dimiliki kedua akun. Admin super tidak pernah turun karena penggabungan (penggabungan
     * akun admin super hanya bisa lewat terminal); selain itu peran yang lebih rendah dipakai, demi keamanan.
     */
    public static function peranGabungan(string $peranA, string $peranB): string
    {
        if ($peranA === DaftarUser::PERAN_ADMIN_SUPER || $peranB === DaftarUser::PERAN_ADMIN_SUPER) {
            return DaftarUser::PERAN_ADMIN_SUPER;
        }

        return self::peranLebihRendah($peranA, $peranB);
    }

    private function pindahEmail(User $a, User $b): void
    {
        // Email utama B yang belum tercatat (data lama) dicatat dulu agar ikut pindah.
        if ($this->daftar->email()->id($b->id, $b->email) === null && $this->daftar->email()->pemilik($b->email) === null) {
            $this->daftar->email()->tambah($b->id, $b->email);
        }

        $this->jalankan("UPDATE {$this->tEmail} SET user_id = ? WHERE user_id = ?", [$a->id, $b->id]);
    }

    private function pindahAkses(int $a, int $b): void
    {
        $aksesA = [];
        foreach ($this->baris("SELECT lingkup, peran FROM {$this->tAkses} WHERE user_id = ?", [$a]) as $x) {
            $aksesA[(string) $x['lingkup']] = DaftarUser::normalkanPeran((string) $x['peran'], (string) $x['lingkup']);
        }

        foreach ($this->baris("SELECT lingkup, peran FROM {$this->tAkses} WHERE user_id = ?", [$b]) as $x) {
            $lingkup = (string) $x['lingkup'];
            $peranB  = DaftarUser::normalkanPeran((string) $x['peran'], $lingkup);
            if (! isset($aksesA[$lingkup])) {
                // Akses pindah apa adanya, termasuk email website pilihan B (emailnya ikut pindah ke A).
                $this->jalankan("UPDATE {$this->tAkses} SET user_id = ? WHERE user_id = ? AND lingkup = ?", [$a, $b, $lingkup]);

                continue;
            }

            $this->jalankan(
                "UPDATE {$this->tAkses} SET peran = ? WHERE user_id = ? AND lingkup = ?",
                [self::peranGabungan($aksesA[$lingkup], $peranB), $a, $lingkup],
            );
            $this->jalankan("DELETE FROM {$this->tAkses} WHERE user_id = ? AND lingkup = ?", [$b, $lingkup]);
        }
    }

    private function pindahPengaturan(int $a, int $b): void
    {
        $milikA = array_map(static fn (array $x): string => (string) $x['kunci'], $this->baris("SELECT kunci FROM {$this->tPengaturan} WHERE user_id = ?", [$a]));
        foreach ($this->baris("SELECT kunci FROM {$this->tPengaturan} WHERE user_id = ?", [$b]) as $x) {
            if (! in_array((string) $x['kunci'], $milikA, true)) {
                $this->jalankan("UPDATE {$this->tPengaturan} SET user_id = ? WHERE user_id = ? AND kunci = ?", [$a, $b, $x['kunci']]);
            }
        }

        $this->jalankan("DELETE FROM {$this->tPengaturan} WHERE user_id = ?", [$b]);
    }

    /**
     * Isian Tentang B mengisi yang kosong di A; profil khusus website B dipakai hanya untuk website yang belum punya
     * profil khusus dari A. Sisanya dibuang.
     *
     * @return list<string> kode foto milik B yang tidak dipakai lagi
     */
    private function pindahProfil(int $a, int $b): array
    {
        $milikA = [];
        foreach ($this->baris("SELECT lingkup, kunci FROM {$this->tProfil} WHERE user_id = ?", [$a]) as $x) {
            $milikA[(string) $x['lingkup']][(string) $x['kunci']] = true;
        }

        $fotoHapus = [];
        foreach ($this->baris("SELECT lingkup, kunci, nilai FROM {$this->tProfil} WHERE user_id = ?", [$b]) as $x) {
            $lingkup = (string) $x['lingkup'];
            $kunci   = (string) $x['kunci'];
            $pindah  = $lingkup === '' ? ! isset($milikA[''][$kunci]) : ! isset($milikA[$lingkup]);
            if ($pindah) {
                $this->jalankan(
                    "UPDATE {$this->tProfil} SET user_id = ? WHERE user_id = ? AND lingkup = ? AND kunci = ?",
                    [$a, $b, $lingkup, $kunci],
                );
            } elseif ($kunci === ProfilUser::FOTO && FotoUser::kodeSah($x['nilai'])) {
                $fotoHapus[] = (string) $x['nilai'];
            }
        }

        // Nomor kota hanya berlaku bersama teks Lokasi yang sama; bila Lokasi A tetap, nomor kota B tidak dipakai.
        if (isset($milikA['']['lokasi']) && ! isset($milikA[''][ProfilUser::LOKASI_ID])) {
            $this->jalankan("DELETE FROM {$this->tProfil} WHERE user_id = ? AND lingkup = '' AND kunci = ?", [$a, ProfilUser::LOKASI_ID]);
        }

        $this->jalankan("DELETE FROM {$this->tProfil} WHERE user_id = ?", [$b]);

        return $fotoHapus;
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

    /** @param list<mixed> $parameter */
    private function jalankan(string $sql, array $parameter): void
    {
        $this->db->query($sql, $parameter);
    }
}
