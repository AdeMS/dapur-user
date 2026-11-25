<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\Email\PengirimEmail;
use AdeMS\Dapur\Email\Pesan;
use AdeMS\Dapur\Email\SuratTemplat;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\DaftarWebsite;
use AdeMS\Dapur\User\Handler\AturSandiHandler;
use AdeMS\Dapur\User\Handler\HalamanPublik;
use AdeMS\Dapur\User\Handler\KelolaUserHandler;
use AdeMS\Dapur\User\Handler\LupaSandiHandler;
use AdeMS\Dapur\User\Handler\ProfilHandler;
use AdeMS\Dapur\User\Handler\VerifikasiEmailHandler;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiDasar;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\SuratUser;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\TokenUser;
use AdeMS\Dapur\User\User;
use DateTimeZone;
use Laminas\Db\Adapter\Adapter;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/BantuanUji.php';

/** Pengirim tiruan: menyimpan email alih-alih mengirimnya. */
final class PengirimUji implements PengirimEmail
{
    /** @var list<Pesan> */
    public array $terkirim = [];

    public bool $aktif = true;

    public bool $gagal = false;

    public function aktif(): bool
    {
        return $this->aktif;
    }

    public function kirim(Pesan $pesan): void
    {
        if ($this->gagal) {
            throw new GagalKirim('server menolak');
        }

        $this->terkirim[] = $pesan;
    }
}

/** Renderer email tiruan: isi email berisi nama template dan tautannya, agar uji bisa mengambil token. */
final class RendererEmailUji implements TemplateRendererInterface
{
    public function render(string $name, $params = []): string
    {
        $params = (array) $params;
        if (isset($params['content'])) {
            // Layout email: cukup kembalikan isinya, agar uji bisa membaca nama template isi.
            return (string) $params['content'];
        }

        return $name . '|' . ($params['tautan'] ?? '') . '|' . ($params['emailBaru'] ?? '') . '|' . (($params['sudahDiganti'] ?? false) ? 'sudah' : 'belum');
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

final class AlurEmailTest extends TestCase
{
    private const SANDI = 'kata-sandi-yang-panjang';
    private const BARU  = 'kata-sandi-baru-yang-panjang';

    private Adapter $db;
    private DaftarUser $daftar;
    private TokenUser $token;
    private PengirimUji $pengirim;
    private SuratUser $surat;
    private RendererUji $renderer;
    private SesiLarik $sesi;
    private Pintu $pintu;
    private int $admin;
    private int $budi;

    protected function setUp(): void
    {
        $this->db       = DatabaseUji::baru();
        $this->daftar   = new DaftarUser($this->db);
        $this->token    = new TokenUser($this->db);
        $this->pengirim = new PengirimUji();
        $this->surat    = new SuratUser($this->pengirim, new SuratTemplat(new RendererEmailUji()));
        $this->renderer = new RendererUji();
        $this->sesi     = new SesiLarik();
        $this->pintu    = new Pintu('dashboard', '/', 3600, 'layout::uji');
        $this->admin    = $this->daftar->buat('admin@contoh.com', 'Admin Uji', self::SANDI);
        $this->budi     = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $this->daftar->tambahAkses($this->admin, 'dashboard');
        $this->daftar->tambahAkses($this->budi, 'dashboard', DaftarUser::PERAN_ANGGOTA);
    }

    /** @param array<string, string> $isi */
    private function permintaan(string $metode, string $alamat, array $isi = [], ?int $sebagai = null): ServerRequest
    {
        if ($metode === 'POST') {
            $isi[TokenCsrf::FIELD] = (new TokenCsrf($this->sesi))->token();
        }

        $request = (new ServerRequest(['REMOTE_ADDR' => '192.0.2.5'], [], 'https://dapur.contoh.com' . $alamat, $metode, 'php://memory', ['User-Agent' => 'Browser Uji']))
            ->withAttribute(Sesi::class, $this->sesi)
            ->withQueryParams($metode === 'GET' ? $isi : [])
            ->withParsedBody($metode === 'POST' ? $isi : null);

        if ($sebagai !== null) {
            $user = $this->daftar->cariId($sebagai);
            $request = $request->withAttribute(User::class, $user)
                ->withAttribute(WajibMasukMiddleware::ATRIBUT_PERAN, $this->daftar->peran($sebagai, 'dashboard'));
        }

        return $request;
    }

    private function halaman(): HalamanPublik
    {
        return new HalamanPublik($this->renderer, $this->pintu);
    }

    private function lupa(): LupaSandiHandler
    {
        return new LupaSandiHandler($this->daftar, $this->token, $this->surat, new PembatasPercobaan($this->db), $this->halaman(), $this->pintu, 'Dapur Uji');
    }

    private function atur(): AturSandiHandler
    {
        return new AturSandiHandler($this->daftar, $this->token, new RiwayatMasuk($this->db), $this->halaman(), $this->pintu);
    }

    private function verifikasi(): VerifikasiEmailHandler
    {
        return new VerifikasiEmailHandler($this->daftar, $this->token, $this->surat, new RiwayatAkun($this->db), $this->halaman(), $this->pintu, 'Dapur Uji');
    }

    private function profil(): ProfilHandler
    {
        return new ProfilHandler(
            $this->daftar,
            new PembatasPercobaan($this->db),
            new RiwayatMasuk($this->db),
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            ProfilHandler::TEMPLATE_PENGATURAN,
            new RiwayatAkun($this->db),
            $this->token,
            $this->surat,
            'Dapur Uji',
            ProfilHandler::HALAMAN_PENGATURAN,
        );
    }

    private function kelola(?DaftarWebsite $website = null): KelolaUserHandler
    {
        return new KelolaUserHandler(
            $this->daftar,
            new RiwayatAkun($this->db),
            new PenyajiDasar($this->renderer, $this->pintu),
            $this->pintu,
            new DateTimeZone('Asia/Jakarta'),
            true,
            KelolaUserHandler::TEMPLATE,
            $this->token,
            $this->surat,
            'Dapur Uji',
            daftarWebsite: $website,
        );
    }

    /** Token dari tautan di email terakhir. */
    private function tokenTerakhir(): string
    {
        $pesan = end($this->pengirim->terkirim);
        self::assertInstanceOf(Pesan::class, $pesan);
        parse_str((string) parse_url(explode('|', $pesan->html)[1], PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }

    public function testLupaSandiJawabanSamaDanEmailHanyaUntukAkunTerdaftar(): void
    {
        $tidakAda = $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => 'tidak@contoh.com']));
        self::assertSame(200, $tidakAda->getStatusCode());
        self::assertTrue($this->renderer->data['terkirim']);
        self::assertSame([], $this->pengirim->terkirim);

        $ada = $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => ' Budi@Contoh.com ']));
        self::assertSame(200, $ada->getStatusCode());
        self::assertTrue($this->renderer->data['terkirim']);
        self::assertCount(1, $this->pengirim->terkirim);
        self::assertSame('budi@contoh.com', $this->pengirim->terkirim[0]->kepada);
        self::assertStringStartsWith('user::email-lupa-sandi|https://dapur.contoh.com/atur-sandi?token=', $this->pengirim->terkirim[0]->html);
        self::assertSame('no-referrer', $ada->getHeaderLine('Referrer-Policy'));
    }

    public function testLupaSandiDibatasiDanTidakTersediaTanpaEmail(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => 'budi@contoh.com']));
        }

        self::assertSame(429, $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => 'budi@contoh.com']))->getStatusCode());
        self::assertFalse((new PembatasPercobaan($this->db))->terkunci('budi@contoh.com', '192.0.2.5'), 'Login tidak ikut terkunci.');

        $this->pengirim->aktif = false;
        self::assertSame(404, $this->lupa()->handle($this->permintaan('GET', '/lupa-sandi'))->getStatusCode());
    }

    public function testAturSandiDariTautanLupaSandi(): void
    {
        $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => 'budi@contoh.com']));
        $token = $this->tokenTerakhir();

        self::assertSame(200, $this->atur()->handle($this->permintaan('GET', '/atur-sandi', ['token' => $token]))->getStatusCode());
        self::assertSame('budi@contoh.com', $this->renderer->data['email']);
        self::assertSame(8, $this->renderer->data['minimalSandi'], 'Anggota minimal 8 karakter.');
        self::assertNotNull($this->token->periksa($token, [TokenUser::LUPA_SANDI]), 'Membuka tautan tidak memakai token.');

        self::assertSame(422, $this->atur()->handle($this->permintaan('POST', '/atur-sandi', ['token' => $token, 'sandi_baru' => self::BARU, 'sandi_ulang' => 'lain']))->getStatusCode());

        $hasil = $this->atur()->handle($this->permintaan('POST', '/atur-sandi', ['token' => $token, 'sandi_baru' => self::BARU, 'sandi_ulang' => self::BARU]));
        self::assertSame(302, $hasil->getStatusCode());
        self::assertSame('/masuk', $hasil->getHeaderLine('Location'));
        self::assertTrue(password_verify(self::BARU, (string) $this->daftar->cariId($this->budi)?->kataSandi));
        self::assertSame(RiwayatMasuk::GANTI_SANDI, (new RiwayatMasuk($this->db))->untukUser($this->budi)[0]['peristiwa']);

        self::assertSame(410, $this->atur()->handle($this->permintaan('POST', '/atur-sandi', ['token' => $token, 'sandi_baru' => self::BARU, 'sandi_ulang' => self::BARU]))->getStatusCode(), 'Sekali pakai.');
        self::assertSame(410, $this->atur()->handle($this->permintaan('GET', '/atur-sandi', ['token' => 'palsu']))->getStatusCode());
    }

    public function testUndanganUntukWebsiteLainDicatatDiWebsiteItu(): void
    {
        $website = new class implements DaftarWebsite {
            public function website(): array
            {
                return ['toko.contoh.com'];
            }

            public function batasanDomain(string $host): BatasanDomain
            {
                return new BatasanDomain();
            }
        };

        $hasil = $this->kelola($website)->handle($this->permintaan('POST', '/user', ['aksi' => 'tambah', 'email' => 'citra@contoh.com', 'nama' => 'Citra', 'peran' => 'admin_web', 'lingkup' => 'toko.contoh.com'], $this->admin));
        self::assertSame(302, $hasil->getStatusCode());
        self::assertSame('Undangan bergabung ke toko.contoh.com', $this->pengirim->terkirim[0]->subjek);
        self::assertSame(
            [RiwayatAkun::UNDANG, RiwayatAkun::BERI_AKSES, RiwayatAkun::BUAT_USER],
            array_column((new RiwayatAkun($this->db))->untukLingkup('toko.contoh.com'), 'peristiwa'),
        );
        self::assertSame([], (new RiwayatAkun($this->db))->untukLingkup('dashboard'));
    }

    public function testUndanganUserBaru(): void
    {
        $hasil = $this->kelola()->handle($this->permintaan('POST', '/user', ['aksi' => 'tambah', 'email' => 'citra@contoh.com', 'nama' => 'Citra', 'peran' => 'anggota'], $this->admin));
        self::assertSame(302, $hasil->getStatusCode());
        self::assertCount(1, $this->pengirim->terkirim);
        self::assertSame('citra@contoh.com', $this->pengirim->terkirim[0]->kepada);
        self::assertStringStartsWith('user::email-undangan|', $this->pengirim->terkirim[0]->html);

        $this->kelola()->handle($this->permintaan('GET', '/user', [], $this->admin));
        self::assertNull($this->renderer->data['sandiBaru'], 'Kata sandi sementara tidak ditampilkan bila undangan terkirim.');
        self::assertStringContainsString('Undangan sudah dikirim', (string) $this->renderer->data['pesan']);
        $citra = array_values(array_filter($this->renderer->data['daftarUser'], static fn (array $u): bool => $u['email'] === 'citra@contoh.com'))[0];
        self::assertTrue($citra['bisaUndang']);

        // Kirim ulang membatalkan tautan lama.
        $lama = $this->tokenTerakhir();
        $this->kelola()->handle($this->permintaan('POST', '/user', ['aksi' => 'undang', 'user' => $citra['kode']], $this->admin));
        self::assertCount(2, $this->pengirim->terkirim);
        self::assertNull($this->token->periksa($lama, [TokenUser::UNDANGAN]));
        self::assertNotNull($this->token->periksa($this->tokenTerakhir(), [TokenUser::UNDANGAN]));
        self::assertSame(RiwayatAkun::UNDANG, (new RiwayatAkun($this->db))->untukUser((int) $citra['id'])[0]['peristiwa']);

        // Tautan undangan membuka halaman sambutan.
        $this->atur()->handle($this->permintaan('GET', '/atur-sandi', ['token' => $this->tokenTerakhir()]));
        self::assertTrue($this->renderer->data['undangan']);
    }

    public function testUndanganGagalMemakaiKataSandiSementara(): void
    {
        $this->pengirim->gagal = true;
        $this->kelola()->handle($this->permintaan('POST', '/user', ['aksi' => 'tambah', 'email' => 'dewi@contoh.com', 'nama' => 'Dewi', 'peran' => 'anggota'], $this->admin));
        $this->kelola()->handle($this->permintaan('GET', '/user', [], $this->admin));

        self::assertSame('dewi@contoh.com', $this->renderer->data['sandiBaru']['email'] ?? null);
        self::assertStringContainsString('Undangan gagal dikirim', (string) $this->renderer->data['pesan']);
    }

    public function testTanpaEmailTetapKataSandiSementara(): void
    {
        $this->pengirim->aktif = false;
        $this->kelola()->handle($this->permintaan('POST', '/user', ['aksi' => 'tambah', 'email' => 'eko@contoh.com', 'nama' => 'Eko', 'peran' => 'anggota'], $this->admin));
        $this->kelola()->handle($this->permintaan('GET', '/user', [], $this->admin));

        self::assertSame([], $this->pengirim->terkirim);
        self::assertSame('eko@contoh.com', $this->renderer->data['sandiBaru']['email'] ?? null);
        self::assertFalse($this->renderer->data['pakaiUndangan']);
    }

    public function testGantiEmailLewatVerifikasi(): void
    {
        self::assertSame(422, $this->profil()->handle($this->permintaan('POST', '/pengaturan', ['aksi' => 'email', 'email_baru' => 'budi.baru@contoh.com', 'sandi_sekarang' => 'salah'], $this->budi))->getStatusCode());
        self::assertSame(422, $this->profil()->handle($this->permintaan('POST', '/pengaturan', ['aksi' => 'email', 'email_baru' => 'admin@contoh.com', 'sandi_sekarang' => self::SANDI], $this->budi))->getStatusCode(), 'Email akun lain ditolak.');
        self::assertSame([], $this->pengirim->terkirim);

        $hasil = $this->profil()->handle($this->permintaan('POST', '/pengaturan', ['aksi' => 'email', 'email_baru' => 'Budi.Baru@Contoh.com', 'sandi_sekarang' => self::SANDI], $this->budi));
        self::assertSame(302, $hasil->getStatusCode());
        self::assertCount(2, $this->pengirim->terkirim);
        self::assertSame('budi.baru@contoh.com', $this->pengirim->terkirim[0]->kepada, 'Verifikasi ke email baru.');
        self::assertSame('budi@contoh.com', $this->pengirim->terkirim[1]->kepada, 'Pemberitahuan ke email lama.');
        self::assertStringEndsWith('|belum', $this->pengirim->terkirim[1]->html);
        self::assertSame('budi@contoh.com', $this->daftar->cariId($this->budi)?->email, 'Belum berubah sebelum verifikasi.');

        $token = '';
        parse_str((string) parse_url(explode('|', $this->pengirim->terkirim[0]->html)[1], PHP_URL_QUERY), $query);
        $token = (string) $query['token'];

        self::assertSame(200, $this->verifikasi()->handle($this->permintaan('GET', '/verifikasi-email', ['token' => $token]))->getStatusCode());
        self::assertSame('budi.baru@contoh.com', $this->renderer->data['emailBaru']);
        self::assertSame('budi@contoh.com', $this->daftar->cariId($this->budi)?->email, 'Membuka tautan saja tidak mengganti email.');

        $hasil = $this->verifikasi()->handle($this->permintaan('POST', '/verifikasi-email', ['token' => $token]));
        self::assertSame(302, $hasil->getStatusCode());
        self::assertSame('budi.baru@contoh.com', $this->daftar->cariId($this->budi)?->email);
        self::assertSame(RiwayatAkun::UBAH_EMAIL, (new RiwayatAkun($this->db))->untukUser($this->budi)[0]['peristiwa']);
        self::assertStringEndsWith('|sudah', end($this->pengirim->terkirim)->html);
        self::assertSame(410, $this->verifikasi()->handle($this->permintaan('POST', '/verifikasi-email', ['token' => $token]))->getStatusCode());
    }

    public function testAlamatLengkap(): void
    {
        $request = new ServerRequest([], [], 'http://lokal.test:8080/apa');
        self::assertSame('http://lokal.test:8080/atur-sandi?token=x', $this->surat->alamatLengkap($request, '/atur-sandi', ['token' => 'x']));

        $tetap = new SuratUser($this->pengirim, new SuratTemplat(new RendererEmailUji()), 'https://dapur.contoh.com');
        self::assertSame('https://dapur.contoh.com/atur-sandi', $tetap->alamatLengkap($request, '/atur-sandi'));
    }

    public function testTambahEmailLewatKonfirmasi(): void
    {
        $tambah = fn (string $email, string $sandi = self::SANDI) => $this->profil()->handle(
            $this->permintaan('POST', '/pengaturan', ['aksi' => 'tambah_email', 'email_baru' => $email, 'sandi_sekarang' => $sandi], $this->budi),
        );

        self::assertSame(422, $tambah('admin@contoh.com')->getStatusCode(), 'Email akun lain ditolak.');
        self::assertSame('tambah_email', $this->renderer->data['galatBagian']);
        self::assertSame(422, $tambah('budi@contoh.com')->getStatusCode(), 'Email sendiri ditolak.');
        self::assertSame(422, $tambah('budi@kantor.co.id', 'salah')->getStatusCode());
        self::assertSame([], $this->pengirim->terkirim);

        self::assertSame(302, $tambah('Budi@Kantor.co.id')->getStatusCode());
        self::assertCount(1, $this->pengirim->terkirim, 'Tanpa pemberitahuan ke email utama, karena email utama tidak berubah.');
        self::assertSame('budi@kantor.co.id', $this->pengirim->terkirim[0]->kepada);
        self::assertNull($this->daftar->cariEmail('budi@kantor.co.id'), 'Belum bisa dipakai sebelum dikonfirmasi.');

        $token = $this->tokenTerakhir();
        self::assertSame(200, $this->verifikasi()->handle($this->permintaan('GET', '/verifikasi-email', ['token' => $token]))->getStatusCode());
        self::assertTrue($this->renderer->data['tambah']);

        $hasil = $this->verifikasi()->handle($this->permintaan('POST', '/verifikasi-email', ['token' => $token]));
        self::assertSame('/pengaturan?tab=akun', $hasil->getHeaderLine('Location'));
        self::assertSame($this->budi, $this->daftar->cariEmail('budi@kantor.co.id')?->id);
        self::assertSame('budi@contoh.com', $this->daftar->cariId($this->budi)?->email, 'Email utama tidak berubah.');
        self::assertSame(RiwayatAkun::TAMBAH_EMAIL, (new RiwayatAkun($this->db))->untukUser($this->budi)[0]['peristiwa']);
        self::assertSame(410, $this->verifikasi()->handle($this->permintaan('POST', '/verifikasi-email', ['token' => $token]))->getStatusCode());
    }

    public function testFormEmailMemakaiAlamatBukanNomor(): void
    {
        $email = $this->daftar->email();
        $email->tambah($this->budi, 'budi@kantor.co.id');
        $kirim = fn (array $isi) => $this->profil()->handle($this->permintaan('POST', '/pengaturan', $isi, $this->budi));

        self::assertSame(302, $kirim(['aksi' => 'utama_email', 'email' => 'BUDI@kantor.co.id'])->getStatusCode());
        self::assertSame('budi@kantor.co.id', $this->daftar->cariId($this->budi)?->email);
        self::assertSame(422, $kirim(['aksi' => 'hapus_email', 'email' => 'orang@lain.com'])->getStatusCode(), 'email milik orang lain / tidak ada');
        self::assertSame(302, $kirim(['aksi' => 'hapus_email', 'email' => 'budi@contoh.com'])->getStatusCode());
        self::assertNull($email->id($this->budi, 'budi@contoh.com'));
    }

    public function testJadikanUtamaDanHapusEmail(): void
    {
        $email  = $this->daftar->email();
        $kantor = $email->tambah($this->budi, 'budi@kantor.co.id');
        $lama   = (int) $email->id($this->budi, 'budi@contoh.com');
        $kirim  = fn (string $aksi, int $id) => $this->profil()->handle(
            $this->permintaan('POST', '/pengaturan', ['aksi' => $aksi, 'email_id' => (string) $id], $this->budi),
        );

        self::assertSame(422, $kirim('hapus_email', $lama)->getStatusCode(), 'Email utama tidak bisa dihapus.');
        self::assertSame('email_saya', $this->renderer->data['galatBagian']);

        self::assertSame(302, $kirim('utama_email', $kantor)->getStatusCode());
        self::assertSame('budi@kantor.co.id', $this->daftar->cariId($this->budi)?->email);

        self::assertSame(302, $kirim('hapus_email', $lama)->getStatusCode());
        self::assertNull($this->daftar->cariEmail('budi@contoh.com'));

        $adminEmail = (int) $email->id($this->admin, 'admin@contoh.com');
        self::assertSame(404, $kirim('utama_email', $adminEmail)->getStatusCode(), 'Email akun lain tidak bisa disentuh.');
        self::assertSame(422, $kirim('hapus_email', $adminEmail)->getStatusCode());
        self::assertSame('admin@contoh.com', $this->daftar->cariId($this->admin)?->email);

        $peristiwa = array_column((new RiwayatAkun($this->db))->untukUser($this->budi), 'peristiwa');
        self::assertSame([RiwayatAkun::HAPUS_EMAIL, RiwayatAkun::EMAIL_UTAMA], $peristiwa);

        // Data halaman: daftar email dengan email utama lebih dulu.
        $this->profil()->handle($this->permintaan('GET', '/pengaturan', [], $this->budi));
        self::assertSame(['budi@kantor.co.id'], array_column($this->renderer->data['daftarEmail'], 'email'));
    }

    public function testWebsiteKantorMewajibkanEmailKantor(): void
    {
        $kantor = new Pintu('kantor.co.id', '/dashboard', 3600, 'layout::uji');
        $domain = new BatasanDomain(['kantor.co.id']);
        $email  = $this->daftar->email();
        $idKantor = $email->tambah($this->budi, 'budi@kantor.co.id');

        // Kelola user: hanya email kantor; email kantor milik akun yang ada -> akun itu diberi akses.
        $kelola = new KelolaUserHandler(
            $this->daftar, new RiwayatAkun($this->db), new PenyajiDasar($this->renderer, $kantor), $kantor,
            new DateTimeZone('Asia/Jakarta'), false, KelolaUserHandler::TEMPLATE, $this->token, $this->surat, 'Kantor', $domain,
        );
        $tambah = fn (string $alamat) => $kelola->handle($this->permintaan(
            'POST', '/dashboard/user', ['aksi' => 'tambah', 'email' => $alamat, 'nama' => 'Siapa', 'peran' => DaftarUser::PERAN_ANGGOTA], $this->admin,
        ));
        self::assertSame(422, $tambah('budi@contoh.com')->getStatusCode());
        self::assertStringContainsString('@kantor.co.id', (string) $this->renderer->data['galat']);
        self::assertSame(302, $tambah('budi@kantor.co.id')->getStatusCode());
        self::assertSame('budi@kantor.co.id sudah terdaftar; akses sebagai anggota ditambahkan.', $this->sesi->ambil(ProfilHandler::SESI_PESAN));
        self::assertSame('budi@kantor.co.id', $email->emailLingkup($this->budi, 'kantor.co.id'));
        // Rekan di website kantor melihat email kantor Budi (admin super tampil otomatis dengan email utamanya).
        self::assertSame(['admin@contoh.com', 'budi@kantor.co.id'], array_column($this->daftar->userDiLingkup('kantor.co.id'), 'email'));

        // Email untuk website ini: email pribadi ditolak, email kantor diterima.
        $profil = new ProfilHandler(
            $this->daftar, new PembatasPercobaan($this->db), new RiwayatMasuk($this->db), new PenyajiDasar($this->renderer, $kantor),
            $kantor, new DateTimeZone('Asia/Jakarta'), ProfilHandler::TEMPLATE_PENGATURAN, new RiwayatAkun($this->db),
            $this->token, $this->surat, 'Kantor', ProfilHandler::HALAMAN_PENGATURAN, $domain,
        );
        $pilih = fn (string $id) => $profil->handle($this->permintaan('POST', '/dashboard/pengaturan', ['aksi' => 'email_website', 'email_id' => $id], $this->budi));
        $idPribadi = (string) $email->id($this->budi, 'budi@contoh.com');
        self::assertSame(422, $pilih($idPribadi)->getStatusCode());
        self::assertSame('budi@kantor.co.id', $email->emailLingkup($this->budi, 'kantor.co.id'));
        self::assertSame(302, $pilih((string) $idKantor)->getStatusCode());

        $profil->handle($this->permintaan('GET', '/dashboard/pengaturan', [], $this->budi));
        self::assertTrue($this->renderer->data['bisaPilihEmailWebsite']);
        self::assertSame($idKantor, $this->renderer->data['emailWebsiteId']);
        self::assertSame([false, true], array_column($this->renderer->data['daftarEmail'], 'bisaDipilih'));
        self::assertSame('@kantor.co.id', $this->renderer->data['domainEmail']);

        // Lupa kata sandi di website kantor: email pribadi tidak dikirimi, email kantor dikirimi ke alamat itu.
        $lupa = new LupaSandiHandler($this->daftar, $this->token, $this->surat, new PembatasPercobaan($this->db), new HalamanPublik($this->renderer, $kantor), $kantor, 'Kantor', $domain);
        $lupa->handle($this->permintaan('POST', '/dashboard/lupa-sandi', ['email' => 'budi@contoh.com']));
        self::assertTrue($this->renderer->data['terkirim']);
        self::assertSame([], $this->pengirim->terkirim);
        $lupa->handle($this->permintaan('POST', '/dashboard/lupa-sandi', ['email' => 'budi@kantor.co.id']));
        self::assertSame('budi@kantor.co.id', $this->pengirim->terkirim[0]->kepada);
    }

    public function testLupaSandiLewatEmailTambahanDikirimKeEmailItu(): void
    {
        $this->daftar->email()->tambah($this->budi, 'budi@lain.com');

        $this->lupa()->handle($this->permintaan('POST', '/lupa-sandi', ['email' => 'budi@lain.com']));

        self::assertSame('budi@lain.com', $this->pengirim->terkirim[0]->kepada);
    }
}
