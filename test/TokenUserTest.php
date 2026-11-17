<?php

declare(strict_types=1);

namespace AdeMS\Dapur\User\Test;

use AdeMS\Dapur\User\DaftarUser;
use AdeMS\Dapur\User\TokenUser;
use Laminas\Db\Adapter\Adapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BantuanUji.php';

final class TokenUserTest extends TestCase
{
    private Adapter $db;
    private TokenUser $token;
    private int $id;

    protected function setUp(): void
    {
        $this->db    = DatabaseUji::baru();
        $this->token = new TokenUser($this->db);
        $this->id    = (new DaftarUser($this->db))->buat('budi@contoh.com', 'Budi', 'kata-sandi-panjang');
    }

    public function testBuatPeriksaPakai(): void
    {
        $asli = $this->token->buat($this->id, TokenUser::LUPA_SANDI);

        self::assertGreaterThanOrEqual(40, strlen($asli));
        $data = $this->token->periksa($asli, [TokenUser::LUPA_SANDI, TokenUser::UNDANGAN]);
        self::assertSame($this->id, $data['user_id'] ?? null);
        self::assertNull($this->token->periksa($asli, [TokenUser::GANTI_EMAIL]), 'Jenis lain tidak berlaku.');

        $this->token->pakai((int) $data['id']);
        self::assertNull($this->token->periksa($asli, [TokenUser::LUPA_SANDI]), 'Sekali pakai.');
    }

    public function testHanyaHashYangDisimpan(): void
    {
        $asli  = $this->token->buat($this->id, TokenUser::UNDANGAN);
        $baris = $this->db->query('SELECT hash FROM "user_token"', Adapter::QUERY_MODE_EXECUTE)->toArray();

        self::assertSame(hash('sha256', $asli), $baris[0]['hash']);
        self::assertStringNotContainsString($asli, json_encode($baris));
    }

    public function testTokenBaruMembatalkanTokenLama(): void
    {
        $lama = $this->token->buat($this->id, TokenUser::LUPA_SANDI);
        $baru = $this->token->buat($this->id, TokenUser::LUPA_SANDI);

        self::assertNull($this->token->periksa($lama, [TokenUser::LUPA_SANDI]));
        self::assertNotNull($this->token->periksa($baru, [TokenUser::LUPA_SANDI]));
    }

    public function testKedaluwarsaDanTokenTidakDikenal(): void
    {
        $asli = $this->token->buat($this->id, TokenUser::GANTI_EMAIL, 'baru@contoh.com');
        self::assertSame('baru@contoh.com', $this->token->periksa($asli, [TokenUser::GANTI_EMAIL])['data'] ?? null);

        $this->db->query('UPDATE "user_token" SET kedaluwarsa = ?', [gmdate('Y-m-d H:i:s', time() - 60)]);
        self::assertNull($this->token->periksa($asli, [TokenUser::GANTI_EMAIL]));
        self::assertNull($this->token->periksa('tidak-ada', [TokenUser::GANTI_EMAIL]));
        self::assertNull($this->token->periksa('', [TokenUser::GANTI_EMAIL]));
        self::assertTrue($this->token->tersedia());
    }
}
