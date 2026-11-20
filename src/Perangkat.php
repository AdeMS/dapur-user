<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/**
 * Meringkas user agent browser untuk tampilan, misalnya "Edge 157 · Windows".
 *
 * Hanya untuk dibaca manusia: teks user agent lengkap tetap disimpan apa adanya di riwayat login.
 * Pengenalan dibuat sederhana dan cukup untuk browser umum; sisanya ditampilkan sebagai "Perangkat lain".
 */
final class Perangkat
{
    public const TIDAK_DIKENAL = 'Perangkat lain';

    /**
     * Pola browser, diperiksa berurutan. Urutan penting: Edge, Opera, dan Samsung Internet juga menulis
     * "Chrome" di user agent-nya, dan Chrome juga menulis "Safari".
     *
     * @var list<array{0: string, 1: string}>
     */
    private const BROWSER = [
        ['#\bEdg(?:e|A|iOS)?/(\d+)#', 'Edge'],
        ['#\b(?:OPR|Opera)/(\d+)#', 'Opera'],
        ['#\bSamsungBrowser/(\d+)#', 'Samsung Internet'],
        ['#\b(?:Firefox|FxiOS)/(\d+)#', 'Firefox'],
        ['#\b(?:CriOS|Chrome)/(\d+)#', 'Chrome'],
        ['#\bVersion/(\d+)[^ ]* (?:Mobile/\S+ )?Safari/#', 'Safari'],
        ['#^curl/(\d+)#', 'curl'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const SISTEM = [
        ['#\bWindows NT\b#', 'Windows'],
        ['#\biPhone\b#', 'iOS'],
        ['#\biPad\b#', 'iPadOS'],
        ['#\bAndroid\b#', 'Android'],
        ['#\bCrOS\b#', 'ChromeOS'],
        ['#\bMac OS X\b#', 'macOS'],
        ['#\bLinux\b#', 'Linux'],
    ];

    /** Isi kolom perangkat untuk perubahan lewat perintah dapur-user di terminal (sebelum 2.2, tanpa nama akun). */
    public const TERMINAL = 'dapur-user (terminal)';

    /**
     * Isi kolom perangkat untuk perintah dapur-user di terminal, bersama nama akun sistem yang menjalankannya,
     * misalnya "dapur-user (terminal: ade)" atau "dapur-user (terminal: Claudia, lewat sudo dari ade)". Yang tercatat
     * adalah akun sistem server, bukan akun Dapur.
     */
    public static function terminal(): string
    {
        $akun = null;
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            $akun = is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : null;
        }

        $akun ??= get_current_user() ?: null;
        if ($akun === null || $akun === '') {
            return self::TERMINAL;
        }

        $sudo = getenv('SUDO_USER');
        if (is_string($sudo) && $sudo !== '' && $sudo !== $akun) {
            $akun .= ', lewat sudo dari ' . $sudo;
        }

        return 'dapur-user (terminal: ' . self::bersihkan($akun) . ')';
    }

    /** Nama akun sistem dari isi kolom perangkat perintah terminal, null bila bukan dari terminal atau tanpa nama. */
    public static function akunTerminal(string $perangkat): ?string
    {
        return preg_match('/^dapur-user \(terminal: (.+)\)$/', $perangkat, $cocok) === 1 ? $cocok[1] : null;
    }

    public static function dariTerminal(string $perangkat): bool
    {
        return $perangkat === self::TERMINAL || self::akunTerminal($perangkat) !== null;
    }

    private static function bersihkan(string $teks): string
    {
        return substr(preg_replace('/[^\w.@ ,-]/u', '', $teks) ?? '', 0, 120);
    }

    public static function ringkas(string $userAgent): string
    {
        if (self::dariTerminal($userAgent)) {
            $akun = self::akunTerminal($userAgent);

            return $akun === null ? 'Terminal' : "Terminal ({$akun})";
        }

        $browser = null;
        foreach (self::BROWSER as [$pola, $nama]) {
            if (preg_match($pola, $userAgent, $cocok) === 1) {
                $browser = $nama . ' ' . $cocok[1];
                break;
            }
        }

        $sistem = null;
        foreach (self::SISTEM as [$pola, $nama]) {
            if (preg_match($pola, $userAgent) === 1) {
                $sistem = $nama;
                break;
            }
        }

        $bagian = array_filter([$browser, $sistem], static fn (?string $b): bool => $b !== null);

        return $bagian === [] ? self::TIDAK_DIKENAL : implode(' · ', $bagian);
    }
}
