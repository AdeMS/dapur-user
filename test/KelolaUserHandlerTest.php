<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Handler\KelolaUserHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/BantuanUji.php';

final class KelolaUserHandlerTest extends TestCase
{
    private const SANDI   = 'kata-sandi-yang-panjang';
    private const LINGKUP = 'toko.contoh.com';

    private Adapter $db;
    private DaftarUser $daftar;
    private RiwayatAkun $riwayatAkun;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private int $admin;
    private int $budi;

    protected function setUp(): void
    {
        $this->db          = DatabaseUji::baru();
        $this->daftar      = new DaftarUser($this->db);
        $this->riwayatAkun = new RiwayatAkun($this->db);
        $this->renderer    = new RendererUji();
        $this->sesi        = new SesiLarik();
        $this->pintu       = new Pintu(self::LINGKUP, '/dashboard', 3600, 'layout::uji');
        $this->admin       = $this->daftar->buat('admin@contoh.com', 'Admin', self::SANDI);
        $this->budi        = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($this->admin, self::LINGKUP, DaftarUser::PERAN_ADMIN_WEB);
        $this->daftar->tambahAkses($this->budi, self::LINGKUP, DaftarUser::PERAN_ANGGOTA);
    }

    private function handler(bool $kelolaAkun = false): KelolaUserHandler
    {
        return new KelolaUserHandler(
            $this->daftar,
            $this->riwayatAkun,
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            $kelolaAkun,
        );
    }

    private function permintaan(string $metode, int $sebagai): ServerRequest
    {
        $user = $this->daftar->cariId($sebagai);
        assert($user !== null);

        return (new ServerRequest(['REMOTE_ADDR' => '192.0.2.1'], [], '/dashboard/user', $metode, 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withAttribute(Sesi::class, $this->sesi)
            ->withAttribute(User::class, $user)
            ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, $this->pintu->lingkup));
    }

    private function get(?int $sebagai = null): ResponseInterface
    {
        return $this->handler()->handle($this->permintaan('GET', $sebagai ?? $this->admin));
    }

    /** @param array<string, string> $isi */
    private function post(array $isi, bool $kelolaAkun = false, ?int $sebagai = null, bool $denganToken = true): ResponseInterface
    {
        if ($denganToken) {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        return $this->handler($kelolaAkun)->handle($this->permintaan('POST', $sebagai ?? $this->admin)->withParsedBody($isi));
    }

    public function testAnggotaDitolak(): void
    {
        $response = $this->get($this->budi);

        self::assertSame(403, $response->getStatusCode());
        self::assertTrue($this->renderer->data['ditolak']);
        self::assertSame([], $this->renderer->data['daftarUser']);

        self::assertSame(403, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->admin], sebagai: $this->budi)->getStatusCode());
        self::assertTrue($this->daftar->punyaAkses($this->admin, self::LINGKUP));
    }

    public function testDaftarUser(): void
    {
        self::assertSame(200, $this->get()->getStatusCode());

        $daftar = array_column($this->renderer->data['daftarUser'], null, 'email');
        self::assertTrue($daftar['admin@contoh.com']['diriSendiri']);
        self::assertFalse($daftar['admin@contoh.com']['bisaCabut']);
        self::assertTrue($daftar['budi@contoh.com']['bisaCabut']);
        self::assertFalse($daftar['budi@contoh.com']['bisaAturAktif'], 'Aktif/nonaktif hanya di dashboard utama.');
        self::assertSame('/dashboard/user', $this->renderer->data['alamatKelola']);
        self::assertSame(self::LINGKUP, $this->renderer->data['lingkup']);
    }

    public function testTambahUserBaruDenganSandiSementaraSekali(): void
    {
        $response = $this->post(['aksi' => 'tambah', 'email' => ' Citra@Contoh.com ', 'nama' => 'Citra', 'peran' => 'anggota']);

        self::assertSame('/dashboard/user', $response->getHeaderLine('Location'));
        $citra = $this->daftar->cariEmail('citra@contoh.com');
        self::assertNotNull($citra);
        self::assertSame('anggota', $this->daftar->peran($citra->id, self::LINGKUP));

        $this->get();
        $baru = $this->renderer->data['sandiBaru'];
        self::assertSame('citra@contoh.com', $baru['email']);
        self::assertTrue(password_verify($baru['sandi'], $citra->kataSandi));

        $this->get();
        self::assertNull($this->renderer->data['sandiBaru'], 'Kata sandi hanya tampil sekali.');
        self::assertArrayNotHasKey(KelolaUserHandler::SESI_SANDI_BARU, $this->sesi->isi);

        $akun = $this->riwayatAkun->untukUser($citra->id);
        self::assertSame([RiwayatAkun::BERI_AKSES, RiwayatAkun::BUAT_USER], array_column($akun, 'peristiwa'));
        self::assertSame('admin@contoh.com', $akun[0]['pelaku']);
        self::assertSame(self::LINGKUP, $akun[0]['lingkup']);
        self::assertSame('Browser Uji', $akun[0]['perangkat']);
    }

    public function testTambahEmailTerdaftarHanyaMemberiAkses(): void
    {
        $dewi = $this->daftar->buat('dewi@contoh.com', 'Dewi', self::SANDI);
        $this->daftar->tambahAkses($dewi, 'dashboard', DaftarUser::PERAN_ANGGOTA);

        $this->post(['aksi' => 'tambah', 'email' => 'dewi@contoh.com', 'nama' => '', 'peran' => 'admin_web']);

        self::assertSame('admin_web', $this->daftar->peran($dewi, self::LINGKUP));
        self::assertSame('anggota', $this->daftar->peran($dewi, 'dashboard'), 'Akses lain tidak berubah.');
        self::assertTrue(password_verify(self::SANDI, (string) $this->daftar->cariId($dewi)?->kataSandi), 'Kata sandi tidak diubah.');
        $this->get();
        self::assertNull($this->renderer->data['sandiBaru']);
        self::assertStringContainsString('sudah terdaftar', (string) $this->renderer->data['pesan']);
    }

    public function testTambahDitolakBilaTidakSah(): void
    {
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'bukan-email', 'nama' => 'X', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'baru@contoh.com', 'nama' => '', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('baru@contoh.com', $this->renderer->data['formEmail'], 'Isian form dikembalikan.');
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'baru@contoh.com', 'nama' => 'X', 'peran' => 'raja'])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'budi@contoh.com', 'nama' => '', 'peran' => 'admin_web'])->getStatusCode());
        self::assertNull($this->daftar->cariEmail('baru@contoh.com'));
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testUbahPeran(): void
    {
        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web']);

        self::assertSame('admin_web', $this->daftar->peran($this->budi, self::LINGKUP));
        self::assertSame('anggota → admin_web', $this->riwayatAkun->untukUser($this->budi)[0]['keterangan']);

        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $this->admin, 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('admin_web', $this->daftar->peran($this->admin, self::LINGKUP));

        // Admin super hanya bisa dipilih di dashboard utama.
        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_super'])->getStatusCode());
    }

    public function testCabutAkses(): void
    {
        $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi]);

        self::assertNull($this->daftar->peran($this->budi, self::LINGKUP));
        self::assertNotNull($this->daftar->cariId($this->budi), 'Akun tidak dihapus.');
        self::assertSame(RiwayatAkun::CABUT_AKSES, $this->riwayatAkun->untukLingkup(self::LINGKUP)[0]['peristiwa']);

        self::assertSame(422, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->admin])->getStatusCode());
    }

    public function testUserLingkupLainTidakBisaDisentuh(): void
    {
        $lain = $this->daftar->buat('lain@contoh.com', 'Lain', self::SANDI);
        $this->daftar->tambahAkses($lain, 'blog.contoh.com');

        self::assertSame(404, $this->post(['aksi' => 'cabut', 'user_id' => (string) $lain])->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'aktif', 'user_id' => (string) $lain, 'nilai' => '0'], true)->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'peran', 'user_id' => 'abc', 'peran' => 'admin_web'])->getStatusCode());
        self::assertTrue($this->daftar->punyaAkses($lain, 'blog.contoh.com'));
        self::assertTrue((bool) $this->daftar->cariId($lain)?->aktif);
    }

    public function testAktifNonaktifHanyaBilaDiizinkan(): void
    {
        self::assertSame(403, $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '0'])->getStatusCode());
        self::assertTrue((bool) $this->daftar->cariId($this->budi)?->aktif);

        $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '0'], true);
        self::assertFalse((bool) $this->daftar->cariId($this->budi)?->aktif);
        self::assertSame(RiwayatAkun::NONAKTIFKAN, $this->riwayatAkun->untukUser($this->budi)[0]['peristiwa']);

        $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->budi, 'nilai' => '1'], true);
        self::assertTrue((bool) $this->daftar->cariId($this->budi)?->aktif);

        self::assertSame(422, $this->post(['aksi' => 'aktif', 'user_id' => (string) $this->admin, 'nilai' => '0'], true)->getStatusCode());
        self::assertTrue((bool) $this->daftar->cariId($this->admin)?->aktif);
    }

    public function testAdminSuperOtomatisDiWebsiteDanTidakBisaDiubahDariSana(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');

        $this->get();
        $baris = array_values(array_filter($this->renderer->data['daftarUser'], static fn (array $u): bool => $u['id'] === $super))[0];
        self::assertSame('admin_super', $baris['peran']);
        self::assertTrue($baris['otomatis']);
        self::assertFalse($baris['bisaUbahPeran']);
        self::assertFalse($baris['bisaCabut']);
        self::assertSame(['admin_web' => 'Admin web', 'anggota' => 'Anggota'], $this->renderer->data['peranTersedia']);

        // Admin web tidak bisa mengubah atau mencabut admin super lewat ID.
        self::assertSame(404, $this->post(['aksi' => 'peran', 'user_id' => (string) $super, 'peran' => 'anggota'])->getStatusCode());
        self::assertSame(404, $this->post(['aksi' => 'cabut', 'user_id' => (string) $super])->getStatusCode());
        self::assertSame(422, $this->post(['aksi' => 'tambah', 'email' => 'super@contoh.com', 'nama' => '', 'peran' => 'anggota'])->getStatusCode());
        self::assertSame('admin_super', $this->daftar->peran($super, self::LINGKUP));
    }

    public function testAdminSuperBisaMengelolaWebsiteTanpaAksesTersendiri(): void
    {
        $super = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');

        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web'], sebagai: $super);
        self::assertSame('admin_web', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testAdminSuperTerakhirDiDashboardUtamaTerlindungi(): void
    {
        $pintu   = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $super   = $this->daftar->buat('super@contoh.com', 'Super', self::SANDI);
        $kedua   = $this->daftar->buat('kedua@contoh.com', 'Kedua', self::SANDI);
        $this->daftar->tambahAkses($super, 'dashboard');
        $this->daftar->tambahAkses($kedua, 'dashboard', DaftarUser::PERAN_ANGGOTA);
        $this->pintu = $pintu;

        $this->get($super);
        self::assertSame(['admin_super' => 'Admin super', 'anggota' => 'Anggota'], $this->renderer->data['peranTersedia']);

        // Kedua dijadikan admin super, lalu boleh diturunkan lagi karena masih ada admin super lain.
        $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'admin_super'], true, sebagai: $super);
        self::assertTrue($this->daftar->adalahAdminSuper($kedua));
        self::assertSame(2, $this->daftar->jumlahAdminSuper());
        $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'anggota'], true, sebagai: $super);
        self::assertFalse($this->daftar->adalahAdminSuper($kedua));

        // Admin web tidak bisa dipilih di dashboard utama.
        self::assertSame(422, $this->post(['aksi' => 'peran', 'user_id' => (string) $kedua, 'peran' => 'admin_web'], true, sebagai: $super)->getStatusCode());
    }

    public function testTanpaTokenDitolak(): void
    {
        self::assertSame(400, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi], denganToken: false)->getStatusCode());
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP));
    }

    public function testAktivitasMenampilkanWaktuSetempatDanPelaku(): void
    {
        $this->post(['aksi' => 'peran', 'user_id' => (string) $this->budi, 'peran' => 'admin_web']);
        $this->get();

        $a = $this->renderer->data['aktivitas'][0];
        self::assertSame('Ubah peran', $a['label']);
        self::assertSame('admin@contoh.com', $a['pelaku']);
        self::assertSame('budi@contoh.com', $a['sasaran']);
        self::assertSame('Perangkat lain', $a['perangkatRingkas']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $a['waktuSetempat']);
        self::assertStringContainsString('Peran budi@contoh.com sekarang admin web.', (string) $this->renderer->data['pesan']);
    }

    public function testTabelRiwayatAkunBelumDibuatMemberiPesanJelas(): void
    {
        $this->db->query('DROP TABLE "user_riwayat_akun"', Adapter::QUERY_MODE_EXECUTE);

        self::assertSame(503, $this->get()->getStatusCode());
        self::assertSame(RiwayatAkun::PESAN_BELUM_DIPASANG, $this->renderer->data['belumSiap']);

        self::assertSame(503, $this->post(['aksi' => 'cabut', 'user_id' => (string) $this->budi])->getStatusCode());
        self::assertSame('anggota', $this->daftar->peran($this->budi, self::LINGKUP), 'Tidak ada yang diubah.');
    }
}
