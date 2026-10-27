<?php

declare(strict_types=1);

namespace App\Tests\Support\Jmap;

use App\Infrastructure\JmapClient\JmapCapability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A JMAP server that behaves like one, in memory, for the client's tests.
 *
 * Enough of RFC 8620 and RFC 8621 to hold the client to them: a session, the
 * Mailbox and Email getters, changes with real states, queries that sort,
 * filter and page by anchor, blob downloads, and Email/set for the write-back
 * tests. Every change bumps the state and is logged, so /changes answers what
 * a real server would — and forgetting the log is how a test makes the server
 * answer `cannotCalculateChanges`.
 *
 * Handed to MockHttpClient as its callback. Method calls it does not know are
 * answered `unknownMethod`, as a server would, so a test fails on the call the
 * code was not expected to make rather than on a response it misread.
 */
final class FakeJmapServer
{
    public const string ACCOUNT = 'u1';
    public const string BASE    = 'https://jmap.example.test';

    /** @var array<string, array{id: string, name: string, parentId: string|null, role: string|null}> */
    public array $mailboxes = [];

    /**
     * @var array<string, array{id: string, blobId: string, threadId: string, mailboxIds: array<string, true>,
     *                          keywords: array<string, true>, receivedAt: string, raw: string}>
     */
    public array $emails = [];

    /** @var list<array{type: string, kind: string, id: string, state: int}> */
    private array $log = [];

    /** @var array<string, int> type => current state */
    private array $states = ['Mailbox' => 1, 'Email' => 1];

    /** @var array<string, int> type => the oldest state /changes can answer from */
    private array $horizon = ['Mailbox' => 1, 'Email' => 1];

    /** @var list<array{0: string, 1: array<string, mixed>}> every method call received, in order */
    public array $calls = [];

    /** @var list<string> every blob downloaded */
    public array $downloads = [];

    /** @var list<string> capabilities the session declares, beyond core */
    public array $capabilities = [JmapCapability::MAIL, JmapCapability::SUBMISSION];

    private int $sequence = 0;

    public function __construct()
    {
        foreach (['inbox' => 'Inbox', 'sent' => 'Sent', 'drafts' => 'Drafts', 'trash' => 'Trash', 'junk' => 'Junk'] as $role => $name) {
            $this->mailboxes['mb-' . $role] = ['id' => 'mb-' . $role, 'name' => $name, 'parentId' => null, 'role' => $role];
        }
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->respond(...), self::BASE);
    }

    // ---------------------------------------------------------------- fixtures

    public function addMailbox(string $id, string $name, ?string $parentId = null, ?string $role = null): void
    {
        $this->mailboxes[$id] = ['id' => $id, 'name' => $name, 'parentId' => $parentId, 'role' => $role];
        $this->changed('Mailbox', 'created', $id);
    }

    public function removeMailbox(string $id): void
    {
        unset($this->mailboxes[$id]);
        $this->changed('Mailbox', 'destroyed', $id);
    }

    /**
     * @param list<string> $mailboxIds
     * @param list<string> $keywords
     */
    public function deliver(string $subject, array $mailboxIds = ['mb-inbox'], array $keywords = [], ?string $messageId = null, ?string $receivedAt = null): string
    {
        $id        = 'e' . (++$this->sequence);
        $messageId ??= sprintf('<%s@example.test>', $id);
        $raw       = sprintf(
            "From: Ada <ada@example.test>\r\nTo: me@example.test\r\nSubject: %s\r\nMessage-ID: %s\r\nDate: Mon, 26 Oct 2026 10:00:00 +0000\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of %s.\r\n",
            $subject,
            $messageId,
            $subject,
        );

        $this->emails[$id] = [
            'id'         => $id,
            'blobId'     => 'blob-' . $id,
            'threadId'   => 't-' . $id,
            'mailboxIds' => array_fill_keys($mailboxIds, true),
            'keywords'   => array_fill_keys($keywords, true),
            'receivedAt' => $receivedAt ?? sprintf('2026-10-26T10:%02d:00Z', $this->sequence % 60),
            'raw'        => $raw,
        ];

        $this->changed('Email', 'created', $id);

        return $id;
    }

    /**
     * @param list<string>|null $mailboxIds
     * @param list<string>|null $keywords
     */
    public function change(string $id, ?array $mailboxIds = null, ?array $keywords = null): void
    {
        if (null !== $mailboxIds) {
            $this->emails[$id]['mailboxIds'] = array_fill_keys($mailboxIds, true);
        }

        if (null !== $keywords) {
            $this->emails[$id]['keywords'] = array_fill_keys($keywords, true);
        }

        $this->changed('Email', 'updated', $id);
    }

    public function destroy(string $id): void
    {
        unset($this->emails[$id]);
        $this->changed('Email', 'destroyed', $id);
    }

    /** Throw away the change log, so any state before now is too old to answer from. */
    public function forgetHistory(string $type = 'Email'): void
    {
        $this->horizon[$type] = $this->states[$type];
    }

    /**
     * The calls of one method, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function callsOf(string $name): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $name),
        ));
    }

    // ---------------------------------------------------------------- server

    /**
     * @param array<string, mixed> $options
     */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ('GET' === $method && '/.well-known/jmap' === $path) {
            return $this->json($this->session());
        }

        if ('GET' === $method && true === str_starts_with($path, '/download/')) {
            $blobId = rawurldecode(explode('/', $path)[3] ?? '');

            foreach ($this->emails as $email) {
                if ($email['blobId'] === $blobId) {
                    $this->downloads[] = $blobId;

                    return new MockResponse($email['raw']);
                }
            }

            return new MockResponse('', ['http_code' => 404]);
        }

        if ('POST' === $method && '/api/' === $path) {
            $request   = json_decode((string) ($options['body'] ?? '{}'), true);
            $responses = [];

            foreach ($request['methodCalls'] ?? [] as [$name, $arguments, $callId]) {
                $this->calls[] = [$name, $arguments];

                foreach ($this->call($name, $arguments) as [$answered, $result]) {
                    $responses[] = [$answered, $result, $callId];
                }
            }

            return $this->json(['methodResponses' => $responses, 'sessionState' => 'session-1']);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function call(string $name, array $arguments): array
    {
        return match ($name) {
            'Mailbox/get'     => [[$name, $this->get('Mailbox', $this->mailboxes, $arguments)]],
            'Mailbox/changes' => [$this->changes('Mailbox', $name, $arguments)],
            'Email/get'       => [[$name, $this->get('Email', $this->emails, $arguments)]],
            'Email/changes'   => [$this->changes('Email', $name, $arguments)],
            'Email/query'     => [$this->query($arguments)],
            'Email/set'       => [[$name, $this->setEmails($arguments)]],
            default           => [['error', ['type' => 'unknownMethod']]],
        };
    }

    /**
     * @param array<string, array<string, mixed>> $objects
     * @param array<string, mixed>                $arguments
     *
     * @return array<string, mixed>
     */
    private function get(string $type, array $objects, array $arguments): array
    {
        $ids        = $arguments['ids'] ?? null;
        $properties = $arguments['properties'] ?? null;
        $list       = [];
        $notFound   = [];

        foreach (null === $ids ? array_keys($objects) : $ids as $id) {
            if (false === isset($objects[$id])) {
                $notFound[] = $id;

                continue;
            }

            $object = $objects[$id];
            unset($object['raw']);

            $list[] = null === $properties ? $object : array_intersect_key($object, array_flip([...$properties, 'id']));
        }

        return ['accountId' => self::ACCOUNT, 'state' => (string) $this->states[$type], 'list' => $list, 'notFound' => $notFound];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function changes(string $type, string $name, array $arguments): array
    {
        $since = (int) ($arguments['sinceState'] ?? 0);

        if ($since < $this->horizon[$type]) {
            return ['error', ['type' => 'cannotCalculateChanges']];
        }

        $created = $updated = $destroyed = [];

        foreach ($this->log as $entry) {
            if ($entry['type'] !== $type || $entry['state'] <= $since) {
                continue;
            }

            match ($entry['kind']) {
                'created'   => $created[$entry['id']] = true,
                'updated'   => isset($created[$entry['id']]) ? null : $updated[$entry['id']] = true,
                'destroyed' => $destroyed[$entry['id']] = true,
            };
        }

        foreach (array_keys($destroyed) as $id) {
            unset($updated[$id]);

            if (true === isset($created[$id])) {
                unset($created[$id], $destroyed[$id]);
            }
        }

        return [$name, [
            'accountId'      => self::ACCOUNT,
            'oldState'       => (string) $since,
            'newState'       => (string) $this->states[$type],
            'hasMoreChanges' => false,
            'created'        => array_keys($created),
            'updated'        => array_keys($updated),
            'destroyed'      => array_keys($destroyed),
        ]];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function query(array $arguments): array
    {
        $inMailbox = $arguments['filter']['inMailbox'] ?? null;
        $emails    = array_filter(
            $this->emails,
            static fn (array $email): bool => null === $inMailbox || true === isset($email['mailboxIds'][$inMailbox]),
        );

        uasort($emails, static fn (array $a, array $b): int => [$b['receivedAt'], $b['id']] <=> [$a['receivedAt'], $a['id']]);

        $ids      = array_keys($emails);
        $position = (int) ($arguments['position'] ?? 0);

        if (true === isset($arguments['anchor'])) {
            $index = array_search($arguments['anchor'], $ids, true);

            if (false === $index) {
                return ['error', ['type' => 'anchorNotFound']];
            }

            $position = $index + (int) ($arguments['anchorOffset'] ?? 0);
        }

        $limit  = (int) ($arguments['limit'] ?? count($ids));
        $result = [
            'accountId'  => self::ACCOUNT,
            'queryState' => 'q' . $this->states['Email'],
            'position'   => $position,
            'ids'        => array_slice($ids, $position, $limit),
        ];

        if (true === ($arguments['calculateTotal'] ?? false)) {
            $result['total'] = count($ids);
        }

        return ['Email/query', $result];
    }

    /**
     * Email/set update with patches, and destroy — what write-back sends.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function setEmails(array $arguments): array
    {
        $updated    = [];
        $notUpdated = [];
        $destroyed  = [];

        foreach ($arguments['update'] ?? [] as $id => $patch) {
            if (false === isset($this->emails[$id])) {
                $notUpdated[$id] = ['type' => 'notFound'];

                continue;
            }

            foreach ($patch as $path => $value) {
                [$property, $key] = array_pad(explode('/', (string) $path, 2), 2, null);

                if (null === $key) {
                    $this->emails[$id][$property] = $value;
                } elseif (null === $value || false === $value) {
                    unset($this->emails[$id][$property][$key]);
                } else {
                    $this->emails[$id][$property][$key] = true;
                }
            }

            $this->changed('Email', 'updated', (string) $id);
            $updated[$id] = null;
        }

        foreach ($arguments['destroy'] ?? [] as $id) {
            if (true === isset($this->emails[$id])) {
                $this->destroy((string) $id);
                $destroyed[] = $id;
            }
        }

        return [
            'accountId'  => self::ACCOUNT,
            'newState'   => (string) $this->states['Email'],
            'updated'    => [] === $updated ? null : $updated,
            'destroyed'  => $destroyed,
            'notUpdated' => [] === $notUpdated ? null : $notUpdated,
        ];
    }

    private function changed(string $type, string $kind, string $id): void
    {
        $this->log[] = ['type' => $type, 'kind' => $kind, 'id' => $id, 'state' => ++$this->states[$type]];
    }

    /**
     * @return array<string, mixed>
     */
    private function session(): array
    {
        $capabilities = [JmapCapability::CORE => ['maxObjectsInGet' => 500, 'maxCallsInRequest' => 16]];
        $primary      = [];

        foreach ($this->capabilities as $capability) {
            $capabilities[$capability] = [];
            $primary[$capability]      = self::ACCOUNT;
        }

        return [
            'capabilities'    => $capabilities,
            'accounts'        => [self::ACCOUNT => ['name' => 'me@example.test', 'isPersonal' => true]],
            'primaryAccounts' => $primary,
            'username'        => 'me@example.test',
            'apiUrl'          => self::BASE . '/api/',
            'downloadUrl'     => self::BASE . '/download/{accountId}/{blobId}/{name}?accept={type}',
            'uploadUrl'       => self::BASE . '/upload/{accountId}/',
            'eventSourceUrl'  => self::BASE . '/eventsource/?types={types}&closeafter={closeafter}&ping={ping}',
            'state'           => 'session-1',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
    }
}
