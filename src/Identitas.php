<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

/**
 * Bagaimana seorang user tampil di satu lingkup: nama, email, foto, dan status.
 *
 * Di website, user bisa memakai nama tampilan dan foto khusus (profil di website itu) dan email pilihannya untuk
 * website tersebut. Bila tidak diatur, yang dipakai nama, foto, dan email utama akun. Di dashboard utama selalu
 * nama, foto, dan email akun.
 */
final class Identitas
{
    public function __construct(
        public readonly string $nama,
        public readonly string $email,
        /** Alamat foto, atau null bila memakai avatar inisial. */
        public readonly ?string $foto,
        public readonly string $inisial,
        /** Status singkat akun (sama di semua website); '' bila tidak diisi. */
        public readonly string $status = '',
    ) {
    }

    public static function untuk(User $user, string $lingkup, DaftarUser $daftar, Pintu $pintu): self
    {
        $email = $daftar->email();
        $tampilEmail = $email->tersedia() ? $email->emailLingkup($user->id, $lingkup) : $user->email;

        $profil  = $lingkup !== Konteks::LINGKUP_UTAMA ? $daftar->profil()->ambil($user->id, $lingkup) : [];
        $status  = $daftar->profil()->ambil($user->id)[ProfilUser::STATUS] ?? '';
        $nama    = ($profil[ProfilUser::NAMA] ?? '') !== '' ? $profil[ProfilUser::NAMA] : $user->nama;
        $foto    = ($profil[ProfilUser::FOTO] ?? '') !== '' ? $profil[ProfilUser::FOTO] : $user->foto;
        $inisial = $nama === $user->nama ? $user->inisial() : User::inisialDari($nama, $tampilEmail);

        return new self($nama, $tampilEmail, $foto === '' ? null : $pintu->alamatFoto($foto), $inisial, $status);
    }
}
