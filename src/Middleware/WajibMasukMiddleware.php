<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Middleware;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\User;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Mengarahkan pengunjung yang belum login ke halaman masuk pintu ini. Status user dan hak aksesnya ke
 * lingkup pintu diperiksa ulang setiap request.
 */
final class WajibMasukMiddleware implements MiddlewareInterface
{
    public const SESI_USER     = 'user.id';
    public const SESI_TERAKHIR = 'user.terakhir_aktif';
    /** Alamat yang diminta sebelum login; dituju lagi setelah login berhasil. */
    public const SESI_TUJUAN = 'user.tujuan';

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly Pintu $pintu,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        assert($sesi instanceof Sesi);

        $id       = $sesi->ambil(self::SESI_USER);
        $terakhir = $sesi->ambil(self::SESI_TERAKHIR);
        $user     = is_int($id) && is_int($terakhir) && time() - $terakhir <= $this->pintu->batasDiam
            ? $this->daftar->cariId($id)
            : null;

        if ($user === null || ! $user->aktif || ! $this->daftar->punyaAkses($user->id, $this->pintu->lingkup)) {
            if ($id !== null) {
                // Session tidak dihancurkan agar alamat tujuan di bawah tetap tersimpan.
                $sesi->hapus(self::SESI_USER);
                $sesi->hapus(self::SESI_TERAKHIR);
                $sesi->gantiId();
            }

            if ($request->getMethod() === 'GET') {
                $uri    = $request->getUri();
                $tujuan = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
                if ($this->pintu->tujuanSah($tujuan)) {
                    $sesi->simpan(self::SESI_TUJUAN, $tujuan);
                }
            }

            return new RedirectResponse($this->pintu->alamatMasuk());
        }

        $sesi->simpan(self::SESI_TERAKHIR, time());

        return $handler->handle($request->withAttribute(User::class, $user));
    }
}
