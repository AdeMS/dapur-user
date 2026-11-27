<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\PenyimpanFoto;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\User;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Menyajikan foto profil (`<alamat dasar>/foto/<kode>`). Dipasang di belakang WajibMasukMiddleware: foto hanya
 * terlihat oleh user yang login, dan hanya foto rekan di lingkup yang sama:
 *
 * - foto sendiri selalu;
 * - foto akun milik user yang punya akses ke lingkup ini;
 * - foto khusus website hanya di website itu;
 * - admin super di dashboard utama melihat semua foto (ia melihat semua akun);
 * - foto di riwayat foto (yang sedang tidak dipakai) hanya oleh pemiliknya.
 *
 * Kode foto berubah setiap foto diganti, sehingga browser boleh menyimpannya lama.
 */
final class FotoHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly PenyimpanFoto $foto,
        private readonly Pintu $pintu,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(User::class);
        assert($user instanceof User);

        $kode    = $request->getAttribute('kode') ?? basename($request->getUri()->getPath());
        $pemilik = is_string($kode) ? $this->daftar->profil()->pemilikFoto($kode) : null;
        $file    = is_string($kode) ? $this->foto->file($kode) : null;
        $boleh   = $pemilik !== null
            ? $this->boleh($user, ...$pemilik)
            : is_string($kode) && $this->foto->pemilik($kode) === $user->id;
        if ($file === null || ! $boleh) {
            return new EmptyResponse(404);
        }

        return (new Response(new Stream($file, 'r'), 200))
            ->withHeader('Content-Type', 'image/webp')
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
    }

    private function boleh(User $user, int $pemilik, string $lingkupFoto): bool
    {
        $lingkup = $this->pintu->lingkup;

        return match (true) {
            $pemilik === $user->id                                     => true,
            $lingkupFoto !== ''                                        => $lingkupFoto === $lingkup && $this->daftar->peran($pemilik, $lingkup) !== null,
            $this->daftar->peran($pemilik, $lingkup) !== null          => true,
            default                                                    => $this->daftar->peran($user->id, $lingkup) === DaftarUser::PERAN_ADMIN_SUPER,
        };
    }
}
