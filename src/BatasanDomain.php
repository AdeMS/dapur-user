<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User;

use RuntimeException;

/**
 * Batasan domain email untuk satu website, dari pengaturan `'user' => ['domain_email' => ['kantor.co.id']]`.
 *
 * Website kantor bisa mewajibkan email kantor: hanya email dengan domain itu yang bisa diberi akses, dipakai login,
 * dan dipilih sebagai email untuk website tersebut. Akunnya tetap satu; email kantor cukup ditambahkan ke akun
 * yang sama. Admin super tidak terkena batasan ini, karena berlaku di semua website.
 *
 * Tanpa pengaturan, semua email diterima.
 */
final class BatasanDomain
{
    /** @var list<string> */
    public readonly array $domain;

    /** @param list<string> $domain */
    public function __construct(array $domain = [])
    {
        $bersih = [];
        foreach ($domain as $d) {
            $d = is_string($d) ? strtolower(trim(ltrim(trim($d), '@'))) : '';
            if (preg_match('/^(?=.{1,190}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $d) !== 1) {
                throw new RuntimeException("Pengaturan 'domain_email' user tidak sah; contoh: ['kantor.co.id'].");
            }
            $bersih[] = $d;
        }

        $this->domain = array_values(array_unique($bersih));
    }

    /** @param mixed $nilai isi $config['user']['domain_email'] */
    public static function dariPengaturan(mixed $nilai): self
    {
        if ($nilai === null) {
            return new self();
        }

        if (! is_array($nilai) || ! array_is_list($nilai)) {
            throw new RuntimeException("Pengaturan 'domain_email' user harus berupa daftar domain; contoh: ['kantor.co.id'].");
        }

        return new self($nilai);
    }

    public function aktif(): bool
    {
        return $this->domain !== [];
    }

    /** Apakah email ini boleh dipakai; selalu true bila tidak ada batasan. */
    public function cocok(string $email): bool
    {
        if (! $this->aktif()) {
            return true;
        }

        $at = strrpos($email, '@');

        return $at !== false && in_array(strtolower(substr($email, $at + 1)), $this->domain, true);
    }

    /** Untuk pesan, misalnya "@kantor.co.id" atau "@kantor.co.id atau @cabang.co.id". */
    public function teks(): string
    {
        return implode(' atau ', array_map(static fn (string $d): string => '@' . $d, $this->domain));
    }
}
