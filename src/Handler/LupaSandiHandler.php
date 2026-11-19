<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SuratUser;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Halaman lupa kata sandi: user mengisi email, lalu menerima tautan untuk membuat kata sandi baru.
 *
 * Jawabannya selalu sama, baik email terdaftar maupun tidak, agar orang lain tidak bisa menebak email mana yang
 * punya akun. Permintaan dibatasi seperti login gagal, agar tidak bisa dipakai untuk membanjiri kotak surat orang.
 */
final class LupaSandiHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::lupa-sandi';

    public const PESAN_TERKIRIM = 'Bila email itu terdaftar, tautan untuk membuat kata sandi baru sudah dikirim. Periksa juga folder spam.';

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly TokenUser $token,
        private readonly SuratUser $surat,
        private readonly PembatasPercobaan $pembatas,
        private readonly HalamanPublik $halaman,
        private readonly Pintu $pintu,
        private readonly string $namaWebsite,
        private readonly BatasanDomain $domain = new BatasanDomain(),
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        if (! $this->surat->aktif()) {
            return $this->tampil($sesi, ['tidakTersedia' => true], 404);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->tampil($sesi, []);
        }

        $isi   = (array) $request->getParsedBody();
        $email = is_string($isi['email'] ?? null) ? DaftarUser::normalkanEmail($isi['email']) : '';
        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->tampil($sesi, ['galat' => 'Halaman sudah kedaluwarsa. Silakan coba lagi.', 'email' => $email], 400);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            return $this->tampil($sesi, ['galat' => 'Email tidak sah.', 'email' => $email], 422);
        }

        // Kunci pembatas dibedakan dari login, agar permintaan ini tidak ikut mengunci login.
        $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $kunci = 'lupa-sandi:' . $email;
        if ($this->pembatas->terkunci($kunci, $ip)) {
            return $this->tampil($sesi, ['galat' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.', 'email' => $email], 429);
        }

        $this->pembatas->catat($kunci, $ip);

        // Tautan dikirim ke email yang diketik (bisa email tambahan akun itu). Di website yang mewajibkan email
        // kantor, email lain tidak dikirimi apa pun, kecuali milik admin super; jawabannya tetap sama.
        $user = $this->daftar->cariEmail($email);
        $peran = $user === null ? null : $this->daftar->peran($user->id, $this->pintu->lingkup);
        if (
            $user !== null && $user->aktif && $peran !== null
            && ($this->domain->cocok($email) || $peran === DaftarUser::PERAN_ADMIN_SUPER)
        ) {
            $token = $this->token->buat($user->id, TokenUser::LUPA_SANDI, $email);
            try {
                $this->surat->kirimLupaSandi(
                    $user,
                    $this->surat->alamatLengkap($request, $this->pintu->alamatAturSandi(), ['token' => $token]),
                    $this->namaWebsite,
                    $email,
                );
            } catch (GagalKirim $e) {
                // Jawaban tetap sama agar keberadaan akun tidak terbuka; kegagalan dicatat untuk admin.
                error_log('dapur-user: email lupa kata sandi gagal dikirim: ' . $e->getMessage());
            }
        }

        return $this->tampil($sesi, ['terkirim' => true]);
    }

    /** @param array<string, mixed> $data */
    private function tampil(Sesi $sesi, array $data, int $status = 200): ResponseInterface
    {
        return $this->halaman->tampil(self::TEMPLATE, $sesi, $data + [
            'tidakTersedia' => false,
            'terkirim'      => false,
            'galat'         => null,
            'email'         => '',
            'alamatLupa'    => $this->pintu->alamatLupaSandi(),
            'pesanTerkirim' => self::PESAN_TERKIRIM,
        ], $status);
    }
}
