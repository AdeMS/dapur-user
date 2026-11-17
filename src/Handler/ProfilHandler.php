<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\AturanKataSandi;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Perangkat;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SuratUser;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use AdeMS\Dapur\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Halaman milik user yang sedang login, dipasang di belakang WajibMasukMiddleware. Satu kelas melayani dua halaman:
 * - Profil (HALAMAN_PROFIL): kartu user (hanya dibaca);
 * - Pengaturan & Privasi (HALAMAN_PENGATURAN): tab Akun Saya (mengubah nama; mengganti email bila email bisa
 *   dikirim dan mengganti kata sandi lewat popup), Aktivitas (perubahan akun), dan Riwayat masuk.
 *
 * Email baru berlaku setelah tautan verifikasi yang dikirim ke email itu dibuka (VerifikasiEmailHandler); email lama
 * menerima pemberitahuan, agar pemilik akun tahu bila bukan dia yang memintanya.
 *
 * Setelah kata sandi diganti, session di perangkat lain otomatis tidak berlaku (lihat
 * WajibMasukMiddleware::SESI_SANDI); session yang dipakai sekarang tetap login dengan ID baru.
 */
final class ProfilHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::profil';

    public const TEMPLATE_PENGATURAN = 'user::pengaturan';

    public const HALAMAN_PROFIL     = 'profil';
    public const HALAMAN_PENGATURAN = 'pengaturan';

    /** Pesan berhasil yang ditampilkan sekali setelah pengalihan. */
    public const SESI_PESAN = 'user.pesan';

    /** Jumlah baris awal di tab Riwayat masuk. */
    public const JUMLAH_RIWAYAT = 20;

    /** Jumlah baris awal di tab Aktivitas. */
    public const JUMLAH_AKTIVITAS = 20;

    /**
     * Tombol "Tampilkan lebih banyak" menambah baris tab yang terbuka sebanyak ini (?tab=...&jumlah=40, 60, ...),
     * paling banyak JUMLAH_MAKS.
     */
    public const JUMLAH_LANGKAH = 20;
    public const JUMLAH_MAKS    = 480;

    /** Tab di halaman Pengaturan & Privasi; bisa dibuka langsung lewat ?tab=... */
    public const TAB_PENGATURAN = ['akun', 'aktivitas', 'riwayat'];

    /** Aksi form yang diterima tiap halaman. Halaman Profil hanya untuk dibaca. */
    private const AKSI = [
        self::HALAMAN_PROFIL     => [],
        self::HALAMAN_PENGATURAN => ['nama', 'sandi', 'email'],
    ];

    /** Tautan lama ke tab halaman profil (sebelum ada Pengaturan & Privasi) => tab tujuannya. */
    private const TAB_LAMA = [
        'sandi'     => 'akun',
        'email'     => 'akun',
        'aktivitas' => 'aktivitas',
        'riwayat'   => 'riwayat',
    ];

    private const PANJANG_NAMA_MAKS  = 190;
    private const PANJANG_SANDI_MAKS = 1024;

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly PembatasPercobaan $pembatas,
        private readonly RiwayatMasuk $riwayat,
        private readonly PenyajiHalaman $penyaji,
        private readonly Pintu $pintu,
        private readonly DateTimeZone $zonaWaktu,
        private readonly string $template = self::TEMPLATE,
        private readonly ?RiwayatAkun $riwayatAkun = null,
        private readonly ?TokenUser $token = null,
        private readonly ?SuratUser $surat = null,
        private readonly string $namaWebsite = 'Dapur AdeMS',
        private readonly string $jenis = self::HALAMAN_PROFIL,
    ) {
        if (! isset(self::AKSI[$jenis])) {
            throw new \InvalidArgumentException("Jenis halaman tidak dikenal: {$jenis}");
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        $user = $request->getAttribute(User::class);
        assert($sesi instanceof Sesi && $user instanceof User);

        if ($request->getMethod() !== 'POST') {
            $pesan = $sesi->ambil(self::SESI_PESAN);
            $sesi->hapus(self::SESI_PESAN);

            $tab = $request->getQueryParams()['tab'] ?? null;
            $tab = is_string($tab) ? $tab : null;

            // Tautan lama ke tab profil (?tab=sandi dan sebagainya; sebelum ada halaman Pengaturan & Privasi).
            if ($this->jenis === self::HALAMAN_PROFIL && isset(self::TAB_LAMA[$tab])) {
                if (is_string($pesan)) {
                    $sesi->simpan(self::SESI_PESAN, $pesan);
                }

                return new RedirectResponse($this->alamatPengaturan(self::TAB_LAMA[$tab]));
            }

            $tab    = in_array($tab, self::TAB_PENGATURAN, true) ? $tab : 'akun';
            $jumlah = self::jumlah($request->getQueryParams()['jumlah'] ?? null);

            return $this->halaman($request, $user, [
                'pesan' => is_string($pesan) ? $pesan : null,
                'tab'   => $tab,
            ], 200, [
                'aktivitas' => $tab === 'aktivitas' ? $jumlah : self::JUMLAH_AKTIVITAS,
                'riwayat'   => $tab === 'riwayat' ? $jumlah : self::JUMLAH_RIWAYAT,
            ]);
        }

        $isi = (array) $request->getParsedBody();
        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->galat($request, $user, null, 'Halaman sudah kedaluwarsa. Silakan coba lagi.', 400);
        }

        $aksi = $isi['aksi'] ?? null;
        if (! in_array($aksi, self::AKSI[$this->jenis], true)) {
            return $this->galat($request, $user, null, 'Permintaan tidak dikenal.', 400);
        }

        return match ($aksi) {
            'nama'  => $this->gantiNama($request, $user, $sesi, $isi),
            'sandi' => $this->gantiSandi($request, $user, $sesi, $isi),
            'email' => $this->gantiEmail($request, $user, $sesi, $isi),
            default => $this->galat($request, $user, null, 'Permintaan tidak dikenal.', 400),
        };
    }

    /** @param array<mixed> $isi */
    private function gantiNama(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $nama = is_string($isi['nama'] ?? null) ? trim($isi['nama']) : '';
        if ($nama === '' || mb_strlen($nama, 'UTF-8') > self::PANJANG_NAMA_MAKS
            || preg_match('/[\x00-\x1f\x7f]/u', $nama) !== 0) {
            return $this->galat(
                $request,
                $user,
                'nama',
                sprintf('Nama wajib diisi, paling panjang %d karakter.', self::PANJANG_NAMA_MAKS),
                422,
                ['nama' => is_string($isi['nama'] ?? null) ? $isi['nama'] : ''],
            );
        }

        if ($this->riwayatAkun !== null && ! $this->riwayatAkun->tersedia()) {
            return $this->galat($request, $user, 'nama', RiwayatAkun::PESAN_BELUM_DIPASANG, 503, ['nama' => $nama]);
        }

        if ($nama !== $user->nama) {
            $this->daftar->gantiNama($user->id, $nama);
            $this->riwayatAkun?->catat(
                RiwayatAkun::UBAH_NAMA,
                $user->id,
                $user->id,
                $this->pintu->lingkup,
                $user->nama . ' → ' . $nama,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                $request->getHeaderLine('User-Agent'),
            );
        }

        $sesi->simpan(self::SESI_PESAN, 'Nama sudah disimpan.');

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /** @param array<mixed> $isi */
    private function gantiSandi(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $lama  = is_string($isi['sandi_lama'] ?? null) ? $isi['sandi_lama'] : '';
        $baru  = is_string($isi['sandi_baru'] ?? null) ? $isi['sandi_baru'] : '';
        $ulang = is_string($isi['sandi_ulang'] ?? null) ? $isi['sandi_ulang'] : '';

        // Kata sandi lama yang salah dihitung seperti login gagal, agar tidak bisa ditebak berulang-ulang
        // oleh orang yang sempat memakai session ini.
        if ($this->pembatas->terkunci($user->email, $ip)) {
            return $this->galat($request, $user, 'sandi', 'Terlalu banyak percobaan. Silakan coba lagi nanti.', 429);
        }

        if ($lama === '' || strlen($lama) > self::PANJANG_SANDI_MAKS || ! password_verify($lama, $user->kataSandi)) {
            $this->pembatas->catat($user->email, $ip);

            return $this->galat($request, $user, 'sandi', 'Kata sandi lama salah.', 422);
        }

        $minimal = AturanKataSandi::panjangMinimal($this->daftar->adalahAdmin($user->id));
        if (strlen($baru) < $minimal || strlen($baru) > self::PANJANG_SANDI_MAKS) {
            return $this->galat($request, $user, 'sandi', sprintf('Kata sandi baru minimal %d karakter.', $minimal), 422);
        }

        if (! hash_equals($baru, $ulang)) {
            return $this->galat($request, $user, 'sandi', 'Kedua kata sandi baru tidak sama.', 422);
        }

        $this->daftar->gantiSandi($user->id, $baru);
        $this->pembatas->bersihkan($user->email, $ip);
        $this->riwayat->catat(RiwayatMasuk::GANTI_SANDI, $user->id, $this->pintu->lingkup, $ip, $request->getHeaderLine('User-Agent'));

        // Session ini tetap login dengan sidik jari kata sandi yang baru; session lain tidak berlaku lagi.
        $baruUser = $this->daftar->cariId($user->id) ?? $user;
        $sesi->gantiId();
        (new TokenCsrf($sesi))->baru();
        $sesi->simpan(WajibMasukMiddleware::SESI_SANDI, $baruUser->sidikSandi());
        $sesi->simpan(self::SESI_PESAN, 'Kata sandi sudah diganti. Perangkat lain yang sedang login sudah dikeluarkan.');

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /**
     * Meminta ganti email: memeriksa kata sandi sekarang, lalu mengirim tautan verifikasi ke email baru dan
     * pemberitahuan ke email lama. Email akun baru berubah setelah tautan dibuka.
     *
     * @param array<mixed> $isi
     */
    private function gantiEmail(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        if (! $this->bisaGantiEmail()) {
            return $this->galat($request, $user, 'email', 'Email belum bisa diubah karena pengiriman email belum diaktifkan.', 503);
        }

        assert($this->token !== null && $this->surat !== null);
        $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $baru  = is_string($isi['email_baru'] ?? null) ? DaftarUser::normalkanEmail($isi['email_baru']) : '';
        $sandi = is_string($isi['sandi_sekarang'] ?? null) ? $isi['sandi_sekarang'] : '';
        $form  = ['emailBaru' => $baru];

        if ($this->pembatas->terkunci($user->email, $ip)) {
            return $this->galat($request, $user, 'email', 'Terlalu banyak percobaan. Silakan coba lagi nanti.', 429, $form);
        }

        if ($sandi === '' || strlen($sandi) > self::PANJANG_SANDI_MAKS || ! password_verify($sandi, $user->kataSandi)) {
            $this->pembatas->catat($user->email, $ip);

            return $this->galat($request, $user, 'email', 'Kata sandi salah.', 422, $form);
        }

        if (filter_var($baru, FILTER_VALIDATE_EMAIL) === false || strlen($baru) > 190) {
            return $this->galat($request, $user, 'email', 'Email baru tidak sah.', 422, $form);
        }

        if ($baru === $user->email) {
            return $this->galat($request, $user, 'email', 'Email baru sama dengan email sekarang.', 422, $form);
        }

        if ($this->daftar->cariEmail($baru) !== null) {
            return $this->galat($request, $user, 'email', "{$baru} sudah dipakai akun lain.", 422, $form);
        }

        $token = $this->token->buat($user->id, TokenUser::GANTI_EMAIL, $baru);
        try {
            $this->surat->kirimVerifikasiEmail(
                $user,
                $baru,
                $this->surat->alamatLengkap($request, $this->pintu->alamatVerifikasiEmail(), ['token' => $token]),
                $this->namaWebsite,
            );
        } catch (GagalKirim $e) {
            return $this->galat($request, $user, 'email', 'Email verifikasi gagal dikirim: ' . $e->getMessage(), 502, $form);
        }

        try {
            $this->surat->kirimPemberitahuanEmail($user, $baru, false, $this->namaWebsite);
        } catch (GagalKirim) {
            // Pemberitahuan ke email lama hanya pelengkap; permintaan tetap berjalan.
        }

        $sesi->simpan(self::SESI_PESAN, "Tautan konfirmasi sudah dikirim ke {$baru}. Email akun berubah setelah tautan itu dibuka.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /** Jumlah baris dari ?jumlah=..., dibulatkan ke kelipatan JUMLAH_LANGKAH antara JUMLAH_LANGKAH dan JUMLAH_MAKS. */
    private static function jumlah(mixed $nilai): int
    {
        $jumlah = is_string($nilai) && ctype_digit($nilai) ? (int) $nilai : self::JUMLAH_LANGKAH;
        $jumlah = (int) (ceil($jumlah / self::JUMLAH_LANGKAH) * self::JUMLAH_LANGKAH);

        return max(self::JUMLAH_LANGKAH, min(self::JUMLAH_MAKS, $jumlah));
    }

    private function alamatPengaturan(string $tab): string
    {
        return $this->pintu->alamatPengaturan() . '?tab=' . rawurlencode($tab);
    }

    private function bisaGantiEmail(): bool
    {
        return $this->token !== null && $this->surat !== null && $this->surat->aktif() && $this->token->tersedia();
    }

    /** @param array<string, mixed> $data */
    private function galat(
        ServerRequestInterface $request,
        User $user,
        ?string $bagian,
        string $pesan,
        int $status,
        array $data = [],
    ): ResponseInterface {
        // Semua form ada di tab Akun Saya; form sandi dan email ada di popup, yang langsung terbuka lagi bersama
        // pesannya (lihat template).
        return $this->halaman($request, $user, $data + ['galat' => $pesan, 'galatBagian' => $bagian, 'tab' => 'akun'], $status);
    }

    /**
     * @param array<string, mixed> $data
     * @param array{aktivitas?: int, riwayat?: int} $jumlah  jumlah baris tiap tab
     */
    private function halaman(
        ServerRequestInterface $request,
        User $user,
        array $data,
        int $status = 200,
        array $jumlah = [],
    ): ResponseInterface {
        $profil = $this->jenis === self::HALAMAN_PROFIL;
        [$aktivitas, $aktivitasLagi] = $profil ? [[], null] : $this->lebihBanyak(
            fn (int $n): array => $this->aktivitas($user, $n),
            $jumlah['aktivitas'] ?? self::JUMLAH_AKTIVITAS,
        );
        [$riwayat, $riwayatLagi] = $profil ? [[], null] : $this->lebihBanyak(
            fn (int $n): array => $this->riwayat($user, $n),
            $jumlah['riwayat'] ?? self::JUMLAH_RIWAYAT,
        );

        return $this->penyaji->halaman($this->template, $request, $data + [
            'pesan'        => null,
            'galat'        => null,
            'galatBagian'  => null,
            'nama'         => $user->nama,
            'emailBaru'    => '',
            'bisaGantiEmail' => $this->bisaGantiEmail(),
            'alamatProfil' => $this->pintu->alamatProfil(),
            'alamatPengaturan' => $this->pintu->alamatPengaturan(),
            'minimalSandi' => AturanKataSandi::panjangMinimal($this->daftar->adalahAdmin($user->id)),
            'zonaWaktu'    => $this->zonaWaktu->getName(),
            'riwayat'      => $riwayat,
            'riwayatLagi'  => $riwayatLagi,
            'tab'          => 'akun',
            'peran'        => $this->peran($request),
            'aktif'        => $user->aktif,
            'aktivitas'    => $aktivitas,
            'aktivitasLagi' => $aktivitasLagi,
            'alamatBeranda' => $this->pintu->alamatDasar === '' ? '/' : $this->pintu->alamatDasar,
        ], $status);
    }

    /** @return array{nilai: string, label: string}|null peran user di lingkup ini */
    private function peran(ServerRequestInterface $request): ?array
    {
        $peran = $request->getAttribute(WajibMasukMiddleware::ATRIBUT_PERAN);

        return is_string($peran) && $peran !== '' ? ['nilai' => $peran, 'label' => DaftarUser::label($peran)] : null;
    }

    /**
     * Mengambil satu baris lebih banyak dari yang ditampilkan untuk mengetahui apakah masih ada data berikutnya.
     *
     * @param callable(int): list<array<string, string>> $ambil
     * @return array{0: list<array<string, string>>, 1: int|null}  baris yang ditampilkan, dan jumlah untuk tombol
     *                                                          "Tampilkan lebih banyak" (null bila tidak ada lagi)
     */
    private function lebihBanyak(callable $ambil, int $jumlah): array
    {
        $baris = $ambil($jumlah + 1);
        if (count($baris) <= $jumlah) {
            return [$baris, null];
        }

        $lagi = $jumlah < self::JUMLAH_MAKS ? min(self::JUMLAH_MAKS, $jumlah + self::JUMLAH_LANGKAH) : null;

        return [array_slice($baris, 0, $jumlah), $lagi];
    }

    /**
     * Aktivitas akun user ini, terbaru lebih dulu: perubahan pada akunnya dan perubahan yang ia lakukan pada akun
     * lain. Email admin yang mengubah akunnya tidak ditampilkan, karena user bisa saja anggota website lain yang
     * tidak perlu tahu email admin.
     *
     * @return list<array{waktu: string, peristiwa: string, label: string, keterangan: string, lingkup: string, user: string, oleh: string}>
     */
    private function aktivitas(User $user, int $jumlah): array
    {
        if ($this->riwayatAkun === null || ! $this->riwayatAkun->tersedia()) {
            return [];
        }

        $utc   = new DateTimeZone('UTC');
        $baris = [];
        foreach ($this->riwayatAkun->aktivitasUser($user->id, $jumlah) as $b) {
            $baris[] = [
                'waktu'      => (new DateTimeImmutable($b['waktu'], $utc))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i'),
                'peristiwa'  => $b['peristiwa'],
                'label'      => RiwayatAkun::label($b['peristiwa']),
                'keterangan' => $b['keterangan'],
                'lingkup'    => $b['lingkup'],
                'user'       => $b['sasaran'] === $user->email ? 'Anda' : $b['sasaran'],
                'oleh'       => match (true) {
                    $b['pelaku'] === null        => 'Terminal',
                    $b['pelaku'] === $user->email => 'Anda',
                    default                      => 'Admin',
                },
            ];
        }

        return $baris;
    }

    /** @return list<array{waktu: string, peristiwa: string, label: string, lingkup: string, ip: string, perangkat: string, perangkatLengkap: string}> */
    private function riwayat(User $user, int $jumlah): array
    {
        $utc   = new DateTimeZone('UTC');
        $baris = [];
        foreach ($this->riwayat->untukUser($user->id, $jumlah) as $b) {
            $baris[] = [
                'waktu'            => (new DateTimeImmutable($b['waktu'], $utc))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i'),
                'peristiwa'        => $b['peristiwa'],
                'label'            => RiwayatMasuk::label($b['peristiwa']),
                'lingkup'          => $b['lingkup'],
                'ip'               => $b['ip'],
                'perangkat'        => Perangkat::ringkas($b['perangkat']),
                'perangkatLengkap' => $b['perangkat'],
            ];
        }

        return $baris;
    }
}
