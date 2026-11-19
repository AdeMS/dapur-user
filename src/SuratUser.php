<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use AdeMS\Dapur\Email\GagalKirim;
use AdeMS\Dapur\Email\PengirimEmail;
use AdeMS\Dapur\Email\SuratTemplat;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Email yang dikirim paket user: undangan, lupa kata sandi, verifikasi email baru, dan pemberitahuan bahwa email
 * akun sudah diganti. Pengiriman memakai adems/dapur-email, jadi caranya (SMTP, file, atau mati) diatur di sana.
 *
 * Tautan di dalam email harus berupa alamat lengkap (dengan https:// dan nama host). Bila 'alamat_website' diatur,
 * alamat itu yang dipakai; bila tidak, alamat diambil dari request yang sedang berjalan.
 */
final class SuratUser
{
    public function __construct(
        private readonly PengirimEmail $pengirim,
        private readonly SuratTemplat $surat,
        private readonly ?string $alamatWebsite = null,
    ) {
    }

    /** Apakah email bisa dikirim. Bila tidak, fitur yang memerlukan email disembunyikan atau memakai cara lain. */
    public function aktif(): bool
    {
        return $this->pengirim->aktif();
    }

    /** Alamat lengkap untuk satu jalur di website ini, misalnya https://contoh.com/atur-sandi?token=... */
    public function alamatLengkap(ServerRequestInterface $request, string $jalur, array $query = []): string
    {
        if ($this->alamatWebsite !== null) {
            $dasar = rtrim($this->alamatWebsite, '/');
        } else {
            $uri   = $request->getUri();
            $port  = $uri->getPort();
            $dasar = ($uri->getScheme() ?: 'https') . '://' . $uri->getHost() . ($port !== null ? ':' . $port : '');
        }

        return $dasar . $jalur . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @param string|null $kepada email tujuan bila bukan email utama, misalnya email kantor untuk website kantor
     * @throws GagalKirim
     */
    public function kirimUndangan(User $user, string $tautan, string $pengundang, string $namaWebsite, ?string $kepada = null): void
    {
        $this->kirim('user::email-undangan', $kepada ?? $user->email, $user->nama, "Undangan bergabung ke {$namaWebsite}", [
            'nama'        => $user->nama,
            'tautan'      => $tautan,
            'pengundang'  => $pengundang,
            'namaWebsite' => $namaWebsite,
            'jam'         => intdiv(TokenUser::MASA_BERLAKU[TokenUser::UNDANGAN], 3600),
        ]);
    }

    /**
     * @param string|null $kepada email tujuan bila bukan email utama, misalnya email yang diketik di halaman lupa
     *                            kata sandi (email tambahan akun itu)
     * @throws GagalKirim
     */
    public function kirimLupaSandi(User $user, string $tautan, string $namaWebsite, ?string $kepada = null): void
    {
        $this->kirim('user::email-lupa-sandi', $kepada ?? $user->email, $user->nama, "Atur ulang kata sandi {$namaWebsite}", [
            'nama'        => $user->nama,
            'tautan'      => $tautan,
            'namaWebsite' => $namaWebsite,
            'menit'       => intdiv(TokenUser::MASA_BERLAKU[TokenUser::LUPA_SANDI], 60),
        ]);
    }

    /** @throws GagalKirim */
    public function kirimVerifikasiEmail(User $user, string $emailBaru, string $tautan, string $namaWebsite, bool $tambah = false): void
    {
        $this->kirim('user::email-verifikasi', $emailBaru, $user->nama, "Konfirmasi email baru {$namaWebsite}", [
            'tambah'      => $tambah,
            'nama'        => $user->nama,
            'emailBaru'   => $emailBaru,
            'tautan'      => $tautan,
            'namaWebsite' => $namaWebsite,
            'jam'         => intdiv(TokenUser::MASA_BERLAKU[TokenUser::GANTI_EMAIL], 3600),
        ]);
    }

    /**
     * Memberi tahu alamat lama bahwa ada permintaan atau perubahan email akun, agar pemilik akun tahu bila bukan dia
     * yang memintanya.
     *
     * @throws GagalKirim
     */
    public function kirimPemberitahuanEmail(User $user, string $emailBaru, bool $sudahDiganti, string $namaWebsite): void
    {
        $this->kirim('user::email-pemberitahuan', $user->email, $user->nama, "Perubahan email akun {$namaWebsite}", [
            'nama'         => $user->nama,
            'emailBaru'    => $emailBaru,
            'sudahDiganti' => $sudahDiganti,
            'namaWebsite'  => $namaWebsite,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function kirim(string $template, string $kepada, string $nama, string $subjek, array $data): void
    {
        $this->pengirim->kirim($this->surat->buat($template, $data, $kepada, $subjek, $nama));
    }
}
