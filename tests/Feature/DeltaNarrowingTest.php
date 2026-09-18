<?php

declare(strict_types=1);

use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\ViewCursor;
use Cbox\Sync\Views\ViewDefinition;
use Cbox\Sync\Views\ViewSyncService;

/**
 * A view bound to one entity type has no interest in another type's writes.
 * Reading them anyway is the dominant cost of a poll on a busy tenant, paid per
 * device, per poll.
 */
it('never reads the commits an entity-typed view cannot match', function () {
    // The output was always correct - project() discards what does not match.
    // What matters is that those commits are never read and decoded at all, so
    // the store itself is what has to be observed.
    $store = new class extends InMemoryStore
    {
        /** @var list<?string> */
        public array $narrowedTo = [];

        public int $commitsRead = 0;

        public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array
        {
            $this->narrowedTo[] = $entityType;
            $commits = parent::commitsAfter($space, $after, $limit, $entityType);
            $this->commitsRead += count($commits);

            return $commits;
        }
    };

    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');
    $scenario->create('n1', 'alpha', type: 'notes');
    $scenario->create('n2', 'alpha', type: 'notes');
    $scenario->create('b', 'alpha');

    $view = FieldEqualsView::matching('project-alpha', 'v1', 'project', 'alpha', 'items');
    $sync = new ViewSyncService($store, 'schema-1', 'epoch-1');
    $context = $sync->context('test', $view);

    $page = $sync->delta(new ViewCursor($context, new CommitSequence(0)), $view);

    expect($store->narrowedTo)->toBe(['items']);
    expect($store->commitsRead)->toBe(2);
    expect($page->commits)->toHaveCount(2);
});

/** A view that cannot express itself as a query gets the whole log, as before. */
it('reads every commit for a view that is not queryable', function () {
    $store = new class extends InMemoryStore
    {
        /** @var list<?string> */
        public array $narrowedTo = [];

        public int $commitsRead = 0;

        public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array
        {
            $this->narrowedTo[] = $entityType;
            $commits = parent::commitsAfter($space, $after, $limit, $entityType);
            $this->commitsRead += count($commits);

            return $commits;
        }
    };

    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');
    $scenario->create('n1', 'alpha', type: 'notes');

    $view = new class implements ViewDefinition
    {
        public function id(): string
        {
            return 'everything';
        }

        public function filterVersion(): string
        {
            return 'v1';
        }

        public function filterSignature(): string
        {
            return 'everything-v1';
        }

        public function includes(EntityRecord $record): bool
        {
            return ! $record->deleted;
        }
    };

    $sync = new ViewSyncService($store, 'schema-1', 'epoch-1');
    $sync->delta(new ViewCursor($sync->context('test', $view), new CommitSequence(0)), $view);

    expect($store->narrowedTo)->toBe([null]);
    expect($store->commitsRead)->toBe(2);
});

/**
 * The regression this narrowing could have introduced: if the cursor only
 * advanced past commits the view matched, a tenant writing other entity types
 * would leave a fully up-to-date device sitting still until retention ran past
 * it and forced a reset it did not need.
 */
it('advances the cursor past writes the view will never care about', function () {
    $scenario = new ViewScenario($this->syncStore());
    $scenario->create('a', 'alpha');

    $view = FieldEqualsView::matching('project-alpha', 'v1', 'project', 'alpha', 'items');
    $sync = new ViewSyncService($scenario->store, 'schema-1', 'epoch-1');
    $context = $sync->context('test', $view);

    $first = $sync->delta(new ViewCursor($context, new CommitSequence(0)), $view);
    expect($first->commits)->toHaveCount(1);

    // The tenant is busy with a type this view does not follow.
    foreach (range(1, 12) as $index) {
        $scenario->create('n'.$index, 'alpha', type: 'notes');
    }

    $second = $sync->delta($first->cursor, $view);

    expect($second->commits)->toBe([]);
    expect($second->cursor->position->value)->toBe($scenario->store->watermark('test')->value);
    expect($second->hasMore)->toBeFalse();
});

/**
 * Rows written before the entity type was a column carry NULL. Narrowing must
 * read that as "unknown", never as "does not match", or upgrading the package
 * would silently drop every commit a host had already stored.
 */
it('still delivers a commit stored before the entity type was recorded', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $store->migrate();

    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');

    $pdo->exec('UPDATE sync_commits SET entity_type = NULL');

    $narrowed = $store->commitsAfter('test', 0, 10, 'items');
    $otherType = $store->commitsAfter('test', 0, 10, 'notes');

    expect($narrowed)->toHaveCount(1);
    expect($narrowed[0])->toBeInstanceOf(Commit::class);
    // Unknown means undecidable, so it is delivered to a view of any type and
    // the view's own predicate throws it away.
    expect($otherType)->toHaveCount(1);
});

it('records the entity type it can narrow on', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $store->migrate();

    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');
    $scenario->create('n1', 'alpha', type: 'notes');

    $types = $pdo->query('SELECT entity_type FROM sync_commits ORDER BY sequence')?->fetchAll(PDO::FETCH_COLUMN);

    expect($types)->toBe(['items', 'notes']);
    expect($store->commitsAfter('test', 0, 10, 'notes'))->toHaveCount(1);
});

/**
 * An installation that predates the entity type column has to keep working
 * after an upgrade, without the host writing a migration. CREATE TABLE IF NOT
 * EXISTS does nothing to a table that is already there, so the schema has to
 * reconcile the difference itself.
 */
it('adds the narrowing column to a schema installed before it existed', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // The sync_commits table exactly as an older release created it.
    $pdo->exec('CREATE TABLE sync_commits (
        space TEXT NOT NULL,
        sequence BIGINT NOT NULL,
        payload TEXT NOT NULL,
        PRIMARY KEY (space, sequence)
    )');
    $pdo->exec("INSERT INTO sync_commits (space, sequence, payload) VALUES ('test', 1, 'legacy')");

    $store = new PdoStore($pdo);
    $store->migrate();

    $columns = $pdo->query('SELECT name FROM pragma_table_info(\'sync_commits\')')?->fetchAll(PDO::FETCH_COLUMN);
    expect($columns)->toContain('entity_type');

    // The row that was already there keeps its place, with no type to narrow on.
    $legacy = $pdo->query('SELECT entity_type FROM sync_commits WHERE sequence = 1')?->fetchColumn();
    expect($legacy)->toBeNull();

    // And the adapter can write again, which it could not before the column.
    $pdo->exec('DELETE FROM sync_commits');
    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');
    expect($pdo->query('SELECT entity_type FROM sync_commits')?->fetchColumn())->toBe('items');
});
