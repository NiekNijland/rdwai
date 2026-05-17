<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Models\QueryRun;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;

final class FindPopularQueries
{
    private const int DEFAULT_LIMIT = 6;

    private const int LOOKBACK_DAYS = 90;

    /**
     * Returns popular prompts for the given locale, ranked by upvote count.
     * Tops up with recent successful queries when upvotes don't fill the
     * limit — so a freshly seeded install still surfaces something useful.
     *
     * @return list<string>
     */
    public function execute(string $locale, int $limit = self::DEFAULT_LIMIT): array
    {
        $upvoted = $this->upvotedPrompts($locale, $limit);

        if (count($upvoted) >= $limit) {
            return $upvoted;
        }

        return $this->topUpWithRecent($upvoted, $locale, $limit);
    }

    /**
     * @return list<string>
     */
    private function upvotedPrompts(string $locale, int $limit): array
    {
        $connection = DB::connection('mongodb');
        assert($connection instanceof Connection);

        $cursor = $connection
            ->getMongoDB()
            ->selectCollection('query_runs')
            ->aggregate([
                ['$match' => [
                    'locale' => $locale,
                    'rating' => QueryRun::RATING_UP,
                    'created_at' => ['$gte' => new UTCDateTime(now()->subDays(self::LOOKBACK_DAYS))],
                ]],
                ['$group' => [
                    '_id' => '$prompt',
                    'votes' => ['$sum' => 1],
                    'last' => ['$max' => '$created_at'],
                ]],
                ['$sort' => ['votes' => -1, 'last' => -1]],
                ['$limit' => $limit],
            ]);

        $out = [];

        foreach ($cursor as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $prompt = $row['_id'] ?? null;

            if (is_string($prompt) && $prompt !== '') {
                $out[] = $prompt;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $seed
     * @return list<string>
     */
    private function topUpWithRecent(array $seed, string $locale, int $limit): array
    {
        $remaining = $limit - count($seed);

        if ($remaining <= 0) {
            return $seed;
        }

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent fluent API is not statically resolvable)
        $query = QueryRun::query()
            ->where('locale', $locale)
            ->whereNotIn('rating', [QueryRun::RATING_DOWN])
            ->orderBy('created_at', 'desc')
            ->limit($remaining * 4);

        if ($seed !== []) {
            $query->whereNotIn('prompt', $seed);
        }

        $recent = $query->get();

        $seen = array_fill_keys($seed, true);
        $out = $seed;

        foreach ($recent as $run) {
            if (count($out) >= $limit) {
                break;
            }

            $prompt = (string) $run->prompt;

            if ($prompt === '' || isset($seen[$prompt])) {
                continue;
            }

            $out[] = $prompt;
            $seen[$prompt] = true;
        }

        return $out;
    }
}
