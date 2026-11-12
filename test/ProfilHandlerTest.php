<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/BantuanUji.php';

final class ProfilHandlerTest extends TestCase
{
    private const SANDI = 'kata-sandi-yang-panjang';
    private const BARU  = 'kata-sandi-baru-yang-panjang';

    private Adapter $db;
    private DaftarUser $daftar;
    private RiwayatMasuk $riwayat;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private int $id;

    protected function setUp(): void
    {
        $this->db       = DatabaseUji::baru();
        $this->daftar   = new DaftarUser($this->db);
        $this->riwayat  = new RiwayatMasuk($this->db);
        $this->renderer = new RendererUji();
        $this->sesi     = new SesiLarik();
        $this->pintu    = new Pintu('dashboard', '/dashboard', 3600, 'layout::uji');
        $this->id       = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($this->id, 'dashboard');
    }

    private function handler(): ProfilHandler
    {
        return new ProfilHandler(
            $this->daftar,
            new PembatasPercobaan($this->db),
            $this->riwayat,
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
        );
    }

    private function user(): User
    {
        $user = $this->daftar->cariId($this->id);
        assert($user !== null);

        return $user;
    }

    private function get(): ResponseInterface
    {
        return $this->handler()->handle(
            (new ServerRequest([], [], '/dashboard/profil', 'GET'))
                ->withAttribute(Sesi::class, $this->sesi)
                ->withAttribute(User::class, $this->user()),
        );
    }

    /** @param array<string, string> $isi */
    private function post(array $isi, bool $denganToken = true): ResponseInterface
    {
        if ($denganToken) {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        return $this->handler()->handle(
            (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/dashboard/profil', 'POST', 'php://memory', ['User-Agent' => 'Browser Uji']))
                ->withParsedBody($isi)
                ->withAttribute(Sesi::class, $this->sesi)
                ->withAttribute(User::class, $this->user()),
        );
    }

    private function gantiSandi(string $lama = self::SANDI, string $baru = self::BARU, ?string $ulang = null): ResponseInterface
    {
        return $this->post(['aksi' => 'sandi', 'sandi_lama' => $lama, 'sandi_baru' => $baru, 'sandi_ulang' => $ulang ?? $baru]);
    }

    public function testHalamanMenampilkanDataDanRiwayatDenganWaktuSetempat(): void
    {
        $this->db->query(
            'INSERT INTO "user_riwayat_masuk" (user_id, lingkup, peristiwa, ip, perangkat, waktu) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->id, 'dashboard', 'masuk', '192.0.2.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/157.0.0.0 Safari/537.36 Edg/157.0.0.0', '2026-11-12 06:44:32'],
        );

        $response = $this->get();
        $data     = $this->renderer->data;

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('layout::uji', $data['layout']);
        self::assertSame('/dashboard/profil', $data['alamatProfil']);
        self::assertSame('/dashboard/keluar', $data['alamatKeluar']);
        self::assertSame('Admin', $data['nama']);
        self::assertSame(12, $data['minimalSandi']);
        self::assertSame('Asia/Jakarta', $data['zonaWaktu']);
        self::assertSame('2026-11-12 13:44', $data['riwayat'][0]['waktu']);
        self::assertSame('Masuk', $data['riwayat'][0]['label']);
        self::assertSame('Edge 157 · Windows', $data['riwayat'][0]['perangkat']);
    }

    public function testGantiNama(): void
    {
        $response = $this->post(['aksi' => 'nama', 'nama' => '  Ade M Saragih  ']);

        self::assertSame('/dashboard/profil', $response->getHeaderLine('Location'));
        self::assertSame('Ade M Saragih', $this->user()->nama);

        $this->get();
        self::assertSame('Nama sudah disimpan.', $this->renderer->data['pesan']);

        $this->get();
        self::assertNull($this->renderer->data['pesan'], 'Pesan hanya tampil sekali.');
    }

    public function testNamaKosongDitolak(): void
    {
        $response = $this->post(['aksi' => 'nama', 'nama' => '   ']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('nama', $this->renderer->data['galatBagian']);
        self::assertSame('Admin', $this->user()->nama);
    }

    public function testTanpaTokenDitolak(): void
    {
        $response = $this->post(['aksi' => 'nama', 'nama' => 'Lain'], false);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Admin', $this->user()->nama);
    }

    public function testGantiSandiBerhasil(): void
    {
        $sidikLama = $this->user()->sidikSandi();
        $this->sesi->simpan(WajibMasukMiddleware::SESI_SANDI, $sidikLama);

        $response = $this->gantiSandi();
        $user     = $this->user();

        self::assertSame('/dashboard/profil', $response->getHeaderLine('Location'));
        self::assertTrue(password_verify(self::BARU, $user->kataSandi));
        self::assertSame($user->sidikSandi(), $this->sesi->ambil(WajibMasukMiddleware::SESI_SANDI));
        self::assertNotSame($sidikLama, $user->sidikSandi());
        self::assertSame(1, $this->sesi->gantiId);
        self::assertSame(RiwayatMasuk::GANTI_SANDI, $this->riwayat->untukUser($this->id)[0]['peristiwa']);
    }

    public function testSesiPerangkatLainBerakhirSetelahGantiSandi(): void
    {
        $lain = new SesiLarik();
        $lain->simpan(WajibMasukMiddleware::SESI_USER, $this->id);
        $lain->simpan(WajibMasukMiddleware::SESI_TERAKHIR, time());
        $lain->simpan(WajibMasukMiddleware::SESI_SANDI, $this->user()->sidikSandi());

        $this->gantiSandi();

        $response = (new WajibMasukMiddleware($this->daftar, $this->pintu))->process(
            (new ServerRequest([], [], '/dashboard', 'GET'))->withAttribute(Sesi::class, $lain),
            new class implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
                {
                    return new \Laminas\Diactoros\Response\EmptyResponse(200);
                }
            },
        );

        self::assertSame('/dashboard/masuk', $response->getHeaderLine('Location'));
    }

    public function testSandiLamaSalahDitolakDanDihitung(): void
    {
        $response = $this->gantiSandi('salah');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Kata sandi lama salah.', $this->renderer->data['galat']);
        self::assertTrue(password_verify(self::SANDI, $this->user()->kataSandi));

        for ($i = 1; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->gantiSandi('salah');
        }

        self::assertSame(429, $this->gantiSandi()->getStatusCode(), 'Setelah batas, kata sandi benar pun ditolak.');
    }

    public function testSandiBaruTerlaluPendekDitolak(): void
    {
        self::assertSame(422, $this->gantiSandi(baru: 'pendek-11ch')->getStatusCode());
        self::assertSame('Kata sandi baru minimal 12 karakter.', $this->renderer->data['galat']);
    }

    public function testUserBiasaCukupDelapanKarakter(): void
    {
        $this->daftar->cabutAkses($this->id, 'dashboard');
        $this->db->query('INSERT INTO "user_akses" (user_id, lingkup, peran) VALUES (?, ?, ?)', [$this->id, 'dashboard', 'anggota']);

        self::assertSame(302, $this->gantiSandi(baru: 'delapan8')->getStatusCode());
    }

    public function testUlangiSandiTidakSamaDitolak(): void
    {
        self::assertSame(422, $this->gantiSandi(ulang: 'lain-sama-sekali-panjang')->getStatusCode());
        self::assertTrue(password_verify(self::SANDI, $this->user()->kataSandi));
    }
}
