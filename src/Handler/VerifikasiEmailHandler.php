<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SuratUser;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Konfirmasi email baru dari tautan verifikasi. Membuka tautan hanya menampilkan tombol konfirmasi; email baru
 * berlaku setelah tombol ditekan, agar pemindai tautan di aplikasi email tidak mengganti email tanpa disengaja.
 */
final class VerifikasiEmailHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::verifikasi-email';

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly TokenUser $token,
        private readonly SuratUser $surat,
        private readonly RiwayatAkun $riwayatAkun,
        private readonly HalamanPublik $halaman,
        private readonly Pintu $pintu,
        private readonly string $namaWebsite,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        $isi   = $request->getMethod() === 'POST' ? (array) $request->getParsedBody() : $request->getQueryParams();
        $token = is_string($isi['token'] ?? null) ? $isi['token'] : '';
        $data  = $this->token->periksa($token, [TokenUser::GANTI_EMAIL, TokenUser::TAMBAH_EMAIL]);
        $user  = $data === null ? null : $this->daftar->cariId($data['user_id']);

        if ($data === null || $user === null || ! $user->aktif || filter_var($data['data'], FILTER_VALIDATE_EMAIL) === false) {
            return $this->tampil($sesi, ['tidakBerlaku' => true], 410);
        }

        $emailBaru = $data['data'];
        $tambah    = $data['jenis'] === TokenUser::TAMBAH_EMAIL;
        $umum      = ['token' => $token, 'emailBaru' => $emailBaru, 'emailLama' => $user->email, 'tambah' => $tambah];

        if ($request->getMethod() !== 'POST') {
            return $this->tampil($sesi, $umum);
        }

        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->tampil($sesi, ['galat' => 'Halaman sudah kedaluwarsa. Silakan coba lagi.'] + $umum, 400);
        }

        $pemilik = $this->daftar->cariEmail($emailBaru);
        if ($pemilik !== null && $pemilik->id !== $user->id) {
            return $this->tampil($sesi, ['galat' => "{$emailBaru} sudah dipakai akun lain."] + $umum, 409);
        }

        $this->token->pakai($data['id']);

        if ($tambah) {
            // Email tambahan: akun tetap memakai email utamanya; email baru bisa dipakai untuk login dan dipilih
            // untuk website tertentu.
            if ($pemilik === null) {
                $this->daftar->email()->tambah($user->id, $emailBaru);
            }
            $this->riwayatAkun->catat(
                RiwayatAkun::TAMBAH_EMAIL,
                $user->id,
                $user->id,
                $this->pintu->lingkup,
                $emailBaru,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                $request->getHeaderLine('User-Agent'),
            );
            $sesi->simpan(ProfilHandler::SESI_PESAN, "{$emailBaru} sudah ditambahkan ke akun Anda dan bisa dipakai untuk masuk.");

            return new RedirectResponse($this->pintu->alamatPengaturan() . '?tab=akun');
        }

        $this->daftar->gantiEmail($user->id, $emailBaru);
        $this->riwayatAkun->catat(
            RiwayatAkun::UBAH_EMAIL,
            $user->id,
            $user->id,
            $this->pintu->lingkup,
            $user->email . ' → ' . $emailBaru,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
        );

        try {
            $this->surat->kirimPemberitahuanEmail($user, $emailBaru, true, $this->namaWebsite);
        } catch (GagalKirim $e) {
            error_log('dapur-user: pemberitahuan perubahan email gagal dikirim: ' . $e->getMessage());
        }

        $sesi->simpan(ProfilHandler::SESI_PESAN, "Email akun sekarang {$emailBaru}. Gunakan email ini untuk masuk.");

        return new RedirectResponse($this->pintu->alamatProfil());
    }

    /** @param array<string, mixed> $data */
    private function tampil(Sesi $sesi, array $data, int $status = 200): ResponseInterface
    {
        return $this->halaman->tampil(self::TEMPLATE, $sesi, $data + [
            'tidakBerlaku'     => false,
            'galat'            => null,
            'token'            => '',
            'emailBaru'        => '',
            'emailLama'        => '',
            'tambah'           => false,
            'alamatVerifikasi' => $this->pintu->alamatVerifikasiEmail(),
        ], $status);
    }
}
