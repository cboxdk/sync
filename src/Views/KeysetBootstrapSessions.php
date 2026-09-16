<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;

/**
 * Stores nothing: the token carries the view context, the watermark and the
 * keyset position, authenticated so a tampered or truncated token is rejected
 * rather than silently resuming somewhere else. Any process can serve any page,
 * which is what a queued or load-balanced deployment needs.
 *
 * The sort key is entity identity, which never changes, so a record that stays
 * in the view can be neither skipped nor duplicated. What this gives up against
 * FrozenBootstrapSessions is byte-identical retry: a page reflects the records
 * as they are when it is served, not as they were when the bootstrap opened.
 * Convergence still holds, because the client applies the delta from the
 * watermark taken at open and its version and tombstone barriers discard
 * anything older than what the bootstrap already delivered.
 */
class KeysetBootstrapSessions implements BootstrapSessions
{
    public function __construct(private Store $store, private string $secret)
    {
        if ($secret === '') {
            throw new InvalidRequest('Bootstrap token secret must not be empty');
        }
    }

    public function open(CursorContext $context, ViewDefinition $view, CommitSequence $watermark, int $pageSize): BootstrapToken
    {
        if ($pageSize < 1) {
            throw new InvalidRequest('Bootstrap page size must be at least one');
        }

        return $this->token($context, $watermark, $pageSize, null, 0);
    }

    public function page(BootstrapToken $token, ViewDefinition $view): BootstrapPage
    {
        $claims = $this->verify($token);
        $context = new CursorContext($claims['space'], $claims['viewId'], $claims['filterVersion'], $claims['filterSignature'], $claims['schemaVersion'], $claims['epoch']);
        if ($context->viewId !== $view->id() || $context->filterSignature !== $view->filterSignature() || $context->filterVersion !== $view->filterVersion()) {
            throw new ResetRequired(ResetReason::ContextChanged);
        }
        $pageSize = $claims['pageSize'];
        $after = $claims['afterType'] === null || $claims['afterId'] === null
            ? null
            : new EntityKey($context->space, $claims['afterType'], $claims['afterId']);

        // One extra row tells us whether another page exists without a second query.
        $batch = $this->store->scanRecords($context->space, $after, $pageSize + 1, $view instanceof QueryableView ? $view->criteria() : null);
        $hasMore = count($batch) > $pageSize;
        $scanned = array_slice($batch, 0, $pageSize);

        $records = [];
        foreach ($scanned as $record) {
            if ($view->includes($record)) {
                $records[] = $record;
            }
        }

        $watermark = new CommitSequence($claims['watermark']);
        if (! $hasMore || $scanned === []) {
            return new BootstrapPage($records, null, new ViewCursor($context, $watermark), $context, $token, $claims['offset']);
        }

        // Resume from the last row we read, not the last one we kept, or a
        // filtered-out record would be scanned again on the next page.
        $last = $scanned[count($scanned) - 1]->entity;
        $next = $this->token($context, $watermark, $pageSize, $last, $claims['offset'] + count($records));

        return new BootstrapPage($records, $next, null, $context, $token, $claims['offset']);
    }

    private function token(CursorContext $context, CommitSequence $watermark, int $pageSize, ?EntityKey $after, int $offset): BootstrapToken
    {
        $claims = [
            'space' => $context->space,
            'viewId' => $context->viewId,
            'filterVersion' => $context->filterVersion,
            'filterSignature' => $context->filterSignature,
            'schemaVersion' => $context->schemaVersion,
            'epoch' => $context->epoch,
            'watermark' => $watermark->value,
            'pageSize' => $pageSize,
            'afterType' => $after?->type,
            'afterId' => $after?->id,
            'offset' => $offset,
        ];
        $payload = self::encode(json_encode($claims, JSON_THROW_ON_ERROR));

        return new BootstrapToken($payload.'.'.self::encode($this->sign($payload)));
    }

    /** @return array{space: string, viewId: string, filterVersion: string, filterSignature: string, schemaVersion: string, epoch: string, watermark: int, pageSize: int, afterType: string|null, afterId: string|null, offset: int} */
    private function verify(BootstrapToken $token): array
    {
        $parts = explode('.', $token->value);
        if (count($parts) !== 2) {
            throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        }
        [$payload, $signature] = $parts;
        $decodedSignature = self::decode($signature);
        if ($decodedSignature === null || ! hash_equals($this->sign($payload), $decodedSignature)) {
            throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        }
        $json = self::decode($payload);
        if ($json === null) {
            throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        }
        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        }
        if (! is_array($claims)) {
            throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        }
        foreach (['space', 'viewId', 'filterVersion', 'filterSignature', 'schemaVersion', 'epoch'] as $key) {
            if (! isset($claims[$key]) || ! is_string($claims[$key])) {
                throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
            }
        }
        foreach (['watermark', 'pageSize', 'offset'] as $key) {
            if (! isset($claims[$key]) || ! is_int($claims[$key])) {
                throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
            }
        }
        foreach (['afterType', 'afterId'] as $key) {
            if (array_key_exists($key, $claims) && $claims[$key] !== null && ! is_string($claims[$key])) {
                throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
            }
        }

        /** @var array{space: string, viewId: string, filterVersion: string, filterSignature: string, schemaVersion: string, epoch: string, watermark: int, pageSize: int, afterType: string|null, afterId: string|null, offset: int} $claims */
        return $claims;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
