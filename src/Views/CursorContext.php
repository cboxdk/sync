<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class CursorContext
{
    public function __construct(
        public string $space,
        public string $viewId,
        public string $filterVersion,
        public string $filterSignature,
        public string $schemaVersion,
        public string $epoch,
    ) {
        if ($space === '' || $viewId === '' || $filterVersion === '' || $filterSignature === '' || $schemaVersion === '' || $epoch === '') {
            throw new InvalidRequest('Cursor context values must not be empty');
        }
    }

    public static function forView(string $space, ViewDefinition $view, string $schemaVersion, string $epoch): self
    {
        return new self($space, $view->id(), $view->filterVersion(), $view->filterSignature(), $schemaVersion, $epoch);
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize([
            $this->space,
            $this->viewId,
            $this->filterVersion,
            $this->filterSignature,
            $this->schemaVersion,
            $this->epoch,
        ]));
    }
}
