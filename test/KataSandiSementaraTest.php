<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\AturanKataSandi;
use AdeMS\Dapur\User\KataSandiSementara;
use PHPUnit\Framework\TestCase;

final class KataSandiSementaraTest extends TestCase
{
    public function testBentukDanPanjangCukupUntukAdmin(): void
    {
        $sandi = KataSandiSementara::buat();

        self::assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{4}(-[a-hjkmnp-z2-9]{4}){3}$/', $sandi);
        self::assertGreaterThanOrEqual(AturanKataSandi::MINIMAL_ADMIN, strlen($sandi));
    }

    public function testSetiapKataSandiBerbeda(): void
    {
        $semua = [];
        for ($i = 0; $i < 50; $i++) {
            $semua[] = KataSandiSementara::buat();
        }

        self::assertCount(50, array_unique($semua));
    }
}
