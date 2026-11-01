<?php

namespace Oweb\SearchInsights;

use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    protected array $data = [
        'd' => '2026-11-01',
        'l' => 'de',
        't' => 'rückgabe',
        'n' => '05d7b7b0281816ab',
        'p' => 'page://S2880HpE67a49fkf',
        'i' => 3
    ];

    public function testSignAndVerify(): void
    {
        $token = Token::sign($this->data, 'secret');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        $this->assertSame($this->data, Token::verify($token, 'secret'));
    }

    public function testWrongSecret(): void
    {
        $this->assertNull(Token::verify(Token::sign($this->data, 'secret'), 'other'));
    }

    public function testTamperedPayload(): void
    {
        [$payload, $signature] = explode('.', Token::sign($this->data, 'secret'));

        $forged = rtrim(strtr(base64_encode(json_encode([...$this->data, 't' => 'casino'])), '+/', '-_'), '=');

        $this->assertNull(Token::verify($forged . '.' . $signature, 'secret'));
        $this->assertNull(Token::verify($payload . '.' . substr($signature, 0, -1) . 'x', 'secret'));
    }

    public function testInvalidTokens(): void
    {
        $this->assertNull(Token::verify('', 'secret'));
        $this->assertNull(Token::verify('abc', 'secret'));
        $this->assertNull(Token::verify('a.b.c', 'secret'));
        $this->assertNull(Token::verify(str_repeat('a', Token::MAX_LENGTH) . '.b', 'secret'));
    }

    public function testValidSignatureForNonJsonPayload(): void
    {
        $payload   = rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'secret', true)), '+/', '-_'), '=');

        $this->assertNull(Token::verify($payload . '.' . $signature, 'secret'));
    }
}
