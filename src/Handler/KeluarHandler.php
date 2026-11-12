<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Mengakhiri login; hanya menerima POST dengan token CSRF yang sah. Dipasang di belakang WajibMasukMiddleware. */
final class KeluarHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Pintu $pintu,
        private readonly ?RiwayatMasuk $riwayat = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        $isi = (array) $request->getParsedBody();
        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return new RedirectResponse($this->pintu->rute());
        }

        $user = $request->getAttribute(User::class);
        if ($user instanceof User) {
            $this->riwayat?->catat(
                RiwayatMasuk::KELUAR,
                $user->id,
                $this->pintu->lingkup,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                $request->getHeaderLine('User-Agent'),
            );
        }

        $sesi->hancurkan();

        return new RedirectResponse($this->pintu->alamatMasuk());
    }
}
