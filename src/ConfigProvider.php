<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use AdeMS\Dapur\Email\ConfigProvider as EmailConfigProvider;

/**
 * Layanan user: data user dan hak akses, login, pembatasan percobaan, riwayat login, dan session.
 *
 * Paket ini tidak mendaftarkan route sendiri. Modul yang memerlukan login (misalnya dashboard) membuat
 * Pintu, lalu memasang WajibMasukMiddleware, MasukHandler, dan KeluarHandler lewat PabrikLayanan.
 * ConfigProvider modul tersebut menyertakan isi kelas ini, jadi kelas ini tidak perlu dicantumkan lagi.
 *
 * Pengaturan $config['user'] sengaja tidak diberi nilai bawaan di sini (bawaannya ada di kelas
 * Pengaturan). Modul yang diaktifkan untuk satu website dimuat sesudah config/autoload inti, sehingga
 * nilai bawaan di sini akan menimpa pengaturan inti seperti 'awalan_tabel'.
 */
final class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        // Layanan email (adems/dapur-email) ikut dimuat, agar modul yang memakai paket ini tidak perlu
        // mencantumkannya sendiri.
        $email = (new EmailConfigProvider())();

        return [
            // Tabel modul ini dipasang oleh `php dapur pasang` (adems/dapur-database).
            'dapur'        => ['skema' => [UserSkema::class]],
            'templates'    => [
                'paths' => $email['templates']['paths'] + [
                    'user' => [dirname(__DIR__) . '/templates'],
                ],
            ],
            'dependencies' => [
                'factories' => $email['dependencies']['factories'] + array_fill_keys([
                    Pengaturan::class,
                    DaftarUser::class,
                    PembatasPercobaan::class,
                    RiwayatMasuk::class,
                    RiwayatAkun::class,
                    PengaturanUser::class,
                    TokenUser::class,
                    FotoUser::class,
                    GabungAkun::class,
                    SuratUser::class,
                    Autentikasi::class,
                    Middleware\MulaiSesiMiddleware::class,
                ], PabrikLayanan::class),
            ],
        ];
    }
}
