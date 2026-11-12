<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Merender halaman user yang tampil setelah login, misalnya Profil.
 *
 * Modul yang memasang halaman itu (misalnya dashboard) bisa memberikan penyajinya sendiri, agar halaman
 * memakai layout dan menu modul tersebut. Tanpa itu, PenyajiDasar yang dipakai.
 */
interface PenyajiHalaman
{
    /** @param array<string, mixed> $data */
    public function halaman(
        string $template,
        ServerRequestInterface $request,
        array $data = [],
        int $status = 200,
    ): ResponseInterface;
}
