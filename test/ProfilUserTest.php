<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\ProfilUser;
use AdeMS\Dapur\User\User;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class ProfilUserTest extends TestCase
{
    private DaftarUser $daftar;
    private ProfilUser $profil;
    private int $ade;

    protected function setUp(): void
    {
        $this->daftar = new DaftarUser(DatabaseUji::baru());
        $this->profil = $this->daftar->profil();
        $this->ade    = $this->daftar->buat('ade@contoh.com', 'Ade', 'sandi-uji-yang-panjang');
    }

    public function testSimpanHanyaYangBerubahDanIsianKosongDihapus(): void
    {
        self::assertTrue($this->profil->tersedia());
        self::assertSame(['lokasi', 'biografi'], $this->profil->simpan($this->ade, '', ['lokasi' => ' Medan ', 'biografi' => "Baris 1\nBaris 2", 'keahlian' => '']));
        self::assertEquals(['lokasi' => 'Medan', 'biografi' => "Baris 1\nBaris 2"], $this->profil->ambil($this->ade));

        self::assertSame([], $this->profil->simpan($this->ade, '', ['lokasi' => 'Medan']), 'Tidak berubah.');
        self::assertSame(['lokasi'], $this->profil->simpan($this->ade, '', ['lokasi' => '']));
        self::assertSame(['biografi' => "Baris 1\nBaris 2"], $this->profil->ambil($this->ade));
    }

    public function testProfilPerWebsiteTerpisahDariTentang(): void
    {
        $this->profil->simpan($this->ade, 'kantor.co.id', [ProfilUser::NAMA => 'Pak Ade']);
        $budi = $this->daftar->buat('budi@contoh.com', 'Budi', 'sandi-uji-yang-panjang');
        $this->profil->simpan($budi, 'kantor.co.id', [ProfilUser::NAMA => 'Mas Budi']);

        self::assertSame([], $this->profil->ambil($this->ade));
        self::assertSame([$this->ade => 'Pak Ade', $budi => 'Mas Budi'], $this->profil->untukLingkup('kantor.co.id', ProfilUser::NAMA));
        self::assertSame([], $this->profil->untukLingkup('lain.com', ProfilUser::NAMA));
    }

    public function testPemilikFoto(): void
    {
        $akun    = str_repeat('a', 32);
        $website = str_repeat('b', 32);
        $this->daftar->aturFoto($this->ade, $akun);
        $this->profil->simpan($this->ade, 'kantor.co.id', [ProfilUser::FOTO => $website]);

        self::assertSame([$this->ade, ''], $this->profil->pemilikFoto($akun));
        self::assertSame([$this->ade, 'kantor.co.id'], $this->profil->pemilikFoto($website));
        self::assertNull($this->profil->pemilikFoto(str_repeat('c', 32)));
        self::assertNull($this->profil->pemilikFoto('../rahasia'));
        self::assertSame([$this->ade => $akun], $this->profil->fotoAkun());
        self::assertSame($akun, $this->daftar->cariId($this->ade)?->foto);
    }

    public function testNamaLengkapDariNamaDepanTengahBelakang(): void
    {
        $this->daftar->gantiNamaLengkap($this->ade, ' Ade ', 'M', ' Saragih');
        $user = $this->daftar->cariId($this->ade);

        self::assertSame('Ade M Saragih', $user?->nama);
        self::assertSame(['Ade', 'M', 'Saragih'], [$user->namaDepan, $user->namaTengah, $user->namaBelakang]);
        self::assertSame('AS', $user->inisial());
        self::assertSame('Ade Saragih', User::gabungNama('Ade', '', 'Saragih'));
        self::assertSame('PA', User::inisialDari('Pak Ade'));
    }
}
