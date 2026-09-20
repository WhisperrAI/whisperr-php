<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\RequestException;
use Whisperr\Whisperr;

final class MessageHistoryTest extends TestCase
{
    private function message(): array
    {
        return ['id' => 'msg_1', 'title' => 'Visit', 'body' => 'A venue', 'created_at' => '2026-09-21T00:00:00Z', 'action' => ['type' => 'open_item', 'item_id' => 'venue:1']];
    }

    public function testHistoryAndDetailUseEncodedUserPathsAndCursor(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(200, ['messages' => [$this->message()], 'next_cursor' => 'next']);
        $transport->respond(200, ['message' => $this->message()]);
        $client = new Whisperr(['api_key' => 'key', 'transport' => $transport]);
        $this->assertSame('next', $client->messageHistory('user/a?x=1', 20, 'opaque+/=')['next_cursor']);
        $this->assertSame('msg_1', $client->message('user/a?x=1', 'msg/../?')['message']['id']);
        $this->assertSame('/v1/users/user%2Fa%3Fx%3D1/messages?limit=20&cursor=opaque%2B%2F%3D', $transport->requests[0]['path']);
        $this->assertSame('/v1/users/user%2Fa%3Fx%3D1/messages/msg%2F%2E%2E%2F%3F', $transport->requests[1]['path']);
        $this->assertSame('GET', $transport->requests[1]['method']);
        $this->assertNull($transport->requests[0]['body']);
    }

    public function testErrorsHaveStatusAndSafeCodeButNoUpstreamSecrets(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(403, ['error' => ['code' => 'server_key_required', 'message' => 'secret-key-content']]);
        $client = new Whisperr(['api_key' => 'key', 'transport' => $transport]);
        try {
            $client->messageHistory('42');
            $this->fail('Expected rejection');
        } catch (RequestException $e) {
            $this->assertSame(403, $e->status());
            $this->assertSame('server_key_required', $e->errorCode());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testSynchronousIdentityPreservesExactTokenOptOut(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(200, ['user' => ['id' => 'usr_1', 'external_id' => '42', 'created' => false]]);
        $client = new Whisperr(['api_key' => 'key', 'transport' => $transport]);
        $result = $client->identifyNow('42', ['channels' => [['channel' => 'push', 'address' => 'exact-token', 'opted_in' => false]]]);
        $this->assertSame('42', $result['user']['external_id']);
        $this->assertSame('/v1/identify', $transport->requests[0]['path']);
        $this->assertSame([['channel' => 'push', 'address' => 'exact-token', 'opted_in' => false]], $transport->requests[0]['body']['channels']);
        $client->flush();
        $this->assertCount(1, $transport->requests);
    }

    public function testSynchronousIdentityDoesNotAcknowledgeADifferentUser(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(200, ['user' => ['id' => 'usr_1', 'external_id' => '43', 'created' => false]]);
        $client = new Whisperr(['api_key' => 'key', 'transport' => $transport]);
        $this->expectException(RequestException::class);
        $client->identifyNow('42');
    }

    public function testMalformedHistoryIsNotReturnedAsAnEmptyInbox(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(200, ['messages' => [['id' => 'missing_fields']], 'next_cursor' => null]);
        $client = new Whisperr(['api_key' => 'key', 'transport' => $transport]);
        $this->expectException(RequestException::class);
        $client->messageHistory('42');
    }
}
