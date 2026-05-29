<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use InvalidArgumentException;

final class QueryProgramFactory
{
    private const int MAX_QUERIES = 4;

    private const string ID_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly PlanFactory $planFactory,
        private readonly PresentationFactory $presentationFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function fromArray(array $data): QueryProgram
    {
        $rawQueries = $this->arrayOrEmpty($data, 'queries');
        if ($rawQueries === []) {
            throw new InvalidArgumentException('A query program must contain at least one query.');
        }
        if (count($rawQueries) > self::MAX_QUERIES) {
            throw new InvalidArgumentException(sprintf(
                'A query program may contain at most %d queries; got %d.',
                self::MAX_QUERIES,
                count($rawQueries),
            ));
        }

        /** @var list<ProgramQuery> $queries */
        $queries = [];
        /** @var list<string> $seenIds */
        $seenIds = [];
        /** @var array<string, TargetDataset> $datasetById */
        $datasetById = [];

        foreach ($rawQueries as $rawQuery) {
            if (! is_array($rawQuery)) {
                throw new InvalidArgumentException('Each program query must be an object.');
            }

            $id = $this->parseId($rawQuery['id'] ?? null, $seenIds);
            $dataset = $this->parseDataset($rawQuery['dataset'] ?? null, $id);
            $plan = $this->planFactory->fromArray($rawQuery, $dataset);

            $this->assertReferencesPointBackward($plan, $seenIds, $id, $datasetById);

            $queries[] = new ProgramQuery($id, $plan);
            $seenIds[] = $id;
            $datasetById[$id] = $dataset;
        }

        $presentation = $this->presentationFactory->fromArray(
            is_array($data['presentation'] ?? null) ? $data['presentation'] : [],
            $seenIds,
        );

        return new QueryProgram($queries, $presentation);
    }

    /**
     * @param  list<string>  $seenIds
     */
    private function parseId(mixed $raw, array $seenIds): string
    {
        $id = (string) ($raw ?? '');
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid query id "%s".', $id));
        }
        if (in_array($id, $seenIds, true)) {
            throw new InvalidArgumentException(sprintf('Duplicate query id "%s".', $id));
        }

        return $id;
    }

    private function parseDataset(mixed $raw, string $queryId): TargetDataset
    {
        $value = (string) ($raw ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Query "%s" must declare a dataset.', $queryId));
        }

        $case = TargetDataset::tryFrom($value);
        if ($case === null) {
            throw new InvalidArgumentException(sprintf('Unknown dataset "%s" on query "%s".', $value, $queryId));
        }

        return $case;
    }

    /**
     * @param  list<string>  $earlierIds
     * @param  array<string, TargetDataset>  $datasetById
     */
    private function assertReferencesPointBackward(Plan $plan, array $earlierIds, string $selfId, array $datasetById): void
    {
        foreach ($plan->where as $clause) {
            $reference = StepReference::tryParse($clause->value);
            if ($reference === null) {
                continue;
            }

            if ($reference->queryId === $selfId) {
                throw new InvalidArgumentException(sprintf('Query "%s" references itself.', $selfId));
            }
            if (! in_array($reference->queryId, $earlierIds, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Query "%s" references "%s", which is not an earlier query.',
                    $selfId,
                    $reference->queryId,
                ));
            }
            $referencedDataset = $datasetById[$reference->queryId];
            if (FieldLookup::tryGet($referencedDataset, $reference->field) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Reference "%s" names field "%s", which does not exist on dataset %s.',
                    $reference->token(),
                    $reference->field,
                    $referencedDataset->value,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function arrayOrEmpty(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
