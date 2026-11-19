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
 * Mengarahkan pengunjung yang belum login ke halaman masuk pintu ini. Status user, hak aksesnya ke
 * lingkup pintu, dan sidik jari kata sandinya diperiksa ulang setiap request: setelah kata sandi diganti,
 * session di perangkat lain tidak berlaku lagi.
 */
final class WajibMasukMiddleware implements MiddlewareInterface
{
    public const SESI_USER     = 'user.id';
    public const SESI_TERAKHIR = 'user.terakhir_aktif';
    /** Alamat yang diminta sebelum login; dituju lagi setelah login berhasil. */
    public const SESI_TUJUAN = 'user.tujuan';
    /** Sidik jari kata sandi saat login (User::sidikSandi()). */
    public const SESI_SANDI = 'user.sandi';

    /** Email yang dipakai untuk login di session ini (akun bisa punya beberapa email); untuk riwayat login. */
    public const SESI_EMAIL = 'user.email_masuk';

    /** Nama atribut request berisi peran user di lingkup pintu ini: 'admin_super', 'admin_web', atau 'anggota'. */
    public const ATRIBUT_PERAN = 'user.peran';

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

        $peran = $user !== null && $user->aktif && $this->sandiSama($sesi, $user)
            ? $this->daftar->peran($user->id, $this->pintu->lingkup)
            : null;

        if ($user === null || $peran === null) {
            if ($id !== null) {
                // Session tidak dihancurkan agar alamat tujuan di bawah tetap tersimpan.
                $sesi->hapus(self::SESI_USER);
                $sesi->hapus(self::SESI_TERAKHIR);
                $sesi->hapus(self::SESI_SANDI);
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

        return $handler->handle($request->withAttribute(User::class, $user)->withAttribute(self::ATRIBUT_PERAN, $peran));
    }

    /**
     * Session dari versi 1.0 belum menyimpan sidik jari; sidik jari saat ini dipakai sekali agar user tidak
     * perlu login ulang setelah pembaruan.
     */
    private function sandiSama(Sesi $sesi, User $user): bool
    {
        $sidik = $sesi->ambil(self::SESI_SANDI);
        if ($sidik === null) {
            $sesi->simpan(self::SESI_SANDI, $user->sidikSandi());

            return true;
        }

        return is_string($sidik) && hash_equals($user->sidikSandi(), $sidik);
    }
}
