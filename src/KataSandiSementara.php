<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/**
 * Kata sandi awal untuk user yang dibuat admin dari browser. Ditampilkan sekali kepada admin, lalu user
 * menggantinya sendiri di halaman Profil.
 *
 * Huruf yang mudah tertukar saat dibaca atau diketik (0/O, 1/l/I) tidak dipakai. Empat kelompok berisi
 * empat karakter, misalnya "k7mq-x3vt-9wcr-hp4n": 19 karakter, cukup untuk syarat admin.
 */
final class KataSandiSementara
{
    private const HURUF = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function buat(): string
    {
        $kelompok = [];
        for ($i = 0; $i < 4; $i++) {
            $teks = '';
            for ($j = 0; $j < 4; $j++) {
                $teks .= self::HURUF[random_int(0, strlen(self::HURUF) - 1)];
            }
            $kelompok[] = $teks;
        }

        return implode('-', $kelompok);
    }
}
