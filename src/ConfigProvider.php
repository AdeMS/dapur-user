<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

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
        return [
            'templates'    => [
                'paths' => [
                    'user' => [dirname(__DIR__) . '/templates'],
                ],
            ],
            'dependencies' => [
                'factories' => array_fill_keys([
                    Pengaturan::class,
                    DaftarUser::class,
                    PembatasPercobaan::class,
                    RiwayatMasuk::class,
                    Autentikasi::class,
                    Middleware\MulaiSesiMiddleware::class,
                ], PabrikLayanan::class),
            ],
        ];
    }
}
