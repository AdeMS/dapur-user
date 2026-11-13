<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Closure;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Metadata\Source\Factory;

/** Perintah baris: pasang, buat-admin, ganti-sandi, riwayat, daftar-akses, cabut-akses, riwayat-akun. */
final class Perintah
{
    /** Isi kolom perangkat di riwayat untuk kata sandi yang diganti lewat perintah ini. */
    public const PERANGKAT_TERMINAL = 'dapur-user (terminal)';

    /**
     * @param Closure(string): string      $bacaSandi meminta kata sandi tanpa menampilkannya
     * @param (Closure(string): string)|null $tanya   meminta jawaban biasa; null bila tidak dijalankan di
     *                                               terminal, sehingga tidak ada yang ditanyakan
     */
    public function __construct(
        private readonly Adapter $db,
        private readonly DaftarUser $user,
        private readonly Closure $bacaSandi,
        private readonly string $lingkup,
        private readonly ?Closure $tanya = null,
        private readonly NamaTabel $tabel = new NamaTabel(),
        private readonly ?RiwayatMasuk $riwayat = null,
        private readonly ?\DateTimeZone $zonaWaktu = null,
        private readonly ?RiwayatAkun $riwayatAkun = null,
    ) {
    }

    /** @param list<string> $argumen tanpa nama program */
    public function jalankan(array $argumen): int
    {
        // --website=<domain> sudah dibaca paket multi-website sebelum perintah ini berjalan.
        $argumen = array_values(array_filter($argumen, static fn (string $a): bool => ! str_starts_with($a, '--website=')));
        $utama   = array_values(array_filter($argumen, static fn (string $a): bool => ! str_starts_with($a, '--')));

        return match ($utama[0] ?? '') {
            'pasang'       => $this->pasang(),
            'buat-admin'   => $this->buatAdmin($utama[1] ?? '', $this->opsi($argumen, 'nama')),
            'ganti-sandi'  => $this->gantiSandi($utama[1] ?? ''),
            'riwayat'      => $this->tampilkanRiwayat($utama[1] ?? '', $this->opsi($argumen, 'jumlah')),
            'daftar-akses' => $this->daftarAkses($utama[1] ?? null),
            'cabut-akses'  => $this->cabutAkses($utama[1] ?? ''),
            'riwayat-akun' => $this->tampilkanRiwayatAkun($utama[1] ?? null, $this->opsi($argumen, 'jumlah')),
            default        => $this->bantuan(($utama[0] ?? '') === '' ? 0 : 1),
        };
    }

    /**
     * Membuat tabel yang belum ada, lalu mengubah peran 'admin' dari versi 1.x menjadi admin super (lingkup
     * utama) atau admin web (website). Tabel yang sudah ada tidak diubah, jadi aman diulang.
     */
    private function pasang(): int
    {
        $ada = Factory::createSourceFromAdapter($this->db)->getTableNames();

        foreach (Skema::tabel($this->db->getPlatform()->getName(), $this->tabel) as $tabel => $sql) {
            if (in_array($tabel, $ada, true)) {
                printf("Tabel %-28s sudah ada%s", $tabel, PHP_EOL);
                continue;
            }

            $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE);
            printf("Tabel %-28s dibuat%s", $tabel, PHP_EOL);
        }

        $diubah = $this->user->perbaruiPeranLama();
        if ($diubah > 0) {
            printf('%sPeran admin lama diperbarui: %d akses (admin super di dashboard utama, admin web di website).%s', PHP_EOL, $diubah, PHP_EOL);
        }

        echo PHP_EOL . 'Tabel user sudah siap; data yang sudah ada tidak diubah.' . PHP_EOL;

        return 0;
    }

    private function buatAdmin(string $email, ?string $nama): int
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->gagal('Pemakaian: dapur-user buat-admin <email> [--nama="Nama Lengkap"]');
        }

        $ada = $this->user->cariEmail($email);
        if ($ada !== null) {
            return $this->userSudahAda($ada);
        }

        $sandi = $this->mintaSandiBaru(true);
        if ($sandi === null) {
            return 1;
        }

        $peran = DaftarUser::peranAdminUntuk($this->lingkup);
        $id    = $this->user->buat($email, $nama ?? $email, $sandi);
        $this->user->tambahAkses($id, $this->lingkup, $peran);
        $this->catatAkun(RiwayatAkun::BUAT_USER, $id, $nama ?? $email);
        $this->catatAkun(RiwayatAkun::BERI_AKSES, $id, 'peran ' . $peran);
        printf("%s %s dibuat untuk lingkup '%s'.%s", DaftarUser::label($peran), DaftarUser::normalkanEmail($email), $this->lingkup, PHP_EOL);

        return 0;
    }

    /** Memastikan akses ke lingkup ini, lalu menawarkan ganti kata sandi (bawaan: tidak). */
    private function userSudahAda(User $user): int
    {
        $peran = DaftarUser::peranAdminUntuk($this->lingkup);
        $lama  = $this->user->peranTersimpan($user->id, $this->lingkup);
        if ($lama === $peran) {
            printf("User %s sudah ada dan sudah menjadi %s di '%s'.%s", $user->email, strtolower(DaftarUser::label($peran)), $this->lingkup, PHP_EOL);
        } elseif ($lama !== null) {
            $this->user->ubahPeran($user->id, $this->lingkup, $peran);
            $this->catatAkun(RiwayatAkun::UBAH_PERAN, $user->id, $lama . ' → ' . $peran);
            printf("User %s sudah ada; perannya di '%s' diubah menjadi %s.%s", $user->email, $this->lingkup, strtolower(DaftarUser::label($peran)), PHP_EOL);
        } else {
            $this->user->tambahAkses($user->id, $this->lingkup, $peran);
            $this->catatAkun(RiwayatAkun::BERI_AKSES, $user->id, 'peran ' . $peran);
            printf("User %s sudah ada; akses %s ke '%s' ditambahkan.%s", $user->email, strtolower(DaftarUser::label($peran)), $this->lingkup, PHP_EOL);
        }

        if ($this->tanya === null) {
            echo 'Kata sandi tidak diubah. Gunakan perintah ganti-sandi untuk menggantinya.' . PHP_EOL;

            return 0;
        }

        $jawaban = strtolower(trim(($this->tanya)('Ganti kata sandinya sekarang? [y/N]: ')));
        if (! in_array($jawaban, ['y', 'ya'], true)) {
            echo 'Kata sandi tidak diubah.' . PHP_EOL;

            return 0;
        }

        $sandi = $this->mintaSandiBaru($this->user->adalahAdmin($user->id));
        if ($sandi === null) {
            return 1;
        }

        $this->simpanSandi($user, $sandi);

        return 0;
    }

    private function gantiSandi(string $email): int
    {
        $user = $email !== '' ? $this->user->cariEmail($email) : null;
        if ($user === null) {
            return $this->gagal('User tidak ditemukan. Pemakaian: dapur-user ganti-sandi <email>');
        }

        $sandi = $this->mintaSandiBaru($this->user->adalahAdmin($user->id));
        if ($sandi === null) {
            return 1;
        }

        $this->simpanSandi($user, $sandi);

        return 0;
    }

    /** Mengganti kata sandi dan mencatatnya di riwayat; session user di semua perangkat ikut berakhir. */
    private function simpanSandi(User $user, string $sandi): void
    {
        $this->user->gantiSandi($user->id, $sandi);
        $this->riwayat?->catat(RiwayatMasuk::GANTI_SANDI, $user->id, $this->lingkup, '', self::PERANGKAT_TERMINAL);
        printf('Kata sandi %s sudah diganti. Session yang sedang login di semua perangkat berakhir.%s', $user->email, PHP_EOL);
    }

    /**
     * Tanpa email: semua user yang punya akses ke lingkup ini. Dengan email: semua lingkup milik user itu.
     */
    private function daftarAkses(?string $email): int
    {
        if ($email !== null) {
            $user = $this->user->cariEmail($email);
            if ($user === null) {
                return $this->gagal('User tidak ditemukan. Pemakaian: dapur-user daftar-akses [<email>]');
            }

            $akses = $this->user->aksesUser($user->id);
            if ($akses === []) {
                printf('%s belum punya akses ke lingkup mana pun.%s', $user->email, PHP_EOL);

                return 0;
            }

            printf('Akses %s%s:%s', $user->email, $user->aktif ? '' : ' (nonaktif)', PHP_EOL);
            foreach ($akses as $a) {
                printf('  %-30s  %s%s', $a['lingkup'], DaftarUser::label($a['peran']), PHP_EOL);
            }

            if ($this->user->adalahAdminSuper($user->id)) {
                echo '  Sebagai admin super, user ini juga admin di semua website.' . PHP_EOL;
            }

            return 0;
        }

        $daftar = $this->user->userDiLingkup($this->lingkup);
        if ($daftar === []) {
            printf("Belum ada user dengan akses ke '%s'.%s", $this->lingkup, PHP_EOL);

            return 0;
        }

        $zona = $this->zona();
        printf("User dengan akses ke '%s' (waktu %s):%s", $this->lingkup, $zona->getName(), PHP_EOL);
        foreach ($daftar as $u) {
            printf(
                '  %-32s  %-24s  %-8s  %s  %s%s',
                $u['email'],
                DaftarUser::label($u['peran']) . ($u['otomatis'] ? ' (otomatis)' : ''),
                $u['aktif'] ? 'aktif' : 'nonaktif',
                $u['terakhir_masuk'] === null ? 'belum pernah login' : 'login ' . $this->waktu($u['terakhir_masuk'], $zona),
                $u['nama'],
                PHP_EOL,
            );
        }

        return 0;
    }

    /** Mencabut akses user dari lingkup ini; admin super terakhir tidak bisa dicabut agar Dapur tidak terkunci. */
    private function cabutAkses(string $email): int
    {
        $user = $email !== '' ? $this->user->cariEmail($email) : null;
        if ($user === null) {
            return $this->gagal('User tidak ditemukan. Pemakaian: dapur-user cabut-akses <email>');
        }

        $peran = $this->user->peranTersimpan($user->id, $this->lingkup);
        if ($peran === null) {
            $pesan = $this->user->adalahAdminSuper($user->id)
                ? sprintf("%s adalah admin super; aksesnya ke '%s' berlaku otomatis dan hanya bisa dicabut dari dashboard utama.", $user->email, $this->lingkup)
                : sprintf("%s tidak punya akses ke '%s'.", $user->email, $this->lingkup);

            return $this->gagal($pesan);
        }

        if ($peran === DaftarUser::PERAN_ADMIN_SUPER && $this->user->jumlahAdminSuper() <= 1) {
            return $this->gagal(sprintf(
                '%s adalah admin super terakhir; buat admin super lain lebih dulu dengan buat-admin dari folder inti.',
                $user->email,
            ));
        }

        if ($this->tanya !== null) {
            $jawaban = strtolower(trim(($this->tanya)(sprintf("Cabut akses %s ke '%s'? [y/N]: ", $user->email, $this->lingkup))));
            if (! in_array($jawaban, ['y', 'ya'], true)) {
                echo 'Akses tidak diubah.' . PHP_EOL;

                return 0;
            }
        }

        $this->user->cabutAkses($user->id, $this->lingkup);
        $this->catatAkun(RiwayatAkun::CABUT_AKSES, $user->id, "peran {$peran}");
        printf("Akses %s ke '%s' dicabut. Akun user tidak dihapus.%s", $user->email, $this->lingkup, PHP_EOL);

        return 0;
    }

    /** Perubahan akun lewat terminal dicatat tanpa pelaku. */
    private function catatAkun(string $peristiwa, int $sasaranId, string $keterangan = ''): void
    {
        $this->riwayatAkun?->catat($peristiwa, null, $sasaranId, $this->lingkup, $keterangan, '', self::PERANGKAT_TERMINAL);
    }

    /** Tanpa email: perubahan akun di lingkup ini. Dengan email: perubahan pada akun user itu di semua lingkup. */
    private function tampilkanRiwayatAkun(?string $email, ?string $jumlah): int
    {
        if ($this->riwayatAkun === null) {
            return $this->gagal('Riwayat akun tidak tersedia.');
        }

        $batas = $jumlah !== null && ctype_digit($jumlah) ? (int) $jumlah : 20;
        if ($email !== null) {
            $user = $this->user->cariEmail($email);
            if ($user === null) {
                return $this->gagal('User tidak ditemukan. Pemakaian: dapur-user riwayat-akun [<email>] [--jumlah=20]');
            }

            $baris = $this->riwayatAkun->untukUser($user->id, $batas);
            $judul = "Riwayat akun {$user->email}";
        } else {
            $baris = $this->riwayatAkun->untukLingkup($this->lingkup, $batas);
            $judul = "Riwayat akun di '{$this->lingkup}'";
        }

        if ($baris === []) {
            echo 'Belum ada riwayat akun.' . PHP_EOL;

            return 0;
        }

        $zona = $this->zona();
        printf('%s (waktu %s):%s', $judul, $zona->getName(), PHP_EOL);
        foreach ($baris as $b) {
            printf(
                '%s  %-11s  %-28s  oleh %-28s  %-20s  %s%s',
                $this->waktu($b['waktu'], $zona),
                $b['peristiwa'],
                $b['sasaran'],
                $b['pelaku'] ?? '(terminal)',
                $b['lingkup'],
                $b['keterangan'],
                PHP_EOL,
            );
        }

        return 0;
    }

    private function zona(): \DateTimeZone
    {
        return $this->zonaWaktu ?? new \DateTimeZone(date_default_timezone_get());
    }

    /** Waktu UTC dari database menurut zona waktu tampilan. */
    private function waktu(string $utc, \DateTimeZone $zona): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zona)->format('Y-m-d H:i:s');
    }

    /** Riwayat login satu user, terbaru lebih dulu, dengan waktu menurut pengaturan 'zona_waktu'. */
    private function tampilkanRiwayat(string $email, ?string $jumlah): int
    {
        $user = $email !== '' ? $this->user->cariEmail($email) : null;
        if ($user === null || $this->riwayat === null) {
            return $this->gagal('User tidak ditemukan. Pemakaian: dapur-user riwayat <email> [--jumlah=20]');
        }

        $baris = $this->riwayat->untukUser($user->id, $jumlah !== null && ctype_digit($jumlah) ? (int) $jumlah : 20);
        if ($baris === []) {
            printf('Belum ada riwayat login untuk %s.%s', $user->email, PHP_EOL);

            return 0;
        }

        $zona = $this->zona();
        printf('Riwayat login %s (waktu %s):%s', $user->email, $zona->getName(), PHP_EOL);
        foreach ($baris as $b) {
            printf(
                '%s  %-11s  %-20s  %-15s  %s%s',
                $this->waktu($b['waktu'], $zona),
                $b['peristiwa'],
                $b['lingkup'],
                $b['ip'],
                $b['perangkat'],
                PHP_EOL,
            );
        }

        return 0;
    }

    private function mintaSandiBaru(bool $admin): ?string
    {
        $minimal = AturanKataSandi::panjangMinimal($admin);
        $sandi   = ($this->bacaSandi)(sprintf('Kata sandi baru (minimal %d karakter): ', $minimal));
        if (strlen($sandi) < $minimal) {
            $this->gagal(sprintf('Kata sandi minimal %d karakter.', $minimal));

            return null;
        }

        if (! hash_equals($sandi, ($this->bacaSandi)('Ulangi kata sandi: '))) {
            $this->gagal('Kedua kata sandi tidak sama.');

            return null;
        }

        return $sandi;
    }

    /** @param list<string> $argumen */
    private function opsi(array $argumen, string $nama): ?string
    {
        foreach ($argumen as $a) {
            if (str_starts_with($a, "--{$nama}=")) {
                return substr($a, strlen($nama) + 3);
            }
        }

        return null;
    }

    private function gagal(string $pesan): int
    {
        fwrite(STDERR, $pesan . PHP_EOL);

        return 1;
    }

    private function bantuan(int $kode): int
    {
        echo <<<BANTUAN
            Pemakaian: dapur-user <perintah>

              pasang                          Membuat tabel yang belum ada dan memperbarui peran lama (aman diulang)
              buat-admin <email> [--nama=..]  Membuat admin super (di inti) atau admin web (dengan --website),
                                              atau menjadikan user yang sudah ada admin di lingkup ini
              ganti-sandi <email>             Mengganti kata sandi user
              riwayat <email> [--jumlah=20]   Menampilkan riwayat login user
              daftar-akses [<email>]          Menampilkan user di lingkup ini, atau semua akses satu user
              cabut-akses <email>             Mencabut akses user dari lingkup ini (akun tidak dihapus)
              riwayat-akun [<email>]          Menampilkan perubahan akun di lingkup ini, atau pada satu user

            Dengan paket adems/dapur-multiwebsite, tambahkan --website=<domain> untuk website tertentu.

            BANTUAN;

        return $kode;
    }
}
