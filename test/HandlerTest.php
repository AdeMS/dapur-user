<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\Autentikasi;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Handler\KeluarHandler;
use AdeMS\Dapur\User\Handler\MasukHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

/** Renderer tiruan yang mengingat data template terakhir. */
final class RendererUji implements TemplateRendererInterface
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function render(string $name, $params = []): string
    {
        $this->data = (array) $params;

        return $name;
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
    }

    /** @return TemplatePath[] */
    public function getPaths(): array
    {
        return [];
    }

    public function addDefaultParam(string $templateName, string $param, mixed $value): void
    {
    }
}

final class HandlerTest extends TestCase
{
    private const SANDI = 'kata-sandi-yang-panjang';

    private Adapter $db;
    private RiwayatMasuk $riwayat;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private int $id;

    protected function setUp(): void
    {
        $this->db       = DatabaseUji::baru();
        $daftar         = new DaftarUser($this->db);
        $this->riwayat  = new RiwayatMasuk($this->db);
        $this->renderer = new RendererUji();
        $this->sesi     = new SesiLarik();
        $this->pintu    = new Pintu('dashboard', '/dashboard', 3600, 'layout::uji');
        $this->id       = $daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $daftar->tambahAkses($this->id, 'dashboard');
    }

    private function handlerMasuk(): MasukHandler
    {
        $daftar = new DaftarUser($this->db);

        return new MasukHandler(
            new Autentikasi($daftar, new PembatasPercobaan($this->db), $this->riwayat),
            $this->renderer,
            $this->pintu,
        );
    }

    /** @param array<string, string> $isi */
    private function post(string $alamat, array $isi): ServerRequest
    {
        return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], $alamat, 'POST', 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withParsedBody($isi)
            ->withAttribute(Sesi::class, $this->sesi);
    }

    public function testHalamanMasukMemakaiLayoutDanAlamatPintu(): void
    {
        $response = $this->handlerMasuk()->handle((new ServerRequest([], [], '/dashboard/masuk', 'GET'))->withAttribute(Sesi::class, $this->sesi));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('layout::uji', $this->renderer->data['layout']);
        self::assertSame('/dashboard/masuk', $this->renderer->data['alamatMasuk']);
    }

    public function testMasukBerhasilMencatatPerangkatDanKembaliKeTujuan(): void
    {
        $token = (new TokenCsrf($this->sesi))->token();
        $this->sesi->simpan(WajibMasukMiddleware::SESI_TUJUAN, '/dashboard/laporan');

        $response = $this->handlerMasuk()->handle($this->post('/dashboard/masuk', [
            TokenCsrf::FIELD => $token,
            'email'          => 'admin@contoh.com',
            'kata_sandi'     => self::SANDI,
        ]));

        self::assertSame('/dashboard/laporan', $response->getHeaderLine('Location'));
        self::assertSame($this->id, $this->sesi->ambil(WajibMasukMiddleware::SESI_USER));
        self::assertSame('Browser Uji', $this->riwayat->untukUser($this->id)[0]['perangkat']);
    }

    public function testKeluarMencatatRiwayat(): void
    {
        $token   = (new TokenCsrf($this->sesi))->token();
        $request = $this->post('/dashboard/keluar', [TokenCsrf::FIELD => $token])
            ->withAttribute(User::class, (new DaftarUser($this->db))->cariId($this->id));

        $response = (new KeluarHandler($this->pintu, $this->riwayat))->handle($request);

        self::assertSame('/dashboard/masuk', $response->getHeaderLine('Location'));
        self::assertTrue($this->sesi->hancur);
        self::assertSame(RiwayatMasuk::KELUAR, $this->riwayat->untukUser($this->id)[0]['peristiwa']);
    }

    public function testKeluarTanpaTokenTidakMengeluarkan(): void
    {
        $response = (new KeluarHandler($this->pintu, $this->riwayat))->handle($this->post('/dashboard/keluar', []));

        self::assertSame('/dashboard', $response->getHeaderLine('Location'));
        self::assertFalse($this->sesi->hancur);
        self::assertSame([], $this->riwayat->untukUser($this->id));
    }
}
