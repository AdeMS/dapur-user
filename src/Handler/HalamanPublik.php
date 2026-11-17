<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Merender halaman yang bisa dibuka tanpa login (lupa kata sandi, atur kata sandi, verifikasi email) dengan layout
 * pintu, seperti halaman masuk.
 *
 * Halaman ini bisa dibuka dari tautan berisi token, jadi Referrer-Policy diperketat agar token tidak terbawa ke
 * website lain lewat header Referer.
 */
final class HalamanPublik
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly Pintu $pintu,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function tampil(string $template, Sesi $sesi, array $data, int $status = 200): ResponseInterface
    {
        $html = $this->renderer->render($template, $data + [
            'layout'      => $this->pintu->layout,
            'alamatMasuk' => $this->pintu->alamatMasuk(),
            'tokenCsrf'   => (new TokenCsrf($sesi))->token(),
            'fieldCsrf'   => TokenCsrf::FIELD,
        ]);

        return (new HtmlResponse($html, $status))->withHeader('Referrer-Policy', 'no-referrer');
    }
}
