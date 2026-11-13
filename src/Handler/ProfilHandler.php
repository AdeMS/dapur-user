<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\AturanKataSandi;
use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\Middleware\WajibMasukMiddleware;
use AdeMS\Dapur\User\PembatasPercobaan;
use AdeMS\Dapur\User\PenyajiHalaman;
use AdeMS\Dapur\User\Perangkat;
use AdeMS\Dapur\User\Pintu;
use AdeMS\Dapur\User\RiwayatAkun;
use AdeMS\Dapur\User\RiwayatMasuk;
use AdeMS\Dapur\User\Sesi;
use AdeMS\Dapur\User\TokenCsrf;
use AdeMS\Dapur\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Halaman profil user yang sedang login: mengubah nama, mengganti kata sandi, dan melihat riwayat login
 * sendiri. Dipasang di belakang WajibMasukMiddleware.
 *
 * Setelah kata sandi diganti, session di perangkat lain otomatis tidak berlaku (lihat
 * WajibMasukMiddleware::SESI_SANDI); session yang dipakai sekarang tetap login dengan ID baru.
 */
final class ProfilHandler implements RequestHandlerInterface
{
    public const TEMPLATE = 'user::profil';

    /** Pesan berhasil yang ditampilkan sekali setelah pengalihan. */
    public const SESI_PESAN = 'user.pesan';

    public const JUMLAH_RIWAYAT = 20;

    private const PANJANG_NAMA_MAKS  = 190;
    private const PANJANG_SANDI_MAKS = 1024;

    public function __construct(
        private readonly DaftarUser $daftar,
        private readonly PembatasPercobaan $pembatas,
        private readonly RiwayatMasuk $riwayat,
        private readonly PenyajiHalaman $penyaji,
        private readonly Pintu $pintu,
        private readonly DateTimeZone $zonaWaktu,
        private readonly string $template = self::TEMPLATE,
        private readonly ?RiwayatAkun $riwayatAkun = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sesi = $request->getAttribute(Sesi::class);
        $user = $request->getAttribute(User::class);
        assert($sesi instanceof Sesi && $user instanceof User);

        if ($request->getMethod() !== 'POST') {
            $pesan = $sesi->ambil(self::SESI_PESAN);
            $sesi->hapus(self::SESI_PESAN);

            return $this->halaman($request, $user, ['pesan' => is_string($pesan) ? $pesan : null]);
        }

        $isi = (array) $request->getParsedBody();
        if (! (new TokenCsrf($sesi))->sah($isi[TokenCsrf::FIELD] ?? null)) {
            return $this->galat($request, $user, null, 'Halaman sudah kedaluwarsa. Silakan coba lagi.', 400);
        }

        return match ($isi['aksi'] ?? null) {
            'nama'  => $this->gantiNama($request, $user, $sesi, $isi),
            'sandi' => $this->gantiSandi($request, $user, $sesi, $isi),
            default => $this->galat($request, $user, null, 'Permintaan tidak dikenal.', 400),
        };
    }

    /** @param array<mixed> $isi */
    private function gantiNama(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $nama = is_string($isi['nama'] ?? null) ? trim($isi['nama']) : '';
        if ($nama === '' || mb_strlen($nama, 'UTF-8') > self::PANJANG_NAMA_MAKS
            || preg_match('/[\x00-\x1f\x7f]/u', $nama) !== 0) {
            return $this->galat(
                $request,
                $user,
                'nama',
                sprintf('Nama wajib diisi, paling panjang %d karakter.', self::PANJANG_NAMA_MAKS),
                422,
                ['nama' => is_string($isi['nama'] ?? null) ? $isi['nama'] : ''],
            );
        }

        if ($this->riwayatAkun !== null && ! $this->riwayatAkun->tersedia()) {
            return $this->galat($request, $user, 'nama', RiwayatAkun::PESAN_BELUM_DIPASANG, 503, ['nama' => $nama]);
        }

        if ($nama !== $user->nama) {
            $this->daftar->gantiNama($user->id, $nama);
            $this->riwayatAkun?->catat(
                RiwayatAkun::UBAH_NAMA,
                $user->id,
                $user->id,
                $this->pintu->lingkup,
                $user->nama . ' → ' . $nama,
                (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                $request->getHeaderLine('User-Agent'),
            );
        }

        $sesi->simpan(self::SESI_PESAN, 'Nama sudah disimpan.');

        return new RedirectResponse($this->pintu->alamatProfil());
    }

    /** @param array<mixed> $isi */
    private function gantiSandi(ServerRequestInterface $request, User $user, Sesi $sesi, array $isi): ResponseInterface
    {
        $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $lama  = is_string($isi['sandi_lama'] ?? null) ? $isi['sandi_lama'] : '';
        $baru  = is_string($isi['sandi_baru'] ?? null) ? $isi['sandi_baru'] : '';
        $ulang = is_string($isi['sandi_ulang'] ?? null) ? $isi['sandi_ulang'] : '';

        // Kata sandi lama yang salah dihitung seperti login gagal, agar tidak bisa ditebak berulang-ulang
        // oleh orang yang sempat memakai session ini.
        if ($this->pembatas->terkunci($user->email, $ip)) {
            return $this->galat($request, $user, 'sandi', 'Terlalu banyak percobaan. Silakan coba lagi nanti.', 429);
        }

        if ($lama === '' || strlen($lama) > self::PANJANG_SANDI_MAKS || ! password_verify($lama, $user->kataSandi)) {
            $this->pembatas->catat($user->email, $ip);

            return $this->galat($request, $user, 'sandi', 'Kata sandi lama salah.', 422);
        }

        $minimal = AturanKataSandi::panjangMinimal($this->daftar->adalahAdmin($user->id));
        if (strlen($baru) < $minimal || strlen($baru) > self::PANJANG_SANDI_MAKS) {
            return $this->galat($request, $user, 'sandi', sprintf('Kata sandi baru minimal %d karakter.', $minimal), 422);
        }

        if (! hash_equals($baru, $ulang)) {
            return $this->galat($request, $user, 'sandi', 'Kedua kata sandi baru tidak sama.', 422);
        }

        $this->daftar->gantiSandi($user->id, $baru);
        $this->pembatas->bersihkan($user->email, $ip);
        $this->riwayat->catat(RiwayatMasuk::GANTI_SANDI, $user->id, $this->pintu->lingkup, $ip, $request->getHeaderLine('User-Agent'));

        // Session ini tetap login dengan sidik jari kata sandi yang baru; session lain tidak berlaku lagi.
        $baruUser = $this->daftar->cariId($user->id) ?? $user;
        $sesi->gantiId();
        (new TokenCsrf($sesi))->baru();
        $sesi->simpan(WajibMasukMiddleware::SESI_SANDI, $baruUser->sidikSandi());
        $sesi->simpan(self::SESI_PESAN, 'Kata sandi sudah diganti. Perangkat lain yang sedang login sudah dikeluarkan.');

        return new RedirectResponse($this->pintu->alamatProfil());
    }

    /** @param array<string, mixed> $data */
    private function galat(
        ServerRequestInterface $request,
        User $user,
        ?string $bagian,
        string $pesan,
        int $status,
        array $data = [],
    ): ResponseInterface {
        return $this->halaman($request, $user, $data + ['galat' => $pesan, 'galatBagian' => $bagian], $status);
    }

    /** @param array<string, mixed> $data */
    private function halaman(ServerRequestInterface $request, User $user, array $data, int $status = 200): ResponseInterface
    {
        return $this->penyaji->halaman($this->template, $request, $data + [
            'pesan'        => null,
            'galat'        => null,
            'galatBagian'  => null,
            'nama'         => $user->nama,
            'alamatProfil' => $this->pintu->alamatProfil(),
            'minimalSandi' => AturanKataSandi::panjangMinimal($this->daftar->adalahAdmin($user->id)),
            'zonaWaktu'    => $this->zonaWaktu->getName(),
            'riwayat'      => $this->riwayat($user),
        ], $status);
    }

    /** @return list<array{waktu: string, peristiwa: string, label: string, lingkup: string, ip: string, perangkat: string, perangkatLengkap: string}> */
    private function riwayat(User $user): array
    {
        $utc   = new DateTimeZone('UTC');
        $baris = [];
        foreach ($this->riwayat->untukUser($user->id, self::JUMLAH_RIWAYAT) as $b) {
            $baris[] = [
                'waktu'            => (new DateTimeImmutable($b['waktu'], $utc))->setTimezone($this->zonaWaktu)->format('Y-m-d H:i'),
                'peristiwa'        => $b['peristiwa'],
                'label'            => RiwayatMasuk::label($b['peristiwa']),
                'lingkup'          => $b['lingkup'],
                'ip'               => $b['ip'],
                'perangkat'        => Perangkat::ringkas($b['perangkat']),
                'perangkatLengkap' => $b['perangkat'],
            ];
        }

        return $baris;
    }
}
