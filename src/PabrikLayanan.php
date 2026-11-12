<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use AdeMS\Dapur\User\Handler\KeluarHandler;
use AdeMS\Dapur\User\Handler\MasukHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Middleware\MulaiSesiMiddleware;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use Laminas\Db\Adapter\Adapter;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Satu factory untuk semua layanan paket; berupa kelas agar konfigurasi tetap bisa di-cache.
 *
 * Metode statis di bawah dipakai modul lain untuk membuat middleware dan handler login bagi pintunya.
 */
final class PabrikLayanan
{
    public function __invoke(ContainerInterface $c, string $nama): object
    {
        return match ($nama) {
            Pengaturan::class => new Pengaturan(self::config($c)),
            DaftarUser::class => new DaftarUser($c->get(Adapter::class), self::tabel($c)),
            PembatasPercobaan::class => new PembatasPercobaan($c->get(Adapter::class), self::tabel($c)),
            RiwayatMasuk::class => new RiwayatMasuk(
                $c->get(Adapter::class),
                self::tabel($c),
                $c->get(Pengaturan::class)->riwayatHari,
            ),
            Autentikasi::class => new Autentikasi(
                $c->get(DaftarUser::class),
                $c->get(PembatasPercobaan::class),
                $c->get(RiwayatMasuk::class),
            ),
            MulaiSesiMiddleware::class => new MulaiSesiMiddleware(Konteks::folderSesi()),
            default => throw new RuntimeException("Layanan user tidak dikenal: {$nama}"),
        };
    }

    public static function wajibMasuk(ContainerInterface $c, Pintu $pintu): WajibMasukMiddleware
    {
        return new WajibMasukMiddleware($c->get(DaftarUser::class), $pintu);
    }

    public static function masuk(ContainerInterface $c, Pintu $pintu): MasukHandler
    {
        return new MasukHandler($c->get(Autentikasi::class), $c->get(TemplateRendererInterface::class), $pintu);
    }

    public static function keluar(ContainerInterface $c, Pintu $pintu): KeluarHandler
    {
        return new KeluarHandler($pintu, $c->get(RiwayatMasuk::class));
    }

    /**
     * Halaman profil. Berikan penyaji milik modul agar halaman memakai menu modul tersebut; tanpa penyaji,
     * halaman memakai layout pintu dengan menu user saja.
     */
    public static function profil(
        ContainerInterface $c,
        Pintu $pintu,
        ?PenyajiHalaman $penyaji = null,
        string $template = ProfilHandler::TEMPLATE,
    ): ProfilHandler {
        return new ProfilHandler(
            $c->get(DaftarUser::class),
            $c->get(PembatasPercobaan::class),
            $c->get(RiwayatMasuk::class),
            $penyaji ?? new PenyajiDasar($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            $c->get(Pengaturan::class)->zonaWaktu,
            $template,
        );
    }

    /** @return array<string, mixed> */
    private static function config(ContainerInterface $c): array
    {
        $config = $c->get('config')['user'] ?? [];

        return is_array($config) ? $config : [];
    }

    private static function tabel(ContainerInterface $c): NamaTabel
    {
        return $c->get(Pengaturan::class)->tabel;
    }
}
