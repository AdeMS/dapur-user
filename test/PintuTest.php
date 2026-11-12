<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\ConfigProvider;
use AdeMS\Dapur\User\Pengaturan;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\TokenCsrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/BantuanUji.php';

final class PintuTest extends TestCase
{
    public function testAlamatDasarAkar(): void
    {
        $pintu = new Pintu('dashboard', '/');

        self::assertSame('/', $pintu->rute());
        self::assertSame('/masuk', $pintu->alamatMasuk());
        self::assertSame('/keluar', $pintu->alamatKeluar());
    }

    public function testAlamatDasarBertingkatDanGarisMiringAkhir(): void
    {
        $pintu = new Pintu('blog.contoh.com', '/admin/');

        self::assertSame('/admin', $pintu->rute());
        self::assertSame('/admin/masuk', $pintu->alamatMasuk());
    }

    /** @return array<string, array{mixed}> */
    public static function alamatTidakSah(): array
    {
        return [
            'kosong'        => [''],
            'tanpa miring'  => ['admin'],
            'keluar folder' => ['/../admin'],
            'tanda kurung'  => ['/{id}'],
            'bukan teks'    => [5],
        ];
    }

    #[DataProvider('alamatTidakSah')]
    public function testAlamatTidakSahDitolak(mixed $alamat): void
    {
        $this->expectException(RuntimeException::class);

        Pintu::normalkanAlamat($alamat);
    }

    public function testPengaturanBawaan(): void
    {
        $atur = new Pengaturan([]);

        self::assertSame('', $atur->tabel->awalan);
        self::assertSame(Pengaturan::RIWAYAT_HARI, $atur->riwayatHari);
        self::assertSame(0, (new Pengaturan(['riwayat_hari' => 0]))->riwayatHari);
    }

    /** @return array<string, array{mixed}> */
    public static function riwayatHariTidakSah(): array
    {
        return ['negatif' => [-1], 'teks' => ['30'], 'pecahan' => [1.5]];
    }

    #[DataProvider('riwayatHariTidakSah')]
    public function testRiwayatHariTidakSahDitolak(mixed $hari): void
    {
        $this->expectException(RuntimeException::class);

        new Pengaturan(['riwayat_hari' => $hari]);
    }

    public function testZonaWaktu(): void
    {
        self::assertSame('Asia/Jakarta', (new Pengaturan(['zona_waktu' => 'Asia/Jakarta']))->zonaWaktu->getName());
        self::assertSame(date_default_timezone_get(), (new Pengaturan([]))->zonaWaktu->getName());
    }

    /** @return array<string, array{mixed}> */
    public static function zonaTidakSah(): array
    {
        return ['tidak dikenal' => ['Asia/Medan'], 'kosong' => [''], 'bukan teks' => [7]];
    }

    #[DataProvider('zonaTidakSah')]
    public function testZonaWaktuTidakSahDitolak(mixed $zona): void
    {
        $this->expectException(RuntimeException::class);

        new Pengaturan(['zona_waktu' => $zona]);
    }

    public function testConfigProviderTanpaNilaiBawaanUser(): void
    {
        // Bawaan di sini akan menimpa 'awalan_tabel' inti saat modul dipasang untuk satu website.
        self::assertArrayNotHasKey('user', (new ConfigProvider())());
    }

    public function testTokenCsrf(): void
    {
        $sesi  = new SesiLarik();
        $csrf  = new TokenCsrf($sesi);
        $token = $csrf->token();

        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->sah($token));
        self::assertFalse($csrf->sah('salah'));
        self::assertFalse($csrf->sah(null));
        self::assertFalse($csrf->sah(['larik']));

        $csrf->baru();
        self::assertFalse($csrf->sah($token));
        self::assertNotSame($token, $csrf->token());
    }

    public function testTokenCsrfTanpaTokenTidakSah(): void
    {
        self::assertFalse((new TokenCsrf(new SesiLarik()))->sah(''));
    }
}
