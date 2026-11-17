<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
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

    private function handler(string $jenis = ProfilHandler::HALAMAN_PROFIL): ProfilHandler
    {
        return new ProfilHandler(
            $this->daftar,
            new PembatasPercobaan($this->db),
            $this->riwayat,
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            $jenis === ProfilHandler::HALAMAN_PROFIL ? ProfilHandler::TEMPLATE : ProfilHandler::TEMPLATE_PENGATURAN,
            new RiwayatAkun($this->db),
            jenis: $jenis,
        );
    }

    private function pengaturan(): ProfilHandler
    {
        return $this->handler(ProfilHandler::HALAMAN_PENGATURAN);
    }

    private function user(): User
    {
        $user = $this->daftar->cariId($this->id);
        assert($user !== null);

        return $user;
    }

    /** @param array<string, string> $query */
    private function get(string $jenis = ProfilHandler::HALAMAN_PROFIL, array $query = []): ResponseInterface
    {
        return $this->handler($jenis)->handle(
            (new ServerRequest([], [], '/dashboard/' . $jenis, 'GET'))
                ->withQueryParams($query)
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

        // Semua form ada di halaman Pengaturan & Privasi; halaman Profil hanya untuk dibaca.
        $jenis = ProfilHandler::HALAMAN_PENGATURAN;

        return $this->handler($jenis)->handle(
            (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/dashboard/' . $jenis, 'POST', 'php://memory', ['User-Agent' => 'Browser Uji']))
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

        $response = $this->get(ProfilHandler::HALAMAN_PENGATURAN);
        $data     = $this->renderer->data;

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ProfilHandler::TEMPLATE_PENGATURAN, $this->renderer->template);
        self::assertSame('layout::uji', $data['layout']);
        self::assertSame('/dashboard/profil', $data['alamatProfil']);
        self::assertSame('/dashboard/pengaturan', $data['alamatPengaturan']);
        self::assertSame('akun', $data['tab']);
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

        self::assertSame('/dashboard/pengaturan?tab=akun', $response->getHeaderLine('Location'));
        self::assertSame('Ade M Saragih', $this->user()->nama);

        $akun = (new RiwayatAkun($this->db))->untukUser($this->id);
        self::assertSame(RiwayatAkun::UBAH_NAMA, $akun[0]['peristiwa']);
        self::assertSame('Admin → Ade M Saragih', $akun[0]['keterangan']);
        self::assertSame('admin@contoh.com', $akun[0]['pelaku']);

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

        self::assertSame('/dashboard/pengaturan?tab=akun', $response->getHeaderLine('Location'));
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

    public function testTabPeranStatusDanAktivitas(): void
    {
        $lain = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $akun = new RiwayatAkun($this->db);
        $akun->catat(RiwayatAkun::BERI_AKSES, $lain, $this->id, 'dashboard', 'peran anggota', '192.0.2.9', 'Browser');
        $akun->catat(RiwayatAkun::UBAH_NAMA, $this->id, $this->id, 'dashboard', 'A → B', '192.0.2.1', 'Browser');
        $akun->catat(RiwayatAkun::BUAT_USER, null, $this->id, 'dashboard', 'Admin', '', 'terminal');
        // Perubahan yang dilakukan user ini pada akun lain ikut tampil; yang tidak melibatkannya tidak.
        $citra = $this->daftar->buat('citra@contoh.com', 'Citra', self::SANDI);
        $akun->catat(RiwayatAkun::UBAH_PERAN, $this->id, $lain, 'dashboard', 'anggota → admin_super', '192.0.2.1', 'Browser');
        $akun->catat(RiwayatAkun::BUAT_USER, $lain, $citra, 'dashboard', 'Citra', '192.0.2.9', 'Browser');

        $this->handler()->handle(
            (new ServerRequest([], [], '/dashboard/profil', 'GET'))
                ->withAttribute(Sesi::class, $this->sesi)
                ->withAttribute(User::class, $this->user())
                ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, DaftarUser::PERAN_ADMIN_SUPER),
        );
        $data = $this->renderer->data;

        self::assertSame(ProfilHandler::TEMPLATE, $this->renderer->template);
        self::assertSame([], $data['riwayat'], 'Profil tidak memuat riwayat masuk.');
        self::assertSame([], $data['aktivitas'], 'Profil tidak memuat aktivitas.');
        self::assertSame('/dashboard/pengaturan', $data['alamatPengaturan']);
        self::assertSame(['nilai' => 'admin_super', 'label' => DaftarUser::label('admin_super')], $data['peran']);
        self::assertTrue($data['aktif']);
        self::assertSame('/dashboard', $data['alamatBeranda']);

        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'aktivitas']);
        $data = $this->renderer->data;
        self::assertSame('aktivitas', $data['tab']);
        // Email admin lain tidak ditampilkan: cukup "Admin".
        self::assertCount(4, $data['aktivitas']);
        self::assertEqualsCanonicalizing(['Admin', 'Anda', 'Anda', 'Terminal'], array_column($data['aktivitas'], 'oleh'));
        self::assertEqualsCanonicalizing(['Anda', 'Anda', 'Anda', 'super@contoh.com'], array_column($data['aktivitas'], 'user'));

    }

    public function testTabTakDikenalDanGalatMembukaTabnya(): void
    {
        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => '<x>']);
        self::assertSame('akun', $this->renderer->data['tab']);
        self::assertNull($this->renderer->data['peran']);

        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'riwayat']);
        self::assertSame('riwayat', $this->renderer->data['tab']);

        // Form di popup gagal: tab Akun Saya terbuka, popup dibuka template menurut galatBagian.
        $this->post(['aksi' => 'email', 'email_baru' => 'x@contoh.com', 'sandi_sekarang' => 'salah']);
        self::assertSame('akun', $this->renderer->data['tab']);
        self::assertSame('email', $this->renderer->data['galatBagian']);
    }

    public function testTampilkanLebihBanyak(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->riwayat->catat(RiwayatMasuk::MASUK, $this->id, 'dashboard', '192.0.2.1', 'Browser');
        }

        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'riwayat']);
        self::assertCount(20, $this->renderer->data['riwayat']);
        self::assertSame(40, $this->renderer->data['riwayatLagi']);
        self::assertNull($this->renderer->data['aktivitasLagi']);

        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'riwayat', 'jumlah' => '40']);
        self::assertCount(25, $this->renderer->data['riwayat']);
        self::assertNull($this->renderer->data['riwayatLagi']);

        // Jumlah hanya berlaku untuk tab yang terbuka, dan nilai aneh kembali ke bawaan.
        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'aktivitas', 'jumlah' => '40']);
        self::assertCount(20, $this->renderer->data['riwayat']);
        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'riwayat', 'jumlah' => '-5']);
        self::assertCount(20, $this->renderer->data['riwayat']);
        $this->get(ProfilHandler::HALAMAN_PENGATURAN, ['tab' => 'riwayat', 'jumlah' => '99999']);
        self::assertCount(25, $this->renderer->data['riwayat']);
    }

    public function testTautanLamaKeTabProfilDialihkanKePengaturan(): void
    {
        $this->sesi->simpan(ProfilHandler::SESI_PESAN, 'Pesan lama');

        $response = $this->get(ProfilHandler::HALAMAN_PROFIL, ['tab' => 'riwayat']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/pengaturan?tab=riwayat', $response->getHeaderLine('Location'));
        self::assertSame('/dashboard/pengaturan?tab=akun', $this->get(ProfilHandler::HALAMAN_PROFIL, ['tab' => 'sandi'])->getHeaderLine('Location'));
        self::assertSame('Pesan lama', $this->sesi->ambil(ProfilHandler::SESI_PESAN), 'Pesan tetap tampil di halaman tujuan.');

        self::assertSame(200, $this->get(ProfilHandler::HALAMAN_PROFIL, ['tab' => '<x>'])->getStatusCode());
    }

    public function testHalamanProfilHanyaUntukDibaca(): void
    {
        $isi = ['aksi' => 'nama', 'nama' => 'Nama Lain'];
        $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();

        $response = $this->handler()->handle(
            (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/dashboard/profil', 'POST'))
                ->withParsedBody($isi)
                ->withAttribute(Sesi::class, $this->sesi)
                ->withAttribute(User::class, $this->user()),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Admin', $this->user()->nama);
    }

    public function testNamaSamaTidakDicatat(): void
    {
        $this->post(['aksi' => 'nama', 'nama' => 'Admin']);

        self::assertSame([], (new RiwayatAkun($this->db))->untukUser($this->id));
    }

    public function testUbahNamaSebelumPasangMemberiPesanJelas(): void
    {
        $this->db->query('DROP TABLE "user_riwayat_akun"', \Laminas\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);

        $response = $this->post(['aksi' => 'nama', 'nama' => 'Nama Baru']);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(RiwayatAkun::PESAN_BELUM_DIPASANG, $this->renderer->data['galat']);
        self::assertSame('Admin', $this->user()->nama);
    }
}
