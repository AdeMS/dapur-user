<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\AturanKataSandi;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarLokasi;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\FotoUser;
use AdeMS\Dapur\User\GabungAkun;
use AdeMS\Dapur\User\Identitas;
use AdeMS\Dapur\User\Konteks;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Perangkat;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\ProfilUser;
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
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Halaman milik user yang sedang login, dipasang di belakang WajibMasukMiddleware. Satu kelas melayani dua halaman:
 * - Profil (HALAMAN_PROFIL): kartu user dan bagian Tentang (hanya dibaca). Dengan ?id=<nomor>, profil rekan yang
 *   sama-sama punya akses ke lingkup ini;
 * - Pengaturan & Privasi (HALAMAN_PENGATURAN): tab Akun Saya (foto, nama, Tentang, profil di website ini; mengganti
 *   email bila email bisa dikirim dan mengganti kata sandi lewat popup), Aktivitas (perubahan akun), dan Riwayat
 *   masuk.
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
    public const TAB_PENGATURAN = ['akun', 'profil', 'aktivitas', 'riwayat'];

    /** Bagian form yang ada di tab Profil (lainnya di tab Akun Saya). */
    private const BAGIAN_PROFIL = ['foto', 'status', 'tentang', 'profil_website'];

    /** Aksi form yang diterima tiap halaman. Halaman Profil hanya untuk dibaca. */
    private const AKSI = [
        self::HALAMAN_PROFIL     => [],
        self::HALAMAN_PENGATURAN => [
            'nama', 'sandi', 'email', 'tambah_email', 'utama_email', 'hapus_email', 'email_website',
            'foto', 'hapus_foto', 'status', 'tentang', 'nama_website', 'gabung_akun',
        ],
    ];

    /** Tautan lama ke tab halaman profil (sebelum ada Pengaturan & Privasi) => tab tujuannya. */
    private const TAB_LAMA = [
        'sandi'     => 'akun',
        'email'     => 'akun',
        'aktivitas' => 'aktivitas',
        'riwayat'   => 'riwayat',
    ];

    private const PANJANG_NAMA_MAKS  = 190;
    /** Panjang tiap bagian nama (depan, tengah, belakang). */
    private const PANJANG_BAGIAN_NAMA = 100;
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
        private readonly BatasanDomain $domain = new BatasanDomain(),
        private readonly ?FotoUser $foto = null,
        private readonly ?DaftarLokasi $lokasi = null,
        private readonly ?GabungAkun $gabung = null,
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

            if ($this->jenis === self::HALAMAN_PROFIL) {
                return $this->halamanProfil($request, $user, is_string($pesan) ? $pesan : null);
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
        if ($isi === [] && (int) $request->getHeaderLine('Content-Length') > 0) {
            // Isi form dibuang PHP karena melewati post_max_size, biasanya foto yang terlalu besar.
            return $this->galat($request, $user, 'foto', 'Foto terlalu besar; paling besar 2 MB.', 413);
        }

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
            'tambah_email' => $this->gantiEmail($request, $user, $sesi, $isi, true),
            'utama_email'  => $this->jadikanEmailUtama($request, $user, $sesi, $isi),
            'hapus_email'  => $this->hapusEmail($request, $user, $sesi, $isi),
            'email_website' => $this->pilihEmailWebsite($request, $user, $sesi, $isi),
            'foto'          => $this->gantiFoto($request, $user, $sesi, $isi),
            'hapus_foto'    => $this->hapusFoto($request, $user, $sesi, $isi),
            'status'        => $this->simpanStatus($request, $user, $sesi, $isi),
            'tentang'       => $this->simpanTentang($request, $user, $sesi, $isi),
            'nama_website'  => $this->simpanNamaWebsite($request, $user, $sesi, $isi),
            'gabung_akun'   => $this->gabungAkun($request, $user, $sesi, $isi),
            default => $this->galat($request, $user, null, 'Permintaan tidak dikenal.', 400),
        };
    }

    /** @param array<mixed> $isi */
    private function gantiNama(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        if (array_key_exists('nama_depan', $isi) && $this->daftar->profil()->tersedia()) {
            return $this->gantiNamaLengkap($request, $user, $sesi, $isi);
        }

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
        $this->riwayat->catat(RiwayatMasuk::GANTI_SANDI, $user->id, $this->pintu->lingkup, $ip, $request->getHeaderLine('User-Agent'), KeluarHandler::emailSesi($sesi));

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
    private function gantiEmail(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi, bool $tambah = false): ResponseInterface
    {
        // Form ganti email utama dan tambah email memakai isian yang sama; bedanya jenis tautan konfirmasi.
        $bagianForm = $tambah ? 'tambah_email' : 'email';
        if (! $this->bisaGantiEmail() || ($tambah && ! $this->daftar->email()->tersedia())) {
            return $this->galat($request, $user, $bagianForm, 'Email belum bisa diubah karena pengiriman email belum diaktifkan.', 503);
        }

        assert($this->token !== null && $this->surat !== null);
        $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $baru  = is_string($isi['email_baru'] ?? null) ? DaftarUser::normalkanEmail($isi['email_baru']) : '';
        $sandi = is_string($isi['sandi_sekarang'] ?? null) ? $isi['sandi_sekarang'] : '';
        $form  = ['emailBaru' => $baru];

        if ($this->pembatas->terkunci($user->email, $ip)) {
            return $this->galat($request, $user, $bagianForm, 'Terlalu banyak percobaan. Silakan coba lagi nanti.', 429, $form);
        }

        if ($sandi === '' || strlen($sandi) > self::PANJANG_SANDI_MAKS || ! password_verify($sandi, $user->kataSandi)) {
            $this->pembatas->catat($user->email, $ip);

            return $this->galat($request, $user, $bagianForm, 'Kata sandi salah.', 422, $form);
        }

        if (filter_var($baru, FILTER_VALIDATE_EMAIL) === false || strlen($baru) > 190) {
            return $this->galat($request, $user, $bagianForm, 'Email baru tidak sah.', 422, $form);
        }

        $pemilik = $this->daftar->cariEmail($baru);
        if ($baru === $user->email || ($tambah && $pemilik?->id === $user->id)) {
            return $this->galat($request, $user, $bagianForm, $tambah ? "{$baru} sudah ada di akun Anda." : 'Email baru sama dengan email sekarang.', 422, $form);
        }

        if ($pemilik !== null && $pemilik->id !== $user->id) {
            return $this->galat($request, $user, $bagianForm, "{$baru} sudah dipakai akun lain.", 422, $form);
        }

        $token = $this->token->buat($user->id, $tambah ? TokenUser::TAMBAH_EMAIL : TokenUser::GANTI_EMAIL, $baru);
        try {
            $this->surat->kirimVerifikasiEmail(
                $user,
                $baru,
                $this->surat->alamatLengkap($request, $this->pintu->alamatVerifikasiEmail(), ['token' => $token]),
                $this->namaWebsite,
                $tambah,
            );
        } catch (GagalKirim $e) {
            return $this->galat($request, $user, $bagianForm, 'Email verifikasi gagal dikirim: ' . $e->getMessage(), 502, $form);
        }

        if ($tambah) {
            $sesi->simpan(self::SESI_PESAN, "Tautan konfirmasi sudah dikirim ke {$baru}. Email itu ditambahkan ke akun Anda setelah tautannya dibuka.");

            return new RedirectResponse($this->alamatPengaturan('akun'));
        }

        try {
            $this->surat->kirimPemberitahuanEmail($user, $baru, false, $this->namaWebsite);
        } catch (GagalKirim) {
            // Pemberitahuan ke email lama hanya pelengkap; permintaan tetap berjalan.
        }

        $sesi->simpan(self::SESI_PESAN, "Tautan konfirmasi sudah dikirim ke {$baru}. Email akun berubah setelah tautan itu dibuka.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /**
     * Menggabungkan akun lain (B) ke akun ini. Pemohon membuktikan memiliki akun B dengan kata sandinya (langsung
     * digabung) atau lewat tautan konfirmasi yang dikirim ke email B (digabung setelah tautan dibuka dan dikonfirmasi,
     * lihat VerifikasiEmailHandler). Akun admin super hanya bisa digabung lewat terminal.
     *
     * @param array<mixed> $isi
     */
    private function gabungAkun(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $ip     = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $emailB = is_string($isi['email_akun'] ?? null) ? DaftarUser::normalkanEmail($isi['email_akun']) : '';
        $cara   = ($isi['cara'] ?? null) === 'tautan' ? 'tautan' : 'sandi';
        $form   = ['gabungForm' => ['email' => $emailB, 'cara' => $cara]];

        if (! $this->bisaGabung($user)) {
            return $this->galat($request, $user, 'gabung', $this->daftar->adalahAdminSuper($user->id)
                ? 'Akun admin super hanya bisa digabungkan oleh admin server lewat terminal.'
                : 'Penggabungan akun belum bisa dipakai di sini.', 403, $form);
        }

        assert($this->gabung !== null);
        if (filter_var($emailB, FILTER_VALIDATE_EMAIL) === false || strlen($emailB) > 190) {
            return $this->galat($request, $user, 'gabung', 'Tulis email akun yang ingin digabungkan.', 422, $form);
        }

        if (($isi['setuju'] ?? null) !== '1') {
            return $this->galat($request, $user, 'gabung', 'Centang pernyataan bahwa penggabungan tidak bisa dibatalkan.', 422, $form);
        }

        $b = $this->daftar->cariEmail($emailB);
        if ($b !== null && $b->id === $user->id) {
            return $this->galat($request, $user, 'gabung', 'Email itu sudah milik akun Anda.', 422, $form);
        }

        if ($cara === 'tautan') {
            if (! $this->bisaGantiEmail()) {
                return $this->galat($request, $user, 'gabung', 'Tautan konfirmasi belum bisa dikirim karena pengiriman email belum diaktifkan. Pakai kata sandi akun itu.', 503, $form);
            }

            assert($this->token !== null && $this->surat !== null);
            // Jawaban selalu sama, agar halaman ini tidak bisa dipakai menebak email yang terdaftar.
            if ($b !== null && $this->gabung->alasanDitolak($user, $b, true) === null) {
                $token = $this->token->buat($user->id, TokenUser::GABUNG_AKUN, $b->id . '|' . $emailB);
                try {
                    $this->surat->kirimKonfirmasiGabung(
                        $user,
                        $emailB,
                        $this->surat->alamatLengkap($request, $this->pintu->alamatVerifikasiEmail(), ['token' => $token]),
                        $this->namaWebsite,
                    );
                } catch (GagalKirim $e) {
                    return $this->galat($request, $user, 'gabung', 'Tautan konfirmasi gagal dikirim: ' . $e->getMessage(), 502, $form);
                }
            }

            $sesi->simpan(self::SESI_PESAN, "Bila {$emailB} adalah akun lain di sini, tautan konfirmasi sudah dikirim ke email itu. Akun digabung setelah tautan itu dibuka dan dikonfirmasi.");

            return new RedirectResponse($this->alamatPengaturan('akun'));
        }

        // Kata sandi akun B yang salah dihitung seperti login gagal ke akun B.
        if ($this->pembatas->terkunci($emailB, $ip)) {
            return $this->galat($request, $user, 'gabung', 'Terlalu banyak percobaan. Silakan coba lagi nanti.', 429, $form);
        }

        $sandi = is_string($isi['sandi_akun'] ?? null) ? $isi['sandi_akun'] : '';
        if ($b === null || $sandi === '' || strlen($sandi) > self::PANJANG_SANDI_MAKS || ! password_verify($sandi, $b->kataSandi)) {
            $this->pembatas->catat($emailB, $ip);

            return $this->galat($request, $user, 'gabung', 'Email atau kata sandi akun itu salah.', 422, $form);
        }

        $this->pembatas->bersihkan($emailB, $ip);
        $alasan = $this->gabung->alasanDitolak($user, $b, true);
        if ($alasan !== null) {
            return $this->galat($request, $user, 'gabung', $alasan, 422, $form);
        }

        self::jalankanGabung($this->gabung, $user, $b, $user->id, $this->pintu->lingkup, $request, $this->riwayatAkun, $this->surat, $this->namaWebsite);
        $sesi->simpan(self::SESI_PESAN, "Akun {$b->email} sudah digabungkan ke akun Anda. Email itu kini bisa dipakai untuk masuk dengan kata sandi akun ini.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /**
     * Menjalankan penggabungan, mencatatnya di riwayat akun, dan memberi tahu kedua email. Dipakai juga oleh
     * VerifikasiEmailHandler (konfirmasi lewat tautan).
     */
    public static function jalankanGabung(
        GabungAkun $gabung,
        User $a,
        User $b,
        ?int $pelaku,
        string $lingkup,
        ServerRequestInterface $request,
        ?RiwayatAkun $riwayatAkun,
        ?SuratUser $surat,
        string $namaWebsite,
    ): void {
        $ringkasan = $gabung->gabung($a, $b);
        $akses     = array_column($ringkasan['akses'], 'lingkup');
        $riwayatAkun?->catat(
            RiwayatAkun::GABUNG_AKUN,
            $pelaku,
            $a->id,
            $lingkup,
            "akun {$b->email} ({$b->nama}) digabungkan" . ($akses === [] ? '' : '; akses: ' . implode(', ', $akses)),
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
        );

        if ($surat === null || ! $surat->aktif()) {
            return;
        }

        foreach (array_unique([$a->email, $b->email]) as $kepada) {
            try {
                $surat->kirimGabungSelesai($a, $b->email, $kepada, $namaWebsite);
            } catch (GagalKirim $e) {
                error_log('dapur-user: pemberitahuan penggabungan akun gagal dikirim: ' . $e->getMessage());
            }
        }
    }

    /** Apakah akun ini bisa menggabungkan akun lain lewat halaman ini (bukan admin super, tabel sudah dipasang). */
    private function bisaGabung(User $user): bool
    {
        return $this->gabung !== null
            && $this->daftar->email()->tersedia()
            && $this->daftar->profil()->tersedia()
            && $this->riwayatAkun !== null && $this->riwayatAkun->tersedia()
            && ! $this->daftar->adalahAdminSuper($user->id);
    }

    /**
     * Data bagian "Gabungkan akun" di tab Akun Saya.
     *
     * @param array<mixed> $form isian terakhir bila form gagal
     * @return array{tersedia: bool, adminSuper: bool, bisaTautan: bool, email: string, cara: string}
     */
    private function dataGabung(User $user, array $form): array
    {
        return [
            'tersedia'   => $this->bisaGabung($user),
            'adminSuper' => $this->gabung !== null && $this->daftar->adalahAdminSuper($user->id),
            'bisaTautan' => $this->bisaGantiEmail(),
            'email'      => is_string($form['email'] ?? null) ? $form['email'] : '',
            'cara'       => ($form['cara'] ?? null) === 'tautan' ? 'tautan' : 'sandi',
        ];
    }

    /** Jumlah baris dari ?jumlah=..., dibulatkan ke kelipatan JUMLAH_LANGKAH antara JUMLAH_LANGKAH dan JUMLAH_MAKS. */
    /**
     * Nama depan, tengah, dan belakang yang diisi sendiri oleh user (2.3). Kolom nama berisi gabungannya.
     *
     * @param array<mixed> $isi
     */
    private function gantiNamaLengkap(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $bagian = [];
        foreach (['nama_depan', 'nama_tengah', 'nama_belakang'] as $k) {
            $bagian[$k] = is_string($isi[$k] ?? null) ? trim($isi[$k]) : '';
        }

        $form = ['namaDepan' => $bagian['nama_depan'], 'namaTengah' => $bagian['nama_tengah'], 'namaBelakang' => $bagian['nama_belakang']];
        foreach ($bagian as $k => $nilai) {
            if (mb_strlen($nilai, 'UTF-8') > self::PANJANG_BAGIAN_NAMA || preg_match('/[\x00-\x1f\x7f]/u', $nilai) !== 0) {
                return $this->galat($request, $user, 'nama', sprintf('Setiap bagian nama paling panjang %d karakter.', self::PANJANG_BAGIAN_NAMA), 422, $form);
            }
        }

        if ($bagian['nama_depan'] === '') {
            return $this->galat($request, $user, 'nama', 'Nama depan wajib diisi.', 422, $form);
        }

        if ($this->riwayatAkun !== null && ! $this->riwayatAkun->tersedia()) {
            return $this->galat($request, $user, 'nama', RiwayatAkun::PESAN_BELUM_DIPASANG, 503, $form);
        }

        $nama = User::gabungNama($bagian['nama_depan'], $bagian['nama_tengah'], $bagian['nama_belakang']);
        if ([$user->namaDepan, $user->namaTengah, $user->namaBelakang, $user->nama] !== [...array_values($bagian), $nama]) {
            $this->daftar->gantiNamaLengkap($user->id, $bagian['nama_depan'], $bagian['nama_tengah'], $bagian['nama_belakang']);
            if ($nama !== $user->nama) {
                $this->catatAkun($request, $user, RiwayatAkun::UBAH_NAMA, $user->nama . ' → ' . $nama);
            }
        }

        $sesi->simpan(self::SESI_PESAN, 'Nama sudah disimpan.');

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /**
     * Mengganti foto akun (untuk = akun) atau foto khusus website ini (untuk = website).
     *
     * @param array<mixed> $isi
     */
    private function gantiFoto(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $website = ($isi['untuk'] ?? null) === 'website';
        $belum   = $this->belumSiapProfil($request, $user, 'foto', $website);
        if ($belum !== null) {
            return $belum;
        }

        assert($this->foto !== null);
        $file = $request->getUploadedFiles()['foto'] ?? null;
        if (! $file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->galat($request, $user, 'foto', 'Pilih file foto lebih dulu.', 422);
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->galat($request, $user, 'foto', 'Foto terlalu besar; paling besar 2 MB.', 413);
        }

        $path = $file->getError() === UPLOAD_ERR_OK ? $file->getStream()->getMetadata('uri') : null;
        if (! is_string($path) || ! is_file($path)) {
            return $this->galat($request, $user, 'foto', 'Foto gagal diunggah. Coba lagi.', 422);
        }

        try {
            $kode = $this->foto->simpan($path);
        } catch (\RuntimeException $e) {
            return $this->galat($request, $user, 'foto', $e->getMessage(), 422);
        }

        $lama = $this->kodeFoto($user, $website);
        $this->aturKodeFoto($user, $website, $kode);
        $this->foto->hapus($lama);
        $this->catatAkun($request, $user, RiwayatAkun::UBAH_FOTO, $website ? 'foto di website ini diganti' : 'foto akun diganti');
        $sesi->simpan(self::SESI_PESAN, $website ? 'Foto di website ini sudah diganti.' : 'Foto sudah diganti.');

        return new RedirectResponse($this->alamatPengaturan('profil'));
    }

    /** @param array<mixed> $isi */
    private function hapusFoto(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $website = ($isi['untuk'] ?? null) === 'website';
        $belum   = $this->belumSiapProfil($request, $user, 'foto', $website, false);
        if ($belum !== null) {
            return $belum;
        }

        $lama = $this->kodeFoto($user, $website);
        if ($lama !== '') {
            $this->aturKodeFoto($user, $website, '');
            $this->foto?->hapus($lama);
            $this->catatAkun($request, $user, RiwayatAkun::UBAH_FOTO, $website ? 'foto di website ini dihapus' : 'foto akun dihapus');
        }

        $sesi->simpan(self::SESI_PESAN, $website ? 'Foto di website ini dihapus; foto akun yang dipakai.' : 'Foto sudah dihapus.');

        return new RedirectResponse($this->alamatPengaturan('profil'));
    }

    /**
     * Status singkat yang diketik bebas, satu baris; kosong berarti status dihapus. Tidak dicatat di riwayat akun, karena
     * status bisa sering berganti.
     *
     * @param array<mixed> $isi
     */
    private function simpanStatus(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $teks = is_string($isi[ProfilUser::STATUS] ?? null) ? trim($isi[ProfilUser::STATUS]) : '';
        if (mb_strlen($teks, 'UTF-8') > ProfilUser::PANJANG_STATUS_MAKS || preg_match('/[\x00-\x1f\x7f]/u', $teks) !== 0) {
            return $this->galat($request, $user, 'status', sprintf('Status paling panjang %d karakter, satu baris.', ProfilUser::PANJANG_STATUS_MAKS), 422, ['statusAkun' => $teks]);
        }

        $belum = $this->belumSiapProfil($request, $user, 'status', false, false);
        if ($belum !== null) {
            return $belum;
        }

        $this->daftar->profil()->simpan($user->id, '', [ProfilUser::STATUS => $teks]);
        $sesi->simpan(self::SESI_PESAN, $teks === '' ? 'Status sudah dihapus.' : 'Status sudah disimpan.');

        return new RedirectResponse($this->alamatPengaturan('profil'));
    }

    /**
     * Bagian Tentang (pendidikan, lokasi, keahlian, biografi), dilihat rekan di website yang sama.
     *
     * @param array<mixed> $isi
     */
    private function simpanTentang(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $nilai = [];
        foreach (ProfilUser::TENTANG as $kunci => [$label, $maks]) {
            $teks = is_string($isi[$kunci] ?? null) ? trim(str_replace("\r\n", "\n", $isi[$kunci])) : '';
            // Biografi boleh beberapa baris; isian lain satu baris.
            $kendali = $kunci === 'biografi' ? '/[\x00-\x09\x0b-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u';
            if (mb_strlen($teks, 'UTF-8') > $maks || preg_match($kendali, $teks) !== 0) {
                return $this->galat($request, $user, 'tentang', sprintf('%s paling panjang %d karakter.', $label, $maks), 422, ['tentang' => $nilai + [$kunci => $teks, ProfilUser::LOKASI_ID => is_string($isi[ProfilUser::LOKASI_ID] ?? null) ? $isi[ProfilUser::LOKASI_ID] : '']]);
            }

            $nilai[$kunci] = $teks;
        }

        $belum = $this->belumSiapProfil($request, $user, 'tentang', false, false);
        if ($belum !== null) {
            return $belum;
        }

        $nilai[ProfilUser::LOKASI_ID] = $this->lokasiId($isi, $nilai['lokasi']);

        $berubah = $this->daftar->profil()->simpan($user->id, '', $nilai);
        // Nomor kota ikut berubah bersama teks Lokasi, jadi cukup dicatat sebagai "lokasi".
        $berubah = array_values(array_unique(array_map(
            static fn (string $k): string => $k === ProfilUser::LOKASI_ID ? 'lokasi' : $k,
            $berubah,
        )));
        if ($berubah !== []) {
            $label = array_map(static fn (string $k): string => strtolower(ProfilUser::TENTANG[$k][0]), $berubah);
            $this->catatAkun($request, $user, RiwayatAkun::UBAH_PROFIL, 'Tentang: ' . implode(', ', $label));
        }

        $sesi->simpan(self::SESI_PESAN, 'Tentang Anda sudah disimpan.');

        return new RedirectResponse($this->alamatPengaturan('profil'));
    }

    /**
     * Apakah saran kota bisa dipakai: sumbernya diatur dan tabelnya terbaca (misalnya database kota belum dipasang atau
     * akun database belum diberi hak baca). Bila tidak, isian Lokasi tampil sebagai teks biasa tanpa saran dan tanpa
     * keterangan saran.
     */
    private function lokasiSiap(): bool
    {
        if ($this->lokasi === null) {
            return false;
        }

        try {
            $this->lokasi->ambil(0);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Nomor kota yang dipilih dari saran Lokasi, hanya bila kota itu memang ada dan teks Lokasi masih sama dengan
     * teks saran (diubah sesudah memilih = Lokasi diketik bebas). Kosong bila tidak ada pilihan yang sah.
     *
     * @param array<mixed> $isi
     */
    private function lokasiId(array $isi, string $teks): string
    {
        $id = $isi[ProfilUser::LOKASI_ID] ?? null;
        if ($this->lokasi === null || $teks === '' || ! is_string($id) || preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return '';
        }

        try {
            $kota = $this->lokasi->ambil((int) $id);
        } catch (\Throwable) {
            // Tabel kota tidak terjangkau: Lokasi tetap tersimpan sebagai teks bebas.
            return '';
        }

        return $kota !== null && $kota['teks'] === $teks ? (string) $kota['id'] : '';
    }

    /**
     * Nama tampilan khusus website ini; kosong berarti memakai nama akun.
     *
     * @param array<mixed> $isi
     */
    private function simpanNamaWebsite(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $nama = is_string($isi['nama_website'] ?? null) ? trim($isi['nama_website']) : '';
        if (mb_strlen($nama, 'UTF-8') > ProfilUser::PANJANG_NAMA_MAKS || preg_match('/[\x00-\x1f\x7f]/u', $nama) !== 0) {
            return $this->galat($request, $user, 'profil_website', sprintf('Nama paling panjang %d karakter.', ProfilUser::PANJANG_NAMA_MAKS), 422, ['namaWebsite' => $nama]);
        }

        $belum = $this->belumSiapProfil($request, $user, 'profil_website', true, false);
        if ($belum !== null) {
            return $belum;
        }

        if ($this->daftar->profil()->simpan($user->id, $this->pintu->lingkup, [ProfilUser::NAMA => $nama]) !== []) {
            $this->catatAkun($request, $user, RiwayatAkun::UBAH_PROFIL, $nama === '' ? 'nama di website ini dihapus' : 'nama di website ini: ' . $nama);
        }

        $sesi->simpan(self::SESI_PESAN, $nama === '' ? 'Website ini kembali memakai nama akun Anda.' : 'Nama di website ini sudah disimpan.');

        return new RedirectResponse($this->alamatPengaturan('profil'));
    }

    /**
     * Halaman galat bila profil belum bisa dipakai: tabel belum dibuat, profil website diminta di dashboard utama, atau
     * (untuk unggah foto) folder foto belum siap.
     */
    private function belumSiapProfil(ServerRequestInterface $request, User $user, string $bagian, bool $website, bool $perluFolder = true): ?ResponseInterface
    {
        if (! $this->daftar->profil()->tersedia() || ($this->riwayatAkun !== null && ! $this->riwayatAkun->tersedia())) {
            return $this->galat($request, $user, $bagian, ProfilUser::PESAN_BELUM_DIPASANG, 503);
        }

        if ($website && $this->pintu->lingkup === Konteks::LINGKUP_UTAMA) {
            return $this->galat($request, $user, $bagian, 'Profil per website hanya bisa diatur di website itu.', 400);
        }

        if ($perluFolder && ($this->foto === null || ! FotoUser::didukung() || ! $this->foto->folderSiap())) {
            return $this->galat($request, $user, $bagian, 'Foto belum bisa diunggah: folder foto belum bisa ditulis oleh web server. Hubungi admin website.', 503);
        }

        return null;
    }

    private function kodeFoto(User $user, bool $website): string
    {
        return $website
            ? ($this->daftar->profil()->ambil($user->id, $this->pintu->lingkup)[ProfilUser::FOTO] ?? '')
            : $user->foto;
    }

    private function aturKodeFoto(User $user, bool $website, string $kode): void
    {
        if ($website) {
            $this->daftar->profil()->simpan($user->id, $this->pintu->lingkup, [ProfilUser::FOTO => $kode]);
        } else {
            $this->daftar->aturFoto($user->id, $kode);
        }
    }

    /**
     * Halaman Profil: milik sendiri, atau (dengan ?id=) milik rekan yang punya akses ke lingkup ini.
     */
    private function halamanProfil(ServerRequestInterface $request, User $user, ?string $pesan): ResponseInterface
    {
        $id     = $request->getQueryParams()['id'] ?? null;
        $target = $user;
        if (is_string($id) && $id !== '' && $id !== (string) $user->id) {
            $target = ctype_digit($id) ? $this->daftar->cariId((int) $id) : null;
            if ($target === null || $this->daftar->peran($target->id, $this->pintu->lingkup) === null) {
                return $this->halaman($request, $user, ['tidakDitemukan' => true, 'profil' => null], 404);
            }
        }

        return $this->halaman($request, $user, ['pesan' => $pesan, 'profil' => $this->dataProfil($request, $target, $target->id === $user->id)]);
    }

    /**
     * Data kartu profil dan bagian Tentang untuk template.
     *
     * @return array<string, mixed>
     */
    private function dataProfil(ServerRequestInterface $request, User $target, bool $diri): array
    {
        $lingkup   = $this->pintu->lingkup;
        $identitas = $diri ? $request->getAttribute(WajibMasukMiddleware::ATRIBUT_IDENTITAS) : null;
        $identitas = $identitas instanceof Identitas ? $identitas : Identitas::untuk($target, $lingkup, $this->daftar, $this->pintu);
        $peran     = $diri ? $this->peran($request) : $this->daftar->peran($target->id, $lingkup);
        $peran     = is_string($peran) ? ['nilai' => $peran, 'label' => DaftarUser::label($peran)] : $peran;

        $isi     = $this->daftar->profil()->ambil($target->id);
        $tentang = [];
        foreach (ProfilUser::TENTANG as $kunci => [$label]) {
            if (($isi[$kunci] ?? '') !== '') {
                $tentang[] = ['kunci' => $kunci, 'label' => $label, 'nilai' => $isi[$kunci]];
            }
        }

        return [
            'id'      => $target->id,
            'nama'    => $identitas->nama,
            'email'   => $identitas->email,
            'foto'    => $identitas->foto,
            'inisial' => $identitas->inisial,
            'peran'   => $peran,
            'aktif'   => $target->aktif,
            'tentang' => $tentang,
            'status'  => $isi[ProfilUser::STATUS] ?? '',
            'diri'    => $diri,
        ];
    }

    /**
     * Data bagian foto, nama, Tentang, dan profil di website ini pada tab Akun Saya.
     *
     * @return array<string, mixed>
     */
    private function dataAkun(User $user): array
    {
        $profil   = $this->daftar->profil();
        $tersedia = $profil->tersedia();
        $lingkup  = $this->pintu->lingkup;
        $website  = $tersedia && $lingkup !== Konteks::LINGKUP_UTAMA ? $profil->ambil($user->id, $lingkup) : null;
        $isiAkun  = $tersedia ? $profil->ambil($user->id) : [];

        return [
            'profilTersedia' => $tersedia,
            'fotoSiap'       => $tersedia && $this->foto !== null && FotoUser::didukung() && $this->foto->folderSiap(),
            'fotoAkun'       => $user->foto === '' ? null : $this->pintu->alamatFoto($user->foto),
            'inisialAkun'    => $user->inisial(),
            'namaDepan'      => $user->namaDepan,
            'namaTengah'     => $user->namaTengah,
            'namaBelakang'   => $user->namaBelakang,
            'tentang'        => $isiAkun,
            'statusAkun'     => $isiAkun[ProfilUser::STATUS] ?? '',
            'alamatLokasi'   => $tersedia && $this->lokasiSiap() ? $this->pintu->alamatLokasi() : null,
            'isianTentang'   => array_map(static fn (array $d): array => ['label' => $d[0], 'maks' => $d[1]], ProfilUser::TENTANG),
            'profilWebsite'  => $website === null ? null : [
                'nama' => $website[ProfilUser::NAMA] ?? '',
                'foto' => ($website[ProfilUser::FOTO] ?? '') === '' ? null : $this->pintu->alamatFoto($website[ProfilUser::FOTO]),
            ],
            'namaWebsite'    => $website[ProfilUser::NAMA] ?? '',
            'lingkup'        => $lingkup,
        ];
    }

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

    /** @param array<mixed> $isi */
    private function jadikanEmailUtama(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $email = $this->daftar->email();
        $id    = $this->emailId($isi);
        $baru  = $id === null || ! $email->tersedia() ? null : $email->milik($user->id, $id);
        if ($baru === null) {
            return $this->galat($request, $user, 'email_saya', 'Email tidak ditemukan di akun Anda.', 404);
        }

        if ($baru !== $user->email) {
            $email->jadikanUtama($user->id, $id);
            $this->catatAkun($request, $user, RiwayatAkun::EMAIL_UTAMA, $user->email . ' → ' . $baru);
        }

        $sesi->simpan(self::SESI_PESAN, "Email utama sekarang {$baru}.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /** @param array<mixed> $isi */
    private function hapusEmail(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $email   = $this->daftar->email();
        $id      = $this->emailId($isi);
        $dihapus = $id === null || ! $email->tersedia() ? null : $email->hapus($user->id, $id);
        if ($dihapus === null) {
            return $this->galat($request, $user, 'email_saya', 'Email utama tidak bisa dihapus. Jadikan email lain sebagai email utama lebih dulu.', 422);
        }

        $this->catatAkun($request, $user, RiwayatAkun::HAPUS_EMAIL, $dihapus);
        $sesi->simpan(self::SESI_PESAN, "{$dihapus} sudah dihapus dari akun Anda.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /**
     * Memilih email yang dipakai di website ini (yang dilihat rekan dan dipakai untuk email dari website ini).
     *
     * @param array<mixed> $isi
     */
    private function pilihEmailWebsite(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $email   = $this->daftar->email();
        $lingkup = $this->pintu->lingkup;
        if (! $email->tersedia() || ! $this->daftar->punyaAkses($user->id, $lingkup)) {
            return $this->galat($request, $user, 'email_saya', 'Email untuk website ini tidak bisa dipilih.', 422);
        }

        $id      = $this->emailId($isi);
        $dipilih = $id === null ? $user->email : $email->milik($user->id, $id);
        if ($dipilih === null) {
            return $this->galat($request, $user, 'email_saya', 'Email tidak ditemukan di akun Anda.', 404);
        }

        $adminSuper = $this->daftar->peran($user->id, $lingkup) === DaftarUser::PERAN_ADMIN_SUPER;
        if (! $this->domain->cocok($dipilih) && ! $adminSuper) {
            return $this->galat($request, $user, 'email_saya', 'Website ini hanya menerima email ' . $this->domain->teks() . '.', 422);
        }

        // Email utama disimpan sebagai "kosong", agar tetap mengikuti bila email utama nanti diganti.
        $email->aturEmailLingkup($user->id, $lingkup, $dipilih === $user->email ? null : $id);
        $this->catatAkun($request, $user, RiwayatAkun::EMAIL_WEBSITE, $dipilih);
        $sesi->simpan(self::SESI_PESAN, "Email untuk website ini sekarang {$dipilih}.");

        return new RedirectResponse($this->alamatPengaturan('akun'));
    }

    /** @param array<mixed> $isi */
    private function emailId(array $isi): ?int
    {
        $id = $isi['email_id'] ?? null;

        return is_string($id) && ctype_digit($id) && $id !== '0' ? (int) $id : null;
    }

    private function catatAkun(ServerRequestInterface $request, User $user, string $peristiwa, string $keterangan): void
    {
        $this->riwayatAkun?->catat(
            $peristiwa,
            $user->id,
            $user->id,
            $this->pintu->lingkup,
            $keterangan,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
        );
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
        $tab = in_array($bagian, self::BAGIAN_PROFIL, true) ? 'profil' : 'akun';

        return $this->halaman($request, $user, $data + ['galat' => $pesan, 'galatBagian' => $bagian, 'tab' => $tab], $status);
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
        ] + ($profil ? [] : $this->dataEmail($user) + $this->dataAkun($user) + [
            'gabungAkun' => $this->dataGabung($user, is_array($data['gabungForm'] ?? null) ? $data['gabungForm'] : []),
        ]), $status);
    }

    /**
     * Data bagian "Email saya": email milik akun, dan email yang dipakai di website ini.
     *
     * @return array<string, mixed>
     */
    private function dataEmail(User $user): array
    {
        $email   = $this->daftar->email();
        $lingkup = $this->pintu->lingkup;
        if (! $email->tersedia()) {
            return ['daftarEmail' => [], 'emailWebsiteId' => null, 'bisaPilihEmailWebsite' => false, 'domainEmail' => ''];
        }

        $adminSuper = $this->daftar->peran($user->id, $lingkup) === DaftarUser::PERAN_ADMIN_SUPER;
        $daftar     = array_map(fn (array $e): array => $e + [
            // Di website yang mewajibkan email kantor, hanya email domain itu yang bisa dipilih untuk website ini.
            'bisaDipilih' => $adminSuper || $this->domain->cocok($e['email']),
        ], $email->daftar($user->id));

        return [
            'daftarEmail'           => $daftar,
            'emailWebsiteId'        => $email->emailIdLingkup($user->id, $lingkup),
            'bisaPilihEmailWebsite' => $this->daftar->punyaAkses($user->id, $lingkup) && count($daftar) > 1,
            'domainEmail'           => $this->domain->teks(),
        ];
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
                // Email saat kejadian bila berbeda dari sekarang (mis. dari akun yang sudah digabung ke akun ini).
                'userSaat'   => $b['sasaranSaat'] ?? '',
                'oleh'       => match (true) {
                    $b['pelaku'] === null        => Perangkat::dariTerminal($b['perangkat']) ? Perangkat::ringkas($b['perangkat']) : 'Terminal',
                    $b['pelaku'] === $user->email => 'Anda',
                    default                      => 'Admin',
                },
                // Hanya untuk pelaku "Anda": email admin lain tidak ditampilkan.
                'olehSaat'   => $b['pelaku'] === $user->email ? ($b['pelakuSaat'] ?? '') : '',
            ];
        }

        return $baris;
    }

    /** @return list<array{waktu: string, peristiwa: string, label: string, email: string, lingkup: string, ip: string, perangkat: string, perangkatLengkap: string}> */
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
                'email'            => $b['email'],
            ];
        }

        return $baris;
    }
}
