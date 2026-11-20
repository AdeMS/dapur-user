<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\DaftarWebsite;
use AdeMS\Dapur\User\KataSandiSementara;
use AdeMS\Dapur\User\Konteks;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Perangkat;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
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
 * Halaman kelola user untuk admin satu lingkup: menambah user atau memberi akses, mengubah peran, mencabut
 * akses, serta (hanya bila diizinkan) mengaktifkan dan menonaktifkan akun. Dipasang di belakang
 * WajibMasukMiddleware; user yang bukan admin super atau admin web mendapat 403.
 *
 * Aktif dan nonaktif berlaku untuk akun di semua lingkup, jadi hanya diizinkan di dashboard utama. Admin
 * tidak bisa mengubah peran, mencabut akses, atau menonaktifkan dirinya sendiri, dan admin super terakhir
 * tidak bisa diturunkan atau dicabut, agar Dapur tidak terkunci. Di website, admin super tampil sebagai admin
 * otomatis dan hanya bisa diubah dari dashboard utama. Semua perubahan dicatat di riwayat akun.
 *
 * Bila email bisa dikirim, user baru menerima undangan berisi tautan untuk membuat kata sandinya sendiri, dan
 * undangan bisa dikirim ulang selama user itu belum pernah login. Bila email mati atau gagal terkirim, kata sandi
 * sementara ditampilkan sekali kepada admin seperti sebelumnya.
 *
 * Di dashboard utama, admin super juga bisa memberi, mengubah, dan mencabut akses user ke website lain (bila
 * DaftarWebsite diberikan), dengan batasan domain email website itu.
 */
final class KelolaUserHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::kelola-user';

    /** Kata sandi sementara user baru; ditampilkan sekali setelah pengalihan, lalu dihapus dari session. */
    public const SESI_SANDI_BARU = 'user.sandi_baru';

    public const JUMLAH_AKTIVITAS = 20;

    private const PANJANG_EMAIL_MAKS = 190;
    private const PANJANG_NAMA_MAKS  = 190;

    /**
     * Tab halaman: daftar user (di dashboard utama, admin super melihat semua akun di sini) atau aktivitas terakhir.
     */
    public const TAMPIL_AKSES     = 'akses';
    public const TAMPIL_AKTIVITAS = 'aktivitas';

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly RiwayatAkun $riwayatAkun,
        private readonly PenyajiHalaman $penyaji,
        private readonly Pintu $pintu,
        private readonly DateTimeZone $zonaWaktu,
        private readonly bool $kelolaAkun = false,
        private readonly string $template = self::TEMPLATE,
        private readonly ?TokenUser $token = null,
        private readonly ?SuratUser $surat = null,
        private readonly string $namaWebsite = 'Dapur AdeMS',
        private readonly BatasanDomain $domain = new BatasanDomain(),
        private readonly ?DaftarWebsite $daftarWebsite = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        $user = $request->getAttribute(User::class);
        assert($sesi instanceof Sesi && $user instanceof User);

        $peran = $request->getAttribute(WajibMasukMiddleware::ATRIBUT_PERAN)
            ?? $this->daftar->peran($user->id, $this->pintu->lingkup);
        if (! DaftarUser::peranAdmin(is_string($peran) ? $peran : null)) {
            return $this->penyaji->halaman($this->template, $request, ['ditolak' => true] + $this->dataKosong(), 403);
        }

        if (! $this->riwayatAkun->tersedia()) {
            return $this->penyaji->halaman(
                $this->template,
                $request,
                ['belumSiap' => RiwayatAkun::PESAN_BELUM_DIPASANG] + $this->dataKosong(),
                503,
            );
        }

        if ($request->getMethod() !== 'POST') {
            $pesan = $sesi->ambil(ProfilHandler::SESI_PESAN);
            $baru  = $sesi->ambil(self::SESI_SANDI_BARU);
            $sesi->hapus(ProfilHandler::SESI_PESAN);
            $sesi->hapus(self::SESI_SANDI_BARU);

            return $this->halaman($request, $user, [
                'pesan'     => is_string($pesan) ? $pesan : null,
                'sandiBaru' => is_array($baru) ? $baru : null,
                'tampil'    => ($request->getQueryParams()['tampil'] ?? null) === self::TAMPIL_AKTIVITAS ? self::TAMPIL_AKTIVITAS : self::TAMPIL_AKSES,
                'cari'      => self::kataCari($request->getQueryParams()['cari'] ?? null),
            ]);
        }

        $isi = (array) $request->getParsedBody();
        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->galat($request, $user, 'Halaman sudah kedaluwarsa. Silakan coba lagi.', 400);
        }

        return match ($isi['aksi'] ?? null) {
            'tambah' => $this->tambah($request, $user, $sesi, $isi),
            'peran'  => $this->ubahPeran($request, $user, $sesi, $isi),
            'cabut'  => $this->cabut($request, $user, $sesi, $isi),
            'aktif'  => $this->aturAktif($request, $user, $sesi, $isi),
            'undang' => $this->undangUlang($request, $user, $sesi, $isi),
            'lepas_email' => $this->lepasEmail($request, $user, $sesi, $isi),
            'akses_website' => $this->aksesWebsite($request, $user, $sesi, $isi),
            'cabut_website' => $this->cabutWebsite($request, $user, $sesi, $isi),
            default  => $this->galat($request, $user, 'Permintaan tidak dikenal.', 400),
        };
    }

    /** @param array<mixed> $isi */
    private function tambah(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $email = is_string($isi['email'] ?? null) ? DaftarUser::normalkanEmail($isi['email']) : '';
        $nama  = is_string($isi['nama'] ?? null) ? trim($isi['nama']) : '';
        $peran = is_string($isi['peran'] ?? null) ? $isi['peran'] : '';
        // Di dashboard utama, admin super bisa langsung menambah user untuk website lain.
        $tujuan = is_string($isi['lingkup'] ?? null) && $isi['lingkup'] !== '' ? $isi['lingkup'] : $this->pintu->lingkup;
        $form   = ['formEmail' => $email, 'formNama' => $nama, 'formPeran' => $peran, 'formLingkup' => $tujuan];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > self::PANJANG_EMAIL_MAKS) {
            return $this->galat($request, $admin, 'Email tidak sah.', 422, $form);
        }

        $lain    = $tujuan !== $this->pintu->lingkup;
        $batasan = $this->domain;
        if ($lain) {
            if (! in_array($tujuan, $this->websiteTersedia($admin), true)) {
                return $this->galat($request, $admin, 'Website tidak dikenal.', 422, $form);
            }

            $batasan = $this->batasanWebsite($tujuan);
            if ($batasan === null) {
                return $this->galat($request, $admin, "Pengaturan website {$tujuan} tidak bisa dibaca. Periksa log error.", 500, $form);
            }
        }

        if (! in_array($peran, DaftarUser::pilihanPeran($tujuan), true)) {
            return $this->galat($request, $admin, 'Peran tidak dikenal.', 422, $form);
        }

        if (! $batasan->cocok($email)) {
            return $this->galat($request, $admin, ($lain ? "Website {$tujuan}" : 'Website ini') . ' hanya menerima email ' . $batasan->teks() . '.', 422, $form);
        }

        $di = $lain ? "di {$tujuan}" : 'di sini';

        // Email bisa milik akun yang sudah ada, termasuk sebagai email tambahannya. Pesan memakai email yang
        // diketik admin, bukan email utama akun itu, agar email pribadi tidak terlihat di website lain.
        $ada = $this->daftar->cariEmail($email);
        if ($ada !== null) {
            $sekarang = $this->daftar->peran($ada->id, $tujuan);
            if ($sekarang === DaftarUser::PERAN_ADMIN_SUPER) {
                return $this->galat($request, $admin, "{$email} adalah admin super dan sudah menjadi admin {$di}.", 422, $form);
            }

            if ($sekarang !== null) {
                return $this->galat($request, $admin, "{$email} sudah punya akses {$di}.", 422, $form);
            }

            $this->daftar->tambahAkses($ada->id, $tujuan, $peran, $email);
            $this->catat($request, RiwayatAkun::BERI_AKSES, $admin, $ada->id, "peran {$peran}", $tujuan);
            $sesi->simpan(ProfilHandler::SESI_PESAN, sprintf(
                '%s sudah terdaftar; akses sebagai %s%s ditambahkan.%s',
                $email,
                strtolower(DaftarUser::label($peran)),
                $lain ? " di {$tujuan}" : '',
                $ada->aktif ? '' : ' Akun ini sedang nonaktif.',
            ));

            return new RedirectResponse($this->pintu->alamatKelolaUser());
        }

        if ($nama === '' || mb_strlen($nama, 'UTF-8') > self::PANJANG_NAMA_MAKS || preg_match('/[\x00-\x1f\x7f]/u', $nama) !== 0) {
            return $this->galat(
                $request,
                $admin,
                sprintf('Nama wajib diisi untuk user baru, paling panjang %d karakter.', self::PANJANG_NAMA_MAKS),
                422,
                $form,
            );
        }

        $sandi = KataSandiSementara::buat();
        $id    = $this->daftar->buat($email, $nama, $sandi);
        $this->daftar->tambahAkses($id, $tujuan, $peran);
        $this->catat($request, RiwayatAkun::BUAT_USER, $admin, $id, $nama, $tujuan);
        $this->catat($request, RiwayatAkun::BERI_AKSES, $admin, $id, "peran {$peran}", $tujuan);

        $baru = $this->daftar->cariId($id);
        if ($baru !== null && $this->bisaUndang()) {
            $gagal = $this->kirimUndangan($request, $admin, $baru, $lain ? $tujuan : null);
            if ($gagal === null) {
                $sesi->simpan(ProfilHandler::SESI_PESAN, "Undangan sudah dikirim ke {$email}. Tautan di dalamnya berlaku 48 jam.");

                return new RedirectResponse($this->pintu->alamatKelolaUser());
            }

            $sesi->simpan(ProfilHandler::SESI_PESAN, "Undangan gagal dikirim ({$gagal}). Berikan kata sandi sementara di bawah ini.");
        }

        $sesi->simpan(self::SESI_SANDI_BARU, ['email' => $email, 'sandi' => $sandi]);

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /** @param array<mixed> $isi */
    private function ubahPeran(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $sasaran = $this->sasaran($isi);
        $baru    = is_string($isi['peran'] ?? null) ? $isi['peran'] : '';
        if ($sasaran === null) {
            return $this->galat($request, $admin, 'User tidak ditemukan di sini.', 404);
        }

        [$target, $lama] = $sasaran;
        if (! in_array($baru, DaftarUser::pilihanPeran($this->pintu->lingkup), true)) {
            return $this->galat($request, $admin, 'Peran tidak dikenal.', 422);
        }

        if ($target->id === $admin->id) {
            return $this->galat($request, $admin, 'Anda tidak bisa mengubah peran Anda sendiri.', 422);
        }

        if ($baru === $lama) {
            return new RedirectResponse($this->pintu->alamatKelolaUser());
        }

        if ($this->adminSuperTerakhir($lama)) {
            return $this->galat($request, $admin, "{$this->emailDi($target)} adalah admin super terakhir.", 422);
        }

        $this->daftar->ubahPeran($target->id, $this->pintu->lingkup, $baru);
        $this->catat($request, RiwayatAkun::UBAH_PERAN, $admin, $target->id, "{$lama} → {$baru}");
        $sesi->simpan(ProfilHandler::SESI_PESAN, sprintf('Peran %s sekarang %s.', $this->emailDi($target), strtolower(DaftarUser::label($baru))));

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /** @param array<mixed> $isi */
    private function cabut(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $sasaran = $this->sasaran($isi);
        if ($sasaran === null) {
            return $this->galat($request, $admin, 'User tidak ditemukan di sini.', 404);
        }

        [$target, $peran] = $sasaran;
        if ($target->id === $admin->id) {
            return $this->galat($request, $admin, 'Anda tidak bisa mencabut akses Anda sendiri.', 422);
        }

        if ($this->adminSuperTerakhir($peran)) {
            return $this->galat($request, $admin, "{$this->emailDi($target)} adalah admin super terakhir.", 422);
        }

        // Email website ini diambil sebelum aksesnya dicabut (sesudahnya yang tersisa hanya email utama).
        $emailDi = $this->emailDi($target);
        $this->daftar->cabutAkses($target->id, $this->pintu->lingkup);
        $this->catat($request, RiwayatAkun::CABUT_AKSES, $admin, $target->id, "peran {$peran}");
        $sesi->simpan(ProfilHandler::SESI_PESAN, "Akses {$emailDi} dicabut. Akunnya tidak dihapus.");

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /** @param array<mixed> $isi */
    private function aturAktif(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        if (! $this->kelolaAkun) {
            return $this->galat($request, $admin, 'Akun hanya bisa diaktifkan atau dinonaktifkan dari dashboard utama.', 403);
        }

        // Admin super di dashboard utama bisa mengatur akun mana pun, termasuk akun yang hanya punya akses ke website.
        $semua   = $this->bisaLihatSemua($admin);
        $sasaran = $this->sasaran($isi);
        if ($sasaran === null && $semua) {
            $id      = is_string($isi['user_id'] ?? null) && ctype_digit($isi['user_id']) ? (int) $isi['user_id'] : 0;
            $akun    = $this->daftar->cariId($id);
            $sasaran = $akun === null ? null : [$akun, null];
        }

        if ($sasaran === null) {
            return $this->galat($request, $admin, 'User tidak ditemukan di sini.', 404);
        }

        [$target] = $sasaran;
        if ($target->id === $admin->id) {
            return $this->galat($request, $admin, 'Anda tidak bisa menonaktifkan akun Anda sendiri.', 422);
        }

        if ($this->daftar->adalahAdminSuper($target->id) && ($isi['nilai'] ?? null) !== '1'
            && $this->daftar->jumlahAdminSuper() <= 1) {
            return $this->galat($request, $admin, "{$this->emailDi($target)} adalah admin super terakhir.", 422);
        }

        $aktif = ($isi['nilai'] ?? null) === '1';
        if ($aktif !== $target->aktif) {
            $this->daftar->aturAktif($target->id, $aktif);
            $this->catat($request, $aktif ? RiwayatAkun::AKTIFKAN : RiwayatAkun::NONAKTIFKAN, $admin, $target->id);
        }

        $sesi->simpan(ProfilHandler::SESI_PESAN, $aktif
            ? "Akun {$this->emailDi($target)} aktif kembali."
            : "Akun {$this->emailDi($target)} dinonaktifkan dan tidak bisa login di mana pun.");

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /**
     * Melepas email tambahan dari sebuah akun (admin super di dashboard utama), misalnya email kantor karyawan yang
     * sudah keluar. Email itu tidak bisa dipakai login lagi; website yang memakainya kembali memakai email utama.
     * Email utama tidak bisa dilepas.
     *
     * @param array<mixed> $isi
     */
    private function lepasEmail(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        if (! $this->bisaLihatSemua($admin)) {
            return $this->galat($request, $admin, 'Email akun hanya bisa dilepas oleh admin super di dashboard utama.', 403);
        }

        $id     = is_string($isi['user_id'] ?? null) && ctype_digit($isi['user_id']) ? (int) $isi['user_id'] : 0;
        $target = $this->daftar->cariId($id);
        $email  = is_string($isi['email'] ?? null) ? DaftarUser::normalkanEmail($isi['email']) : '';
        $emails = $this->daftar->email();
        $baris  = $target === null || ! $emails->tersedia() ? null : $emails->id($target->id, $email);
        if ($target === null || $baris === null) {
            return $this->galat($request, $admin, 'Email tidak ditemukan pada akun itu.', 404);
        }

        if ($emails->hapus($target->id, $baris) === null) {
            return $this->galat($request, $admin, "{$email} adalah email utama akun itu dan tidak bisa dilepas.", 422);
        }

        $this->catat($request, RiwayatAkun::HAPUS_EMAIL, $admin, $target->id, $email);
        $sesi->simpan(ProfilHandler::SESI_PESAN, "{$email} sudah dilepas dari akun {$target->nama} dan tidak bisa dipakai login lagi.");

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /**
     * Memberi akses website kepada akun, atau mengubah peran dan email website bila aksesnya sudah ada. Hanya admin
     * super di dashboard utama. Email yang dipakai di website itu harus milik akun dan lolos batasan domain website.
     *
     * @param array<mixed> $isi
     */
    private function aksesWebsite(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $siap = $this->sasaranWebsite($request, $admin, $isi);
        if ($siap instanceof ResponseInterface) {
            return $siap;
        }

        [$target, $website, $batasan] = $siap;
        $peran = is_string($isi['peran'] ?? null) ? $isi['peran'] : '';
        if (! in_array($peran, DaftarUser::pilihanPeran($website), true)) {
            return $this->galat($request, $admin, 'Peran tidak dikenal.', 422);
        }

        // Email untuk website itu: salah satu email akun, kosong berarti email utama.
        $emailUser = $this->daftar->email();
        $pilihan   = is_string($isi['email'] ?? null) ? DaftarUser::normalkanEmail($isi['email']) : '';
        $emailId   = null;
        if ($pilihan !== '' && $pilihan !== $target->email) {
            $emailId = $emailUser->tersedia() ? $emailUser->id($target->id, $pilihan) : null;
            if ($emailId === null) {
                return $this->galat($request, $admin, 'Email itu bukan milik akun ini.', 422);
            }
        }

        $dipakai = $pilihan !== '' ? $pilihan : $target->email;
        if (! $batasan->cocok($dipakai)) {
            return $this->galat($request, $admin, sprintf(
                'Website %s hanya menerima email %s. Pilih email akun yang sesuai, atau tambahkan email itu ke akun lebih dulu.',
                $website,
                $batasan->teks(),
            ), 422);
        }

        $lama = $this->daftar->peranTersimpan($target->id, $website);
        if ($lama === null) {
            $this->daftar->tambahAkses($target->id, $website, $peran, $dipakai);
            $this->catat($request, RiwayatAkun::BERI_AKSES, $admin, $target->id, "peran {$peran}", $website);
            $pesan = sprintf('%s sekarang %s di %s.', $target->nama, strtolower(DaftarUser::label($peran)), $website);
        } else {
            $lama = DaftarUser::normalkanPeran($lama, $website);
            if ($lama !== $peran) {
                $this->daftar->ubahPeran($target->id, $website, $peran);
                $this->catat($request, RiwayatAkun::UBAH_PERAN, $admin, $target->id, "{$lama} → {$peran}", $website);
            }

            if ($emailUser->tersedia() && $emailUser->emailIdLingkup($target->id, $website) !== $emailId) {
                $emailUser->aturEmailLingkup($target->id, $website, $emailId);
                $this->catat($request, RiwayatAkun::EMAIL_WEBSITE, $admin, $target->id, $dipakai, $website);
            }

            $pesan = sprintf('Akses %s di %s disimpan: %s, email %s.', $target->nama, $website, strtolower(DaftarUser::label($peran)), $dipakai);
        }

        $sesi->simpan(ProfilHandler::SESI_PESAN, $pesan);

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /** @param array<mixed> $isi */
    private function cabutWebsite(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $siap = $this->sasaranWebsite($request, $admin, $isi, false);
        if ($siap instanceof ResponseInterface) {
            return $siap;
        }

        [$target, $website] = $siap;
        $peran = $this->daftar->peranTersimpan($target->id, $website);
        if ($peran === null) {
            return $this->galat($request, $admin, "{$target->nama} tidak punya akses ke {$website}.", 404);
        }

        $this->daftar->cabutAkses($target->id, $website);
        $this->catat($request, RiwayatAkun::CABUT_AKSES, $admin, $target->id, 'peran ' . DaftarUser::normalkanPeran($peran, $website), $website);
        $sesi->simpan(ProfilHandler::SESI_PESAN, "Akses {$target->nama} ke {$website} dicabut. Akunnya tidak dihapus.");

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /**
     * Akun dan website sasaran form akses website, beserta batasan domain website itu; atau halaman galat.
     *
     * @param array<mixed> $isi
     * @return array{0: User, 1: string, 2: BatasanDomain}|ResponseInterface
     */
    private function sasaranWebsite(ServerRequestInterface $request, User $admin, array $isi, bool $bacaBatasan = true): array|ResponseInterface
    {
        $website = is_string($isi['website'] ?? null) ? $isi['website'] : '';
        if (! in_array($website, $this->websiteTersedia($admin), true)) {
            return $this->galat($request, $admin, 'Website tidak dikenal.', $this->bisaLihatSemua($admin) ? 422 : 403);
        }

        $id     = is_string($isi['user_id'] ?? null) && ctype_digit($isi['user_id']) ? (int) $isi['user_id'] : 0;
        $target = $this->daftar->cariId($id);
        if ($target === null) {
            return $this->galat($request, $admin, 'User tidak ditemukan.', 404);
        }

        if ($this->daftar->adalahAdminSuper($target->id)) {
            return $this->galat($request, $admin, "{$target->nama} adalah admin super dan sudah menjadi admin di semua website.", 422);
        }

        $batasan = $bacaBatasan ? $this->batasanWebsite($website) : new BatasanDomain();
        if ($batasan === null) {
            return $this->galat($request, $admin, "Pengaturan website {$website} tidak bisa dibaca. Periksa log error.", 500);
        }

        return [$target, $website, $batasan];
    }

    /**
     * Website yang aksesnya bisa diatur dari halaman ini: hanya untuk admin super di dashboard utama.
     *
     * @return list<string>
     */
    private function websiteTersedia(User $admin): array
    {
        if ($this->daftarWebsite === null || ! $this->bisaLihatSemua($admin)) {
            return [];
        }

        return array_values(array_filter(
            $this->daftarWebsite->website(),
            fn (string $host): bool => $host !== $this->pintu->lingkup,
        ));
    }

    /** Batasan domain email satu website, atau null bila pengaturannya tidak bisa dibaca (dicatat ke log PHP). */
    private function batasanWebsite(string $website): ?BatasanDomain
    {
        assert($this->daftarWebsite !== null);
        try {
            return $this->daftarWebsite->batasanDomain($website);
        } catch (\Throwable $e) {
            error_log("Dapur: pengaturan user website {$website} tidak bisa dibaca: {$e->getMessage()}");

            return null;
        }
    }

    /** Kata pencarian dari ?cari=..., dirapikan dan dibatasi panjangnya. */
    private static function kataCari(mixed $nilai): string
    {
        if (! is_string($nilai)) {
            return '';
        }

        $nilai = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $nilai) ?? '');

        return mb_substr($nilai, 0, 100, 'UTF-8');
    }

    /** @param list<string> $teks */
    private static function cocok(string $cari, array $teks): bool
    {
        if ($cari === '') {
            return true;
        }

        foreach ($teks as $t) {
            if (mb_stripos($t, $cari, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /** Daftar semua akun hanya untuk admin super di halaman yang boleh mengatur akun (dashboard utama). */
    private function bisaLihatSemua(User $admin): bool
    {
        return $this->kelolaAkun && $this->daftar->adalahAdminSuper($admin->id);
    }

    /**
     * Mengirim ulang undangan kepada user yang belum pernah login, misalnya karena tautan lama sudah kedaluwarsa.
     *
     * @param array<mixed> $isi
     */
    private function undangUlang(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        if (! $this->bisaUndang()) {
            return $this->galat($request, $admin, 'Undangan tidak bisa dikirim karena pengiriman email belum diaktifkan.', 503);
        }

        $sasaran = $this->sasaran($isi);
        if ($sasaran === null) {
            return $this->galat($request, $admin, 'User tidak ditemukan di sini.', 404);
        }

        [$target] = $sasaran;
        $baris    = array_values(array_filter($this->daftar->userDiLingkup($this->pintu->lingkup), static fn (array $u): bool => $u['id'] === $target->id));
        if ($target->id === $admin->id || ! $target->aktif || ($baris[0]['terakhir_masuk'] ?? null) !== null) {
            return $this->galat($request, $admin, 'Undangan hanya untuk user aktif yang belum pernah login.', 422);
        }

        $gagal = $this->kirimUndangan($request, $admin, $target);
        if ($gagal !== null) {
            return $this->galat($request, $admin, "Undangan gagal dikirim: {$gagal}", 502);
        }

        $sesi->simpan(ProfilHandler::SESI_PESAN, "Undangan dikirim ulang ke {$this->emailDi($target)}.");

        return new RedirectResponse($this->pintu->alamatKelolaUser());
    }

    /** @return string|null pesan kegagalan, atau null bila undangan terkirim */
    private function kirimUndangan(ServerRequestInterface $request, User $admin, User $user, ?string $website = null): ?string
    {
        assert($this->token !== null && $this->surat !== null);
        // Email tujuan disimpan di token, agar riwayat login mencatat email yang dipakai saat kata sandi dibuat.
        $token = $this->token->buat($user->id, TokenUser::UNDANGAN, $this->emailDi($user));
        try {
            $this->surat->kirimUndangan(
                $user,
                $this->surat->alamatLengkap($request, $this->pintu->alamatAturSandi(), ['token' => $token]),
                $admin->nama,
                $website ?? $this->namaWebsite,
                $this->emailDi($user),
            );
        } catch (GagalKirim $e) {
            return $e->getMessage();
        }

        // Undangan ke website lain dicatat di riwayat website itu, bersama pemberian aksesnya.
        $this->catat($request, RiwayatAkun::UNDANG, $admin, $user->id, 'email', $website);

        return null;
    }

    /** Email user yang dipakai di website ini (email pilihannya untuk website ini, atau email utama). */
    private function emailDi(User $user): string
    {
        $email = $this->daftar->email();

        return $email->tersedia() ? $email->emailLingkup($user->id, $this->pintu->lingkup) : $user->email;
    }

        private function bisaUndang(): bool
    {
        return $this->token !== null && $this->surat !== null && $this->surat->aktif() && $this->token->tersedia();
    }

    /**
     * User sasaran dari form beserta perannya. Hanya user yang punya akses tersendiri ke lingkup ini yang bisa
     * diubah, agar admin satu website tidak bisa menyentuh user website lain lewat ID. Admin super di website
     * tidak bisa diubah dari sana (hanya dari dashboard utama), jadi juga tidak termasuk.
     *
     * @param array<mixed> $isi
     * @return array{0: User, 1: string}|null
     */
    private function sasaran(array $isi): ?array
    {
        $id = $isi['user_id'] ?? null;
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $lingkup = $this->pintu->lingkup;
        $user    = $this->daftar->cariId((int) $id);
        if ($user === null || ($lingkup !== Konteks::LINGKUP_UTAMA && $this->daftar->adalahAdminSuper($user->id))) {
            return null;
        }

        $peran = $this->daftar->peranTersimpan($user->id, $lingkup);

        return $peran === null ? null : [$user, $peran];
    }

    /** Apakah perubahan pada user berperan ini akan menghapus admin super terakhir. */
    private function adminSuperTerakhir(string $peran): bool
    {
        return $peran === DaftarUser::PERAN_ADMIN_SUPER && $this->daftar->jumlahAdminSuper() <= 1;
    }

    private function catat(
        ServerRequestInterface $request,
        string $peristiwa,
        User $admin,
        int $sasaranId,
        string $keterangan = '',
        ?string $lingkup = null,
    ): void {
        $this->riwayatAkun->catat(
            $peristiwa,
            $admin->id,
            $sasaranId,
            $lingkup ?? $this->pintu->lingkup,
            $keterangan,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
        );
    }

    /** @param array<string, mixed> $data */
    private function galat(ServerRequestInterface $request, User $admin, string $pesan, int $status, array $data = []): ResponseInterface
    {
        return $this->halaman($request, $admin, $data + ['galat' => $pesan], $status);
    }

    /** @param array<string, mixed> $data */
    private function halaman(ServerRequestInterface $request, User $admin, array $data, int $status = 200): ResponseInterface
    {
        $lingkup          = $this->pintu->lingkup;
        $jumlahAdminSuper = $this->daftar->jumlahAdminSuper();
        $diWebsite        = $lingkup !== Konteks::LINGKUP_UTAMA;
        $bisaUndang       = $this->bisaUndang();

        // Pencarian nama dan email. Di lingkup ini yang dicari email yang dipakai di sini, agar email pribadi user
        // (di website lain) tidak bisa ditebak dari halaman website.
        $cari   = is_string($data['cari'] ?? null) ? $data['cari'] : '';
        $daftar = [];
        $perId  = [];
        foreach ($this->daftar->userDiLingkup($lingkup) as $u) {
            $diri       = $u['id'] === $admin->id;
            $super      = $u['peran'] === DaftarUser::PERAN_ADMIN_SUPER;
            $terkunci   = $diri || ($super && ($diWebsite || $jumlahAdminSuper <= 1));
            $baris      = $u + [
                'diriSendiri'   => $diri,
                'labelPeran'    => DaftarUser::label($u['peran']),
                'bisaUbahPeran' => ! $terkunci,
                'bisaCabut'     => ! $terkunci,
                'bisaAturAktif' => $this->kelolaAkun && ! $diri && ! ($super && $u['aktif'] && $jumlahAdminSuper <= 1),
                'bisaUndang'    => $bisaUndang && ! $diri && ! $u['otomatis'] && $u['aktif'] && $u['terakhir_masuk'] === null,
                'login'         => $u['terakhir_masuk'] === null ? null : $this->waktu($u['terakhir_masuk']),
            ];
            $perId[$u['id']] = $baris;
            if (self::cocok($cari, [$u['nama'], $u['email']])) {
                $daftar[] = $baris;
            }
        }

        $aktivitas = [];
        foreach ($this->riwayatAkun->untukLingkup($lingkup, self::JUMLAH_AKTIVITAS) as $b) {
            $aktivitas[] = $b + [
                'label'            => RiwayatAkun::label($b['peristiwa']),
                'waktuSetempat'    => $this->waktu($b['waktu']),
                'perangkatRingkas' => $b['perangkat'] === '' ? '' : Perangkat::ringkas($b['perangkat']),
                'akunTerminal'     => Perangkat::akunTerminal($b['perangkat']),
            ];
        }

        // Website yang aksesnya bisa diatur dari sini, dengan batasan domain email masing-masing untuk petunjuk.
        $website = [];
        foreach ($this->websiteTersedia($admin) as $host) {
            $batasan   = $this->batasanWebsite($host);
            $website[] = ['host' => $host, 'domain' => $batasan?->domain ?? [], 'terbaca' => $batasan !== null];
        }

        $semuaAkun = null;
        $jumlahSemua = 0;
        if ($this->bisaLihatSemua($admin)) {
            $semuaAkun = [];
            foreach ($this->daftar->semuaAkun() as $a) {
                $jumlahSemua++;
                // Di Semua akun, email tambahan ikut dicari.
                if (! self::cocok($cari, [$a['nama'], ...$a['semuaEmail']])) {
                    continue;
                }

                $super       = in_array(DaftarUser::PERAN_ADMIN_SUPER, array_column($a['akses'], 'peran'), true);
                $a['akses']  = array_map(static fn (array $x): array => $x + ['labelPeran' => DaftarUser::label($x['peran'])], $a['akses']);
                // Akses ke dashboard utama (peran, cabut, undangan) diatur dari baris yang sama.
                $d           = $perId[$a['id']] ?? null;
                $semuaAkun[] = $a + [
                    'diriSendiri'   => $a['id'] === $admin->id,
                    'login'         => $a['terakhir_masuk'] === null ? null : $this->waktu($a['terakhir_masuk']),
                    'adminSuper'    => $super,
                    'bisaAturAktif' => $a['id'] !== $admin->id && ! ($super && $a['aktif'] && $jumlahAdminSuper <= 1),
                    'peran'         => $d['peran'] ?? null,
                    'bisaUbahPeran' => $d['bisaUbahPeran'] ?? false,
                    'bisaCabut'     => $d['bisaCabut'] ?? false,
                    'bisaUndang'    => $d['bisaUndang'] ?? false,
                    'bisaAturWebsite' => $website !== [] && ! $super,
                ];
            }
        }

        return $this->penyaji->halaman($this->template, $request, $data + [
            'daftarUser' => $daftar,
            'aktivitas'  => $aktivitas,
            'semuaAkun'  => $semuaAkun,
            'jumlahSemua' => $jumlahSemua,
            'websiteTersedia' => $website,
        ] + $this->dataKosong(), $status);
    }

    /**
     * Data yang selalu ada di template, agar template tidak perlu memeriksa keberadaan setiap nilai.
     *
     * @return array<string, mixed>
     */
    private function dataKosong(): array
    {
        return [
            'ditolak'       => false,
            'belumSiap'     => null,
            'pesan'         => null,
            'galat'         => null,
            'sandiBaru'     => null,
            'daftarUser'    => [],
            'aktivitas'     => [],
            'peranTersedia' => array_combine(
                DaftarUser::pilihanPeran($this->pintu->lingkup),
                array_map(DaftarUser::label(...), DaftarUser::pilihanPeran($this->pintu->lingkup)),
            ),
            'kelolaAkun'    => $this->kelolaAkun,
            'semuaAkun'     => null,
            'jumlahSemua'   => 0,
            'tampil'        => self::TAMPIL_AKSES,
            'cari'          => '',
            'lingkup'       => $this->pintu->lingkup,
            'alamatKelola'  => $this->pintu->alamatKelolaUser(),
            'zonaWaktu'     => $this->zonaWaktu->getName(),
            'formEmail'     => '',
            'formNama'      => '',
            'formPeran'     => DaftarUser::PERAN_ANGGOTA,
            'formLingkup'   => $this->pintu->lingkup,
            'websiteTersedia' => [],
            'peranWebsite'  => array_combine(DaftarUser::PERAN_WEBSITE, array_map(DaftarUser::label(...), DaftarUser::PERAN_WEBSITE)),
            'pakaiUndangan' => $this->bisaUndang(),
        ];
    }

    private function waktu(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i');
    }
}
