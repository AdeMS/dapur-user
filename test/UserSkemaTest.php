<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\Pemasang;
use AdeMS\Dapur\User\ConfigProvider;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\NamaTabel;
use AdeMS\Dapur\User\Pengaturan;
use AdeMS\Dapur\User\Skema;
use AdeMS\Dapur\User\UserSkema;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

final class UserSkemaTest extends TestCase
{
    public function testTerdaftarUntukPhpDapurPasang(): void
    {
        self::assertSame([UserSkema::class], (new ConfigProvider())()['dapur']['skema']);
    }

    public function testDatabaseKosongDipasangLengkapLaluTidakAdaPerubahan(): void
    {
        $db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        $p  = new Pemasang(new KonteksSkema($db, 'xyz_'), [new UserSkema()]);

        $p->jalankan($p->rencana());

        $tabel = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name", Adapter::QUERY_MODE_EXECUTE)->toArray();
        $nama  = array_column($tabel, 'name');
        foreach (array_keys(Skema::tabel('SQLite', new NamaTabel('xyz_'))) as $t) {
            self::assertContains($t, $nama);
        }
        self::assertContains('xyz_dapur_skema', $nama);
        self::assertSame([], $p->rencana(), 'Sesudah dipasang, tidak ada perubahan tersisa.');

        $langkah = $db->query('SELECT modul, langkah FROM "xyz_dapur_skema" ORDER BY langkah', Adapter::QUERY_MODE_EXECUTE)->toArray();
        self::assertSame(
            [['modul' => 'user', 'langkah' => '2.0-peran-admin'], ['modul' => 'user', 'langkah' => '2.2-email-utama'], ['modul' => 'user', 'langkah' => '2.6-kode-user']],
            $langkah,
        );
    }

    public function testLangkahDataMengubahPeranLamaSekaliSaja(): void
    {
        $db = new Adapter(['driver' => 'Pdo_Sqlite', 'database' => ':memory:']);
        foreach (Skema::perintah('SQLite') as $sql) {
            $db->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }

        $daftar = new DaftarUser($db);
        $id     = $daftar->buat('admin@contoh.com', 'Admin', 'kata-sandi-panjang');
        $db->query("INSERT INTO \"user_akses\" (user_id, lingkup, peran) VALUES ({$id}, 'dashboard', 'admin')", Adapter::QUERY_MODE_EXECUTE);

        $p       = new Pemasang(new KonteksSkema($db), [new UserSkema()]);
        $laporan = [];
        $p->jalankan($p->rencana(), static function ($x, ?string $ket) use (&$laporan): void {
            $laporan[$x->nama] = $ket;
        });

        self::assertSame('peran admin lama diperbarui: 1 akses', $laporan['2.0-peran-admin']);
        self::assertSame(DaftarUser::PERAN_ADMIN_SUPER, $daftar->peranTersimpan($id, 'dashboard'));
        self::assertSame([], $p->rencana());
    }

    public function testAwalanBersamaMenangAtasAwalanUserLama(): void
    {
        self::assertSame('lama_', Pengaturan::configUser(['user' => ['awalan_tabel' => 'lama_']])['awalan_tabel']);
        self::assertSame(
            'baru_',
            Pengaturan::configUser(['user' => ['awalan_tabel' => 'lama_'], 'database' => ['awalan_tabel' => 'baru_']])['awalan_tabel'],
        );
        self::assertSame('baru_', Pengaturan::configUser(['database' => ['awalan_tabel' => 'baru_']])['awalan_tabel']);
        self::assertSame([], Pengaturan::configUser(null));
    }
}
