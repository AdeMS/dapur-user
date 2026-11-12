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

    public static function ringkas(string $userAgent): string
    {
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
