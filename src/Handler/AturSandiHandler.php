<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\AturanKataSandi;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Membuat kata sandi dari tautan email: undangan user baru atau lupa kata sandi.
 *
 * Membuka tautan hanya menampilkan formulir; token baru dipakai saat formulir dikirim, sehingga pemindai tautan di
 * aplikasi email tidak menghabiskan token. Setelah kata sandi dibuat, semua session lama user itu berakhir (sidik
 * jari kata sandi berubah), lalu user diarahkan ke halaman masuk.
 */
final class AturSandiHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::atur-sandi';

    private const PANJANG_SANDI_MAKS = 1024;

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly TokenUser $token,
        private readonly RiwayatMasuk $riwayat,
        private readonly HalamanPublik $halaman,
        private readonly Pintu $pintu,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        $isi   = $request->getMethod() === 'POST' ? (array) $request->getParsedBody() : $request->getQueryParams();
        $token = is_string($isi['token'] ?? null) ? $isi['token'] : '';
        $data  = $this->token->periksa($token, [TokenUser::UNDANGAN, TokenUser::LUPA_SANDI]);
        $user  = $data === null ? null : $this->daftar->cariId($data['user_id']);

        if ($data === null || $user === null || ! $user->aktif) {
            return $this->tampil($sesi, ['tidakBerlaku' => true], 410);
        }

        $minimal = AturanKataSandi::panjangMinimal($this->daftar->adalahAdmin($user->id));
        $umum    = ['token' => $token, 'email' => $user->email, 'nama' => $user->nama, 'minimalSandi' => $minimal, 'undangan' => $data['jenis'] === TokenUser::UNDANGAN];

        if ($request->getMethod() !== 'POST') {
            return $this->tampil($sesi, $umum);
        }

        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->tampil($sesi, ['galat' => 'Halaman sudah kedaluwarsa. Silakan coba lagi.'] + $umum, 400);
        }

        $baru  = is_string($isi['sandi_baru'] ?? null) ? $isi['sandi_baru'] : '';
        $ulang = is_string($isi['sandi_ulang'] ?? null) ? $isi['sandi_ulang'] : '';
        if (strlen($baru) < $minimal || strlen($baru) > self::PANJANG_SANDI_MAKS) {
            return $this->tampil($sesi, ['galat' => sprintf('Kata sandi minimal %d karakter.', $minimal)] + $umum, 422);
        }

        if (! hash_equals($baru, $ulang)) {
            return $this->tampil($sesi, ['galat' => 'Kedua kata sandi tidak sama.'] + $umum, 422);
        }

        $this->token->pakai($data['id']);
        $this->daftar->gantiSandi($user->id, $baru);
        $this->riwayat->catat(
            RiwayatMasuk::GANTI_SANDI,
            $user->id,
            $this->pintu->lingkup,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent'),
            // Email tujuan tautan (sejak 2.2 disimpan di token); token lama tanpa email dicatat kosong.
            filter_var($data['data'], FILTER_VALIDATE_EMAIL) !== false ? $data['data'] : '',
        );

        $sesi->simpan(ProfilHandler::SESI_PESAN, 'Kata sandi sudah dibuat. Silakan masuk dengan kata sandi baru.');

        return new RedirectResponse($this->pintu->alamatMasuk());
    }

    /** @param array<string, mixed> $data */
    private function tampil(Sesi $sesi, array $data, int $status = 200): ResponseInterface
    {
        return $this->halaman->tampil(self::TEMPLATE, $sesi, $data + [
            'tidakBerlaku' => false,
            'galat'        => null,
            'token'        => '',
            'email'        => '',
            'nama'         => '',
            'minimalSandi' => AturanKataSandi::MINIMAL_ADMIN,
            'undangan'     => false,
            'alamatAtur'   => $this->pintu->alamatAturSandi(),
            'alamatLupa'   => $this->pintu->alamatLupaSandi(),
        ], $status);
    }
}
