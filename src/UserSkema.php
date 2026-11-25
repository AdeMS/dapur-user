<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use AdeMS\Dapur\Database\KonteksSkema;
use AdeMS\Dapur\Database\SkemaModul;

/**
 * Skema modul user untuk `php dapur pasang` (adems/dapur-database). Isi tabel dan kolomnya ada di kelas Skema.
 *
 * Nama langkah di bawah tidak boleh diganti: dipakai untuk mencatat bahwa langkah itu sudah dijalankan.
 */
final class UserSkema implements SkemaModul
{
    public const NAMA = 'user';

    public function nama(): string
    {
        return self::NAMA;
    }

    public function paket(): string
    {
        return 'adems/dapur-user';
    }

    public function butuh(): array
    {
        return [];
    }

    public function tabel(KonteksSkema $k): array
    {
        return Skema::tabel($k->platform, new NamaTabel($k->awalan));
    }

    public function kolom(KonteksSkema $k): array
    {
        return Skema::kolom($k->platform, new NamaTabel($k->awalan));
    }

    public function indeks(KonteksSkema $k): array
    {
        $t    = new NamaTabel($k->awalan);
        $nama = 'uq_' . $k->awalan . 'user_kode';

        return [
            // 2.6: kode akun unik (boleh kosong sementara, sebelum langkah 2.6-kode-user mengisinya).
            $nama => sprintf('CREATE UNIQUE INDEX %s ON %s (kode)', $k->kutip($nama), $k->kutip($t->user())),
        ];
    }

    public function langkah(KonteksSkema $k): array
    {
        return [
            // 2.0: peran 'admin' dari versi 1.x menjadi admin super (dashboard utama) atau admin web (website).
            '2.0-peran-admin' => static function (KonteksSkema $k): ?string {
                $diubah = (new DaftarUser($k->db, new NamaTabel($k->awalan)))->perbaruiPeranLama();

                return $diubah > 0 ? "peran admin lama diperbarui: {$diubah} akses" : null;
            },
            // 2.6: akun lama diberi kode acak untuk alamat dan form.
            '2.6-kode-user' => static function (KonteksSkema $k): ?string {
                $diisi = (new DaftarUser($k->db, new NamaTabel($k->awalan)))->isiKodeKosong();

                return $diisi > 0 ? "kode akun dibuat: {$diisi} akun" : null;
            },
            // 2.2: email utama akun lama dicatat di tabel email, agar bisa dipakai untuk masuk.
            '2.2-email-utama' => static function (KonteksSkema $k): ?string {
                $disalin = (new EmailUser($k->db, new NamaTabel($k->awalan)))->salinEmailUtama();

                return $disalin > 0 ? "email utama dicatat di tabel email: {$disalin} akun" : null;
            },
        ];
    }
}
