<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\EmailUser;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class EmailUserTest extends TestCase
{
    private const SANDI = 'sandi-uji-yang-panjang';

    private Adapter $db;
    private DaftarUser $daftar;
    private EmailUser $email;
    private int $ade;

    protected function setUp(): void
    {
        $this->db     = DatabaseUji::baru();
        $this->daftar = new DaftarUser($this->db);
        $this->email  = new EmailUser($this->db);
        $this->ade    = $this->daftar->buat('ade@pribadi.com', 'Ade', self::SANDI);
    }

    public function testAkunBaruLangsungTercatatDiTabelEmail(): void
    {
        self::assertTrue($this->email->tersedia());
        self::assertSame($this->ade, $this->email->pemilik('ADE@pribadi.com '));
        self::assertSame([['id' => 1, 'email' => 'ade@pribadi.com', 'utama' => true]], array_map(
            static fn (array $e): array => ['id' => $e['id'], 'email' => $e['email'], 'utama' => $e['utama']],
            $this->email->daftar($this->ade),
        ));
    }

    public function testLoginBisaDenganEmailTambahan(): void
    {
        $this->email->tambah($this->ade, 'ade@kantor.co.id');

        self::assertSame($this->ade, $this->daftar->cariEmail('Ade@Kantor.co.id')?->id);
        self::assertSame('ade@pribadi.com', $this->daftar->cariEmail('ade@kantor.co.id')?->email, 'Email akun tetap email utama.');
        self::assertNull($this->daftar->cariEmail('lain@kantor.co.id'));
    }

    public function testJadikanUtamaDanHapus(): void
    {
        $kantor = $this->email->tambah($this->ade, 'ade@kantor.co.id');
        $utama  = (int) $this->email->id($this->ade, 'ade@pribadi.com');

        self::assertNull($this->email->hapus($this->ade, $utama), 'Email utama tidak bisa dihapus.');

        self::assertSame('ade@kantor.co.id', $this->email->jadikanUtama($this->ade, $kantor));
        self::assertSame('ade@kantor.co.id', $this->daftar->cariId($this->ade)?->email);
        self::assertTrue($this->email->daftar($this->ade)[0]['utama']);
        self::assertSame('ade@kantor.co.id', $this->email->daftar($this->ade)[0]['email']);

        self::assertSame('ade@pribadi.com', $this->email->hapus($this->ade, $utama));
        self::assertNull($this->daftar->cariEmail('ade@pribadi.com'));
    }

    public function testEmailMilikAkunLainTidakBisaDisentuh(): void
    {
        $budi   = $this->daftar->buat('budi@contoh.com', 'Budi', self::SANDI);
        $milikB = (int) $this->email->id($budi, 'budi@contoh.com');

        self::assertNull($this->email->jadikanUtama($this->ade, $milikB));
        self::assertNull($this->email->hapus($this->ade, $milikB));
        self::assertFalse($this->email->aturEmailLingkup($this->ade, 'kantor.co.id', $milikB));
        self::assertSame('budi@contoh.com', $this->daftar->cariId($budi)?->email);
    }

    public function testEmailPerLingkup(): void
    {
        $kantor = $this->email->tambah($this->ade, 'ade@kantor.co.id');
        $this->daftar->tambahAkses($this->ade, 'kantor.co.id', DaftarUser::PERAN_ANGGOTA);
        $this->daftar->tambahAkses($this->ade, 'hobi.com', DaftarUser::PERAN_ANGGOTA);

        self::assertSame('ade@pribadi.com', $this->email->emailLingkup($this->ade, 'kantor.co.id'));
        self::assertTrue($this->email->aturEmailLingkup($this->ade, 'kantor.co.id', $kantor));
        self::assertSame('ade@kantor.co.id', $this->email->emailLingkup($this->ade, 'kantor.co.id'));
        self::assertSame($kantor, $this->email->emailIdLingkup($this->ade, 'kantor.co.id'));
        self::assertSame('ade@pribadi.com', $this->email->emailLingkup($this->ade, 'hobi.com'));

        // Daftar user di website kantor menampilkan email kantor, website lain email utama.
        self::assertSame('ade@kantor.co.id', $this->daftar->userDiLingkup('kantor.co.id')[0]['email']);
        self::assertSame('ade@pribadi.com', $this->daftar->userDiLingkup('hobi.com')[0]['email']);

        // Email yang dihapus membuat website itu kembali memakai email utama.
        $this->email->hapus($this->ade, $kantor);
        self::assertSame('ade@pribadi.com', $this->email->emailLingkup($this->ade, 'kantor.co.id'));
        self::assertNull($this->email->emailIdLingkup($this->ade, 'kantor.co.id'));
    }

    public function testTambahAksesDenganEmailTertentu(): void
    {
        $this->email->tambah($this->ade, 'ade@kantor.co.id');
        $this->daftar->tambahAkses($this->ade, 'kantor.co.id', DaftarUser::PERAN_ANGGOTA, 'ade@kantor.co.id');

        self::assertSame('ade@kantor.co.id', $this->email->emailLingkup($this->ade, 'kantor.co.id'));
    }

    public function testGantiEmailMenggantiEmailUtamaDanMelepasYangLama(): void
    {
        $this->email->tambah($this->ade, 'ade@kantor.co.id');

        $this->daftar->gantiEmail($this->ade, 'ade@baru.com');

        self::assertSame('ade@baru.com', $this->daftar->cariId($this->ade)?->email);
        self::assertNull($this->email->pemilik('ade@pribadi.com'), 'Email utama lama dilepas.');
        self::assertSame($this->ade, $this->email->pemilik('ade@kantor.co.id'), 'Email tambahan lain tetap.');
        self::assertSame(['ade@baru.com', 'ade@kantor.co.id'], array_column($this->email->daftar($this->ade), 'email'));
    }

    public function testSalinEmailUtamaAmanDiulang(): void
    {
        self::assertSame(0, $this->email->salinEmailUtama());
        $this->db->query('DELETE FROM "user_email"', Adapter::QUERY_MODE_EXECUTE);

        self::assertSame(1, $this->email->salinEmailUtama());
        self::assertSame(0, $this->email->salinEmailUtama());
    }

    public function testRiwayatAkunWebsiteMemakaiEmailWebsite(): void
    {
        $riwayat = new \AdeMS\Dapur\User\RiwayatAkun($this->db);
        $budi    = $this->daftar->buat('budi@pribadi.com', 'Budi', self::SANDI);
        $this->email->tambah($this->ade, 'ade@kantor.co.id');
        $this->daftar->tambahAkses($this->ade, 'kantor.co.id', DaftarUser::PERAN_ADMIN_WEB, 'ade@kantor.co.id');
        $this->daftar->tambahAkses($budi, 'kantor.co.id', DaftarUser::PERAN_ANGGOTA);
        $riwayat->catat(\AdeMS\Dapur\User\RiwayatAkun::BERI_AKSES, $this->ade, $budi, 'kantor.co.id', 'peran anggota');

        $baris = $riwayat->untukLingkup('kantor.co.id')[0];
        self::assertSame('ade@kantor.co.id', $baris['pelaku'], 'Pelaku tampil dengan email kantornya.');
        self::assertSame('budi@pribadi.com', $baris['sasaran'], 'Budi belum memilih email lain.');

        // Sesudah akses dicabut, nama yang tampil, bukan email utama.
        $this->daftar->cabutAkses($budi, 'kantor.co.id');
        self::assertSame('Budi', $riwayat->untukLingkup('kantor.co.id')[0]['sasaran']);

        // Riwayat milik user sendiri tetap memakai email utama.
        self::assertSame('ade@pribadi.com', $riwayat->untukUser($budi)[0]['pelaku']);
    }
}
