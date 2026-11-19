<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarUser;
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
 */
final class KelolaUserHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::kelola-user';

    /** Kata sandi sementara user baru; ditampilkan sekali setelah pengalihan, lalu dihapus dari session. */
    public const SESI_SANDI_BARU = 'user.sandi_baru';

    public const JUMLAH_AKTIVITAS = 20;

    private const PANJANG_EMAIL_MAKS = 190;
    private const PANJANG_NAMA_MAKS  = 190;

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
            default  => $this->galat($request, $user, 'Permintaan tidak dikenal.', 400),
        };
    }

    /** @param array<mixed> $isi */
    private function tambah(ServerRequestInterface $request, User $admin, Sesi $sesi, array $isi): ResponseInterface
    {
        $email = is_string($isi['email'] ?? null) ? DaftarUser::normalkanEmail($isi['email']) : '';
        $nama  = is_string($isi['nama'] ?? null) ? trim($isi['nama']) : '';
        $peran = is_string($isi['peran'] ?? null) ? $isi['peran'] : '';
        $form  = ['formEmail' => $email, 'formNama' => $nama, 'formPeran' => $peran];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > self::PANJANG_EMAIL_MAKS) {
            return $this->galat($request, $admin, 'Email tidak sah.', 422, $form);
        }

        if (! in_array($peran, DaftarUser::pilihanPeran($this->pintu->lingkup), true)) {
            return $this->galat($request, $admin, 'Peran tidak dikenal.', 422, $form);
        }

        if (! $this->domain->cocok($email)) {
            return $this->galat($request, $admin, 'Website ini hanya menerima email ' . $this->domain->teks() . '.', 422, $form);
        }

        // Email bisa milik akun yang sudah ada, termasuk sebagai email tambahannya. Pesan memakai email yang
        // diketik admin, bukan email utama akun itu, agar email pribadi tidak terlihat di website lain.
        $ada = $this->daftar->cariEmail($email);
        if ($ada !== null) {
            $sekarang = $this->daftar->peran($ada->id, $this->pintu->lingkup);
            if ($sekarang === DaftarUser::PERAN_ADMIN_SUPER) {
                return $this->galat($request, $admin, "{$email} adalah admin super dan sudah menjadi admin di sini.", 422, $form);
            }

            if ($sekarang !== null) {
                return $this->galat($request, $admin, "{$email} sudah punya akses di sini.", 422, $form);
            }

            $this->daftar->tambahAkses($ada->id, $this->pintu->lingkup, $peran, $email);
            $this->catat($request, RiwayatAkun::BERI_AKSES, $admin, $ada->id, "peran {$peran}");
            $sesi->simpan(ProfilHandler::SESI_PESAN, sprintf(
                '%s sudah terdaftar; akses sebagai %s ditambahkan.%s',
                $email,
                strtolower(DaftarUser::label($peran)),
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
        $this->daftar->tambahAkses($id, $this->pintu->lingkup, $peran);
        $this->catat($request, RiwayatAkun::BUAT_USER, $admin, $id, $nama);
        $this->catat($request, RiwayatAkun::BERI_AKSES, $admin, $id, "peran {$peran}");

        $baru = $this->daftar->cariId($id);
        if ($baru !== null && $this->bisaUndang()) {
            $gagal = $this->kirimUndangan($request, $admin, $baru);
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

        $sasaran = $this->sasaran($isi);
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
    private function kirimUndangan(ServerRequestInterface $request, User $admin, User $user): ?string
    {
        assert($this->token !== null && $this->surat !== null);
        // Email tujuan disimpan di token, agar riwayat login mencatat email yang dipakai saat kata sandi dibuat.
        $token = $this->token->buat($user->id, TokenUser::UNDANGAN, $this->emailDi($user));
        try {
            $this->surat->kirimUndangan(
                $user,
                $this->surat->alamatLengkap($request, $this->pintu->alamatAturSandi(), ['token' => $token]),
                $admin->nama,
                $this->namaWebsite,
                $this->emailDi($user),
            );
        } catch (GagalKirim $e) {
            return $e->getMessage();
        }

        $this->catat($request, RiwayatAkun::UNDANG, $admin, $user->id, 'email');

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

    private function catat(ServerRequestInterface $request, string $peristiwa, User $admin, int $sasaranId, string $keterangan = ''): void
    {
        $this->riwayatAkun->catat(
            $peristiwa,
            $admin->id,
            $sasaranId,
            $this->pintu->lingkup,
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

        $daftar = [];
        foreach ($this->daftar->userDiLingkup($lingkup) as $u) {
            $diri       = $u['id'] === $admin->id;
            $super      = $u['peran'] === DaftarUser::PERAN_ADMIN_SUPER;
            $terkunci   = $diri || ($super && ($diWebsite || $jumlahAdminSuper <= 1));
            $daftar[]   = $u + [
                'diriSendiri'   => $diri,
                'labelPeran'    => DaftarUser::label($u['peran']),
                'bisaUbahPeran' => ! $terkunci,
                'bisaCabut'     => ! $terkunci,
                'bisaAturAktif' => $this->kelolaAkun && ! $diri && ! ($super && $u['aktif'] && $jumlahAdminSuper <= 1),
                'bisaUndang'    => $bisaUndang && ! $diri && ! $u['otomatis'] && $u['aktif'] && $u['terakhir_masuk'] === null,
                'login'         => $u['terakhir_masuk'] === null ? null : $this->waktu($u['terakhir_masuk']),
            ];
        }

        $aktivitas = [];
        foreach ($this->riwayatAkun->untukLingkup($lingkup, self::JUMLAH_AKTIVITAS) as $b) {
            $aktivitas[] = $b + [
                'label'            => RiwayatAkun::label($b['peristiwa']),
                'waktuSetempat'    => $this->waktu($b['waktu']),
                'perangkatRingkas' => $b['perangkat'] === '' ? '' : Perangkat::ringkas($b['perangkat']),
            ];
        }

        return $this->penyaji->halaman($this->template, $request, $data + [
            'daftarUser' => $daftar,
            'aktivitas'  => $aktivitas,
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
            'lingkup'       => $this->pintu->lingkup,
            'alamatKelola'  => $this->pintu->alamatKelolaUser(),
            'zonaWaktu'     => $this->zonaWaktu->getName(),
            'formEmail'     => '',
            'formNama'      => '',
            'formPeran'     => DaftarUser::PERAN_ANGGOTA,
            'pakaiUndangan' => $this->bisaUndang(),
        ];
    }

    private function waktu(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i');
    }
}
