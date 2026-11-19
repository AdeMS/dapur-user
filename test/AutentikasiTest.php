<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\Autentikasi;
use AdeMS\Dapur\User\BatasanDomain;
use AdeMS\Dapur\User\EmailUser;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\HasilMasuk;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\RiwayatMasuk;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class AutentikasiTest extends TestCase
{
    private const SANDI = 'kata-sandi-yang-panjang';
    private const IP    = '192.0.2.1';

    private Adapter $db;
    private DaftarUser $daftar;
    private Autentikasi $autentikasi;
    private RiwayatMasuk $riwayat;
    private int $id;

    protected function setUp(): void
    {
        $this->db          = DatabaseUji::baru();
        $this->daftar      = new DaftarUser($this->db);
        $this->riwayat     = new RiwayatMasuk($this->db);
        $this->autentikasi = new Autentikasi($this->daftar, new PembatasPercobaan($this->db), $this->riwayat);
        $this->id          = $this->daftar->buat('Admin@Contoh.com', 'Admin', self::SANDI);
        $this->daftar->tambahAkses($this->id, 'dashboard');
    }

    private function masuk(string $sandi = self::SANDI, string $email = 'admin@contoh.com', string $lingkup = 'dashboard', string $ip = self::IP): HasilMasuk
    {
        return $this->autentikasi->masuk($email, $sandi, $ip, $lingkup, 'Browser Uji');
    }

    public function testWebsiteDenganBatasanDomainHanyaMenerimaEmailDomainItu(): void
    {
        $budi = $this->daftar->buat('budi@pribadi.com', 'Budi', self::SANDI);
        (new EmailUser($this->db))->tambah($budi, 'budi@kantor.co.id');
        $this->daftar->tambahAkses($budi, 'kantor.co.id', DaftarUser::PERAN_ANGGOTA);
        $kantor = new Autentikasi($this->daftar, new PembatasPercobaan($this->db), $this->riwayat, new BatasanDomain(['kantor.co.id']));

        // Kata sandi salah tetap "gagal" biasa, agar email tidak bisa ditebak.
        self::assertSame(HasilMasuk::GAGAL, $kantor->masuk('budi@pribadi.com', 'salah-salah-salah', self::IP, 'kantor.co.id')->status);
        self::assertSame(HasilMasuk::DOMAIN, $kantor->masuk('budi@pribadi.com', self::SANDI, self::IP, 'kantor.co.id')->status);
        $terakhir = $this->riwayat->untukUser($budi)[0];
        self::assertSame([RiwayatMasuk::DITOLAK_DOMAIN, 'budi@pribadi.com'], [$terakhir['peristiwa'], $terakhir['email']]);
        self::assertSame('Ditolak (domain)', RiwayatMasuk::label(RiwayatMasuk::DITOLAK_DOMAIN));
        self::assertSame(HasilMasuk::BERHASIL, $kantor->masuk('Budi@Kantor.co.id', self::SANDI, self::IP, 'kantor.co.id')->status);
        self::assertSame('@kantor.co.id', $kantor->batasan()->teks());

        // Admin super tidak dibatasi.
        self::assertSame(HasilMasuk::BERHASIL, $kantor->masuk('admin@contoh.com', self::SANDI, self::IP, 'kantor.co.id')->status);
    }

    public function testRiwayatMencatatEmailYangDipakai(): void
    {
        (new EmailUser($this->db))->tambah($this->id, 'admin@kantor.co.id');

        $this->masuk(email: 'Admin@Kantor.co.id');
        $this->masuk('salah-salah-salah');
        $this->autentikasi->masuk('tidak@terdaftar.com', 'salah-salah-salah', self::IP, 'dashboard');

        $riwayat = $this->riwayat->untukUser($this->id);
        self::assertSame(['admin@contoh.com', 'admin@kantor.co.id'], [$riwayat[0]['email'], $riwayat[1]['email']]);
        self::assertSame([RiwayatMasuk::GAGAL, RiwayatMasuk::MASUK], [$riwayat[0]['peristiwa'], $riwayat[1]['peristiwa']]);

        // Email yang tidak terdaftar tidak disimpan.
        $baris = $this->db->query('SELECT email FROM "user_riwayat_masuk" WHERE user_id IS NULL', [])->toArray();
        self::assertSame([['email' => '']], $baris);
    }

    public function testBatasanDomain(): void
    {
        $b = new BatasanDomain(['@Kantor.co.id ', 'cabang.co.id']);
        self::assertTrue($b->cocok('ade@KANTOR.co.id'));
        self::assertFalse($b->cocok('ade@kantor.co.id.palsu.com'));
        self::assertFalse($b->cocok('ade@sub.kantor.co.id'));
        self::assertSame('@kantor.co.id atau @cabang.co.id', $b->teks());
        self::assertTrue((new BatasanDomain())->cocok('siapa@saja.com'));

        $this->expectException(\RuntimeException::class);
        BatasanDomain::dariPengaturan(['bukan domain']);
    }

    public function testBerhasilDanEmailTidakPekaHurufBesar(): void
    {
        $hasil = $this->masuk(email: ' ADMIN@contoh.com ');

        self::assertSame(HasilMasuk::BERHASIL, $hasil->status);
        self::assertSame($this->id, $hasil->user?->id);
    }

    public function testKataSandiSalahGagal(): void
    {
        self::assertSame(HasilMasuk::GAGAL, $this->masuk('salah')->status);
    }

    public function testEmailTidakAdaGagal(): void
    {
        self::assertSame(HasilMasuk::GAGAL, $this->masuk(email: 'lain@contoh.com')->status);
    }

    public function testIsianKosongGagal(): void
    {
        self::assertSame(HasilMasuk::GAGAL, $this->masuk('', '')->status);
    }

    public function testUserNonaktifGagal(): void
    {
        $this->db->query('UPDATE "user" SET aktif = 0 WHERE id = ?', [$this->id]);

        self::assertSame(HasilMasuk::GAGAL, $this->masuk()->status);
    }

    public function testTanpaAksesKeLingkupGagal(): void
    {
        $this->daftar->ubahPeran($this->id, 'dashboard', DaftarUser::PERAN_ANGGOTA);

        self::assertSame(HasilMasuk::GAGAL, $this->masuk(lingkup: 'blog.contoh.com')->status);
    }

    public function testAdminSuperBisaMasukKeSemuaWebsite(): void
    {
        self::assertSame(HasilMasuk::BERHASIL, $this->masuk(lingkup: 'blog.contoh.com')->status);
    }

    public function testTerkunciSetelahBatasWalaupunSandiBenar(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            self::assertSame(HasilMasuk::GAGAL, $this->masuk('salah')->status);
        }

        self::assertSame(HasilMasuk::DIBATASI, $this->masuk()->status);
    }

    public function testIpLainTidakIkutTerkunci(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->masuk('salah');
        }

        self::assertSame(HasilMasuk::BERHASIL, $this->masuk(ip: '192.0.2.2')->status);
    }

    public function testBerhasilMembersihkanCatatanGagal(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP - 1; $i++) {
            $this->masuk('salah');
        }
        $this->masuk();

        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP - 1; $i++) {
            self::assertSame(HasilMasuk::GAGAL, $this->masuk('salah')->status);
        }
        self::assertSame(HasilMasuk::BERHASIL, $this->masuk()->status);
    }

    public function testCatatanLamaTidakDihitung(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->masuk('salah');
        }
        $this->db->query('UPDATE "user_percobaan_masuk" SET waktu = ?', [gmdate('Y-m-d H:i:s', time() - PembatasPercobaan::JENDELA_DETIK - 1)]);

        self::assertSame(HasilMasuk::BERHASIL, $this->masuk()->status);
    }

    public function testEmailTidakDisimpanApaAdanya(): void
    {
        $this->masuk('salah', 'rahasia@contoh.com');

        $baris = $this->db->query('SELECT kunci FROM "user_percobaan_masuk"', [])->toArray();
        self::assertSame(hash('sha256', 'rahasia@contoh.com'), $baris[0]['kunci']);
    }

    public function testRiwayatMencatatBerhasilDanGagal(): void
    {
        $this->masuk('salah');
        $this->masuk();

        $baris = $this->riwayat->untukUser($this->id);
        self::assertSame([RiwayatMasuk::MASUK, RiwayatMasuk::GAGAL], array_column($baris, 'peristiwa'));
        self::assertSame(['dashboard', 'dashboard'], array_column($baris, 'lingkup'));
        self::assertSame(self::IP, $baris[0]['ip']);
        self::assertSame('Browser Uji', $baris[0]['perangkat']);
    }

    public function testRiwayatEmailTakTerdaftarTanpaUserDanTanpaEmail(): void
    {
        $this->masuk('salah', 'rahasia@contoh.com');

        $baris = $this->db->query('SELECT * FROM "user_riwayat_masuk"', [])->toArray();
        self::assertCount(1, $baris);
        self::assertNull($baris[0]['user_id']);
        self::assertSame(RiwayatMasuk::GAGAL, $baris[0]['peristiwa']);
        self::assertStringNotContainsString('rahasia', implode(' ', array_map('strval', $baris[0])));
    }

    public function testPercobaanYangDibatasiTidakMasukRiwayat(): void
    {
        for ($i = 0; $i < PembatasPercobaan::BATAS_AKUN_IP; $i++) {
            $this->masuk('salah');
        }
        self::assertSame(HasilMasuk::DIBATASI, $this->masuk()->status);

        self::assertCount(PembatasPercobaan::BATAS_AKUN_IP, $this->riwayat->untukUser($this->id, 100));
    }

    public function testKataSandiDisimpanSebagaiHash(): void
    {
        $user = $this->daftar->cariId($this->id);

        self::assertNotSame(self::SANDI, $user?->kataSandi);
        self::assertTrue(password_verify(self::SANDI, (string) $user?->kataSandi));
        self::assertFalse(DaftarUser::perluHashBaru((string) $user?->kataSandi));
    }

    public function testHashLamaDiperbaruiDanUserYangDikembalikanMemakaiHashBaru(): void
    {
        $lama = password_hash(self::SANDI, PASSWORD_BCRYPT, ['cost' => 10]);
        $this->db->query('UPDATE "user" SET kata_sandi = ? WHERE id = ?', [$lama, $this->id]);

        $hasil = $this->masuk();
        $baru  = $this->daftar->cariId($this->id);

        self::assertSame(HasilMasuk::BERHASIL, $hasil->status);
        self::assertNotSame($lama, $baru?->kataSandi);
        self::assertSame($baru?->kataSandi, $hasil->user?->kataSandi);
    }
}
