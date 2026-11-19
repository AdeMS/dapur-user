<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/** Memeriksa email dan kata sandi, lalu mencatat hasilnya. */
final class Autentikasi
{
    /** Hash tiruan, agar waktu proses email tak terdaftar sama dengan email terdaftar. */
    private const HASH_TIRUAN = '$2y$12$IkUn9.GODEFjhaYLMl0rP.JQU8VUUFf8ij62P7.ZbhkZmx5DHCDQ6';

    private const PANJANG_EMAIL_MAKS = 190;
    private const PANJANG_SANDI_MAKS = 1024;

    public function __construct(
        private readonly DaftarUser $user,
        private readonly PembatasPercobaan $pembatas,
        private readonly ?RiwayatMasuk $riwayat = null,
        private readonly BatasanDomain $domain = new BatasanDomain(),
    ) {
    }

    /** Batasan domain email website ini. */
    public function batasan(): BatasanDomain
    {
        return $this->domain;
    }

    /** @param string $perangkat user agent browser, untuk riwayat login */
    public function masuk(string $email, string $kataSandi, string $ip, string $lingkup, string $perangkat = ''): HasilMasuk
    {
        $email = DaftarUser::normalkanEmail($email);

        // Tidak dicatat di riwayat, agar riwayat tidak bisa dibanjiri percobaan yang memang sudah ditolak.
        if ($this->pembatas->terkunci($email, $ip)) {
            return new HasilMasuk(HasilMasuk::DIBATASI);
        }

        $bentukSah = $email !== '' && strlen($email) <= self::PANJANG_EMAIL_MAKS
            && $kataSandi !== '' && strlen($kataSandi) <= self::PANJANG_SANDI_MAKS;
        $user      = $bentukSah ? $this->user->cariEmail($email) : null;
        $cocok     = password_verify($bentukSah ? $kataSandi : '', $user?->kataSandi ?? self::HASH_TIRUAN);

        // Semua penyebab gagal diperlakukan sama, agar keberadaan akun tidak bisa ditebak.
        if ($user === null || ! $cocok || ! $user->aktif || $this->user->peran($user->id, $lingkup) === null) {
            $this->pembatas->catat($email, $ip);
            $this->riwayat?->catat(RiwayatMasuk::GAGAL, $user?->id, $lingkup, $ip, $perangkat, $email);

            return new HasilMasuk(HasilMasuk::GAGAL);
        }

        // Website yang mewajibkan email kantor: email lain dari akun yang sama ditolak di sini. Pesannya baru
        // diberikan sesudah kata sandi benar, agar tidak bisa dipakai menebak email. Admin super tidak dibatasi.
        if (! $this->domain->cocok($email) && $this->user->peran($user->id, $lingkup) !== DaftarUser::PERAN_ADMIN_SUPER) {
            // Dicatat terpisah dari "gagal", agar pemilik akun tahu kata sandinya benar dan hanya emailnya yang ditolak.
            $this->riwayat?->catat(RiwayatMasuk::DITOLAK_DOMAIN, $user->id, $lingkup, $ip, $perangkat, $email);

            return new HasilMasuk(HasilMasuk::DOMAIN);
        }

        if (DaftarUser::perluHashBaru($user->kataSandi)) {
            $this->user->gantiSandi($user->id, $kataSandi);
            // Ambil ulang agar sidik jari kata sandi di session cocok dengan hash yang baru.
            $user = $this->user->cariId($user->id) ?? $user;
        }

        $this->pembatas->bersihkan($email, $ip);
        $this->user->catatMasuk($user->id);
        $this->riwayat?->catat(RiwayatMasuk::MASUK, $user->id, $lingkup, $ip, $perangkat, $email);

        return new HasilMasuk(HasilMasuk::BERHASIL, $user);
    }
}
