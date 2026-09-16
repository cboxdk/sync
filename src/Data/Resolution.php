<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class Resolution
{
    /** @var list<string> */
    public array $candidateIds;

    /**
     * @param  list<string>  $candidateIds
     */
    public function __construct(public string $groupId, public int $groupRevision, array $candidateIds)
    {
        self::validateIds($candidateIds);
        $candidateIdsCopy = [];
        foreach ($candidateIds as $value) {
            $candidateIdsCopy[] = $value;
        }
        $this->candidateIds = $candidateIdsCopy;

        if ($groupId === '' || $groupRevision < 1 || $candidateIds === [] || count(array_unique($candidateIds)) !== count($candidateIds)) {
            throw new InvalidRequest('Resolution needs a group revision and unique candidate IDs');
        }
    }

    /** @param array<array-key, mixed> $candidateIds */
    private static function validateIds(array $candidateIds): void
    {
        if (! array_is_list($candidateIds)) {
            throw new InvalidRequest('Candidate IDs must be a list');
        }
        foreach ($candidateIds as $id) {
            if (! is_string($id) || $id === '') {
                throw new InvalidRequest('Invalid candidate ID');
            }
        }
    }
}
