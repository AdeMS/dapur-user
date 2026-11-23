<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Handler;

use AdeMS\Dapur\User\DaftarLokasi;
use AdeMS\Dapur\User\Sesi;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saran kota untuk isian Lokasi (`<alamat dasar>/lokasi?cari=Band`), dipanggil JavaScript di tab Profil halaman
 * Pengaturan & Privasi. Harus dipasang di belakang WajibMasukMiddleware: hanya user yang login yang bisa mencari.
 *
 * Jawaban: {"hasil": [{"id": 123, "teks": "Kota Bandung, Jawa Barat, Indonesia"}, ...]}. 404 bila sumber kota
 * tidak diatur; 503 bila tabel kota tidak terbaca; 429 bila satu session mencari terlalu sering.
 */
final class LokasiHandler implements RequestHandlerInterface
{
    /** Paling banyak sekian pencarian per menit per session (browser mengirim satu pencarian per jeda mengetik). */
    public const BATAS_PER_MENIT = 60;

    private const SESI_BATAS = 'user.lokasi_batas';

    public function __construct(private readonly ?DaftarLokasi $sumber)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->sumber === null) {
            return new EmptyResponse(404);
        }

        $sesi = $request->getAttribute(Sesi::class);
        if ($sesi instanceof Sesi && ! self::bolehMencari($sesi)) {
            return new JsonResponse(['hasil' => []], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
        }

        $cari = $request->getQueryParams()['cari'] ?? '';
        try {
            $hasil = is_string($cari) ? $this->sumber->cari($cari) : [];
        } catch (\Throwable) {
            // Tabel kota tidak terbaca (belum dipasang atau tanpa hak baca): tanpa saran, Lokasi tetap bisa diketik.
            return new JsonResponse(['hasil' => []], 503, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['hasil' => $hasil], 200, ['Cache-Control' => 'no-store']);
    }

    private static function bolehMencari(Sesi $sesi): bool
    {
        $menit = intdiv(time(), 60);
        $batas = $sesi->ambil(self::SESI_BATAS);
        $jumlah = is_array($batas) && ($batas[0] ?? null) === $menit ? (int) ($batas[1] ?? 0) : 0;
        if ($jumlah >= self::BATAS_PER_MENIT) {
            return false;
        }

        $sesi->simpan(self::SESI_BATAS, [$menit, $jumlah + 1]);

        return true;
    }
}
