<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Penyaji bawaan: layout dari pintu, ditambah data yang dibutuhkan menu user (template user::menu-user).
 */
final class PenyajiDasar implements PenyajiHalaman
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly Pintu $pintu,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function halaman(
        string $template,
        ServerRequestInterface $request,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        return new HtmlResponse($this->renderer->render($template, $data + [
            'layout'       => $this->pintu->layout,
            'user'         => $request->getAttribute(User::class),
            'alamatKeluar' => $this->pintu->alamatKeluar(),
            'alamatProfil' => $this->pintu->alamatProfil(),
            'tokenCsrf'    => (new TokenCsrf($sesi))->token(),
            'fieldCsrf'    => TokenCsrf::FIELD,
        ]), $status);
    }
}
