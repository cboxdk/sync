<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\ValueObjects\CommitSequence;

/**
 * Materializes the whole view at open time, so retrying a token returns the
 * identical page. Sessions live in this process only: a multi-process
 * deployment must use KeysetBootstrapSessions instead, or every page after the
 * first lands on a worker that has never heard of the token.
 */
class FrozenBootstrapSessions implements BootstrapSessions
{
    private const SCAN_BATCH = 500;

    /** @var array<string, BootstrapSession> */
    private array $sessions = [];

    /** @var array<string, BootstrapRequest> */
    private array $tokens = [];

    public function __construct(private Store $store) {}

    public function open(CursorContext $context, ViewDefinition $view, CommitSequence $watermark, int $pageSize): BootstrapToken
    {
        $criteria = $view instanceof QueryableView ? $view->criteria() : null;
        $records = [];
        $after = null;
        while (true) {
            $batch = $this->store->scanRecords($context->space, $after, self::SCAN_BATCH, $criteria);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $record) {
                if ($view->includes($record)) {
                    $records[] = $record;
                }
            }
            $after = $batch[array_key_last($batch)]->entity;
        }
        $sessionId = bin2hex(random_bytes(16));
        $this->sessions[$sessionId] = new BootstrapSession($context, $records, $watermark, $pageSize);

        return $this->token($sessionId, 0);
    }

    public function page(BootstrapToken $token, ViewDefinition $view): BootstrapPage
    {
        $request = $this->tokens[$token->value] ?? throw new ResetRequired(ResetReason::BootstrapTokenUnknown);
        $session = $this->sessions[$request->sessionId] ?? throw new ResetRequired(ResetReason::BootstrapSessionExpired);
        if ($session->context->viewId !== $view->id() || $session->context->filterSignature !== $view->filterSignature()) {
            throw new ResetRequired(ResetReason::ContextChanged);
        }
        $records = array_slice($session->records, $request->offset, $session->pageSize);
        $nextOffset = $request->offset + count($records);
        if ($nextOffset < count($session->records)) {
            return new BootstrapPage($records, $this->token($request->sessionId, $nextOffset), null, $session->context, $token, $request->offset);
        }

        return new BootstrapPage($records, null, new ViewCursor($session->context, $session->watermark), $session->context, $token, $request->offset);
    }

    private function token(string $sessionId, int $offset): BootstrapToken
    {
        $value = hash('sha256', serialize([$sessionId, $offset]));
        $this->tokens[$value] = new BootstrapRequest($sessionId, $offset);

        return new BootstrapToken($value);
    }
}
