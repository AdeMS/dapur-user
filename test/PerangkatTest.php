<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\Perangkat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PerangkatTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function contoh(): iterable
    {
        yield 'Edge Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0',
            'Edge 157 · Windows',
        ];
        yield 'Chrome Android' => [
            'Mozilla/5.0 (Linux; Android 14; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36',
            'Chrome 157 · Android',
        ];
        yield 'Chrome iPhone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/157.0.0.0 Mobile/15E148 Safari/604.1',
            'Chrome 157 · iOS',
        ];
        yield 'Safari iPhone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            'Safari 18 · iOS',
        ];
        yield 'Safari Mac' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
            'Safari 18 · macOS',
        ];
        yield 'Firefox Linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
            'Firefox 140 · Linux',
        ];
        yield 'Opera Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 OPR/120.0.0.0',
            'Opera 120 · Windows',
        ];
        yield 'Samsung Internet' => [
            'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36',
            'Samsung Internet 28 · Android',
        ];
        yield 'curl' => ['curl/8.11.1', 'curl 8'];
        yield 'sistem saja' => ['Mozilla/5.0 (Windows NT 10.0) BrowserBaru', 'Windows'];
        yield 'tidak dikenal' => ['Browser Uji', 'Perangkat lain'];
        yield 'kosong' => ['', 'Perangkat lain'];
    }

    #[DataProvider('contoh')]
    public function testRingkas(string $userAgent, string $harapan): void
    {
        self::assertSame($harapan, Perangkat::ringkas($userAgent));
    }
}
