<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use AdeMS\Dapur\Email\PengirimEmail;
use AdeMS\Dapur\Email\SuratTemplat;
use AdeMS\Dapur\User\Handler\AturSandiHandler;
use AdeMS\Dapur\User\Handler\HalamanPublik;
use AdeMS\Dapur\User\Handler\KelolaUserHandler;
use AdeMS\Dapur\User\Handler\KeluarHandler;
use AdeMS\Dapur\User\Handler\LupaSandiHandler;
use AdeMS\Dapur\User\Handler\MasukHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Handler\VerifikasiEmailHandler;
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
            RiwayatAkun::class => new RiwayatAkun($c->get(Adapter::class), self::tabel($c)),
            PengaturanUser::class => new PengaturanUser($c->get(Adapter::class), self::tabel($c)),
            TokenUser::class => new TokenUser($c->get(Adapter::class), self::tabel($c)),
            SuratUser::class => new SuratUser(
                $c->get(PengirimEmail::class),
                $c->get(SuratTemplat::class),
                $c->get(Pengaturan::class)->alamatWebsite,
            ),
            Autentikasi::class => new Autentikasi(
                $c->get(DaftarUser::class),
                $c->get(PembatasPercobaan::class),
                $c->get(RiwayatMasuk::class),
                $c->get(Pengaturan::class)->domainEmail,
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
        return new MasukHandler($c->get(Autentikasi::class), $c->get(TemplateRendererInterface::class), $pintu, $c->get(SuratUser::class));
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
            $c->get(RiwayatAkun::class),
            $c->get(TokenUser::class),
            $c->get(SuratUser::class),
            self::namaWebsite($c),
        );
    }

    /**
     * Halaman Pengaturan & Privasi (kata sandi, email, riwayat masuk). Penyaji seperti pada profil().
     */
    public static function pengaturan(
        ContainerInterface $c,
        Pintu $pintu,
        ?PenyajiHalaman $penyaji = null,
        string $template = ProfilHandler::TEMPLATE_PENGATURAN,
    ): ProfilHandler {
        return new ProfilHandler(
            $c->get(DaftarUser::class),
            $c->get(PembatasPercobaan::class),
            $c->get(RiwayatMasuk::class),
            $penyaji ?? new PenyajiDasar($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            $c->get(Pengaturan::class)->zonaWaktu,
            $template,
            $c->get(RiwayatAkun::class),
            $c->get(TokenUser::class),
            $c->get(SuratUser::class),
            self::namaWebsite($c),
            ProfilHandler::HALAMAN_PENGATURAN,
            $c->get(Pengaturan::class)->domainEmail,
        );
    }

    /**
     * Halaman kelola user untuk admin pintu ini.
     *
     * @param bool $kelolaAkun izinkan aktif/nonaktif akun; akun berlaku di semua lingkup, jadi sebaiknya
     *                         hanya untuk dashboard utama
     */
    public static function kelolaUser(
        ContainerInterface $c,
        Pintu $pintu,
        ?PenyajiHalaman $penyaji = null,
        string $template = KelolaUserHandler::TEMPLATE,
        bool $kelolaAkun = false,
    ): KelolaUserHandler {
        return new KelolaUserHandler(
            $c->get(DaftarUser::class),
            $c->get(RiwayatAkun::class),
            $penyaji ?? new PenyajiDasar($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            $c->get(Pengaturan::class)->zonaWaktu,
            $kelolaAkun,
            $template,
            $c->get(TokenUser::class),
            $c->get(SuratUser::class),
            self::namaWebsite($c),
            $c->get(Pengaturan::class)->domainEmail,
        );
    }

    /** Halaman lupa kata sandi (tanpa login). */
    public static function lupaSandi(ContainerInterface $c, Pintu $pintu): LupaSandiHandler
    {
        return new LupaSandiHandler(
            $c->get(DaftarUser::class),
            $c->get(TokenUser::class),
            $c->get(SuratUser::class),
            $c->get(PembatasPercobaan::class),
            new HalamanPublik($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            self::namaWebsite($c),
            $c->get(Pengaturan::class)->domainEmail,
        );
    }

    /** Halaman membuat kata sandi dari tautan undangan atau lupa kata sandi (tanpa login). */
    public static function aturSandi(ContainerInterface $c, Pintu $pintu): AturSandiHandler
    {
        return new AturSandiHandler(
            $c->get(DaftarUser::class),
            $c->get(TokenUser::class),
            $c->get(RiwayatMasuk::class),
            new HalamanPublik($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
        );
    }

    /** Halaman konfirmasi email baru dari tautan verifikasi (tanpa login). */
    public static function verifikasiEmail(ContainerInterface $c, Pintu $pintu): VerifikasiEmailHandler
    {
        return new VerifikasiEmailHandler(
            $c->get(DaftarUser::class),
            $c->get(TokenUser::class),
            $c->get(SuratUser::class),
            $c->get(RiwayatAkun::class),
            new HalamanPublik($c->get(TemplateRendererInterface::class), $pintu),
            $pintu,
            self::namaWebsite($c),
        );
    }

    /** Nama website untuk subjek dan isi email: judul website, atau "Dapur AdeMS". */
    public static function namaWebsite(ContainerInterface $c): string
    {
        $website = $c->get('config')['website'] ?? [];
        $judul   = is_array($website) ? ($website['judul'] ?? null) : null;

        return is_string($judul) && trim($judul) !== '' ? trim($judul) : 'Dapur AdeMS';
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
