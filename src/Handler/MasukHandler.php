<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\Autentikasi;
use AdeMS\Dapur\User\HasilMasuk;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Halaman dan proses login untuk satu pintu. */
final class MasukHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::masuk';

    public function __construct(
        private readonly Autentikasi $autentikasi,
        private readonly TemplateRendererInterface $renderer,
        private readonly Pintu $pintu,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        if ($request->getMethod() !== 'POST') {
            return $sesi->ambil(WajibMasukMiddleware::SESI_USER) !== null
                ? new RedirectResponse($this->pintu->rute())
                : $this->halaman($sesi, null, '');
        }

        $isi   = (array) $request->getParsedBody();
        $email = is_string($isi['email'] ?? null) ? $isi['email'] : '';
        $csrf  = new TokenCsrf($sesi);

        if (! $csrf->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->halaman($sesi, 'Halaman sudah kedaluwarsa. Silakan coba lagi.', $email, 400);
        }

        $hasil = $this->autentikasi->masuk(
            $email,
            is_string($isi['kata_sandi'] ?? null) ? $isi['kata_sandi'] : '',
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $this->pintu->lingkup,
            $request->getHeaderLine('User-Agent'),
        );

        if ($hasil->status === HasilMasuk::DIBATASI) {
            return $this->halaman($sesi, 'Terlalu banyak percobaan. Silakan coba lagi nanti.', $email, 429);
        }

        if ($hasil->status !== HasilMasuk::BERHASIL || $hasil->user === null) {
            return $this->halaman($sesi, 'Email atau kata sandi salah.', $email, 401);
        }

        $tujuan = $sesi->ambil(WajibMasukMiddleware::SESI_TUJUAN);
        $sesi->hapus(WajibMasukMiddleware::SESI_TUJUAN);

        $sesi->gantiId();
        $csrf->baru();
        $sesi->simpan(WajibMasukMiddleware::SESI_USER, $hasil->user->id);
        $sesi->simpan(WajibMasukMiddleware::SESI_TERAKHIR, time());
        $sesi->simpan(WajibMasukMiddleware::SESI_SANDI, $hasil->user->sidikSandi());

        return new RedirectResponse($this->pintu->tujuanSah($tujuan) ? $tujuan : $this->pintu->rute());
    }

    private function halaman(Sesi $sesi, ?string $pesan, string $email, int $status = 200): ResponseInterface
    {
        return new HtmlResponse($this->renderer->render(self::TEMPLATE, [
            'layout'      => $this->pintu->layout,
            'pesan'       => $pesan,
            'email'       => $email,
            'alamatMasuk' => $this->pintu->alamatMasuk(),
            'tokenCsrf'   => (new TokenCsrf($sesi))->token(),
            'fieldCsrf'   => TokenCsrf::FIELD,
        ]), $status);
    }
}
