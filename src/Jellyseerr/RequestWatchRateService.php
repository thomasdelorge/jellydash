<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyseerr;

use Mk\Framework\Jellyfin\HistoryFilters;

/**
 * Crosses the local Jellyseerr request mirror with play_history to see whether
 * requesters actually watch what they asked for.
 *
 * Matching is offline (title + requester vs Jellyfin username). Homonyms and
 * localised titles can miss; that is an accepted limit until plays carry TMDB ids.
 */
final class RequestWatchRateService
{
    private const MIN_WATCHED_SEC = 300;

    public function __construct(
        private ?SeerrRequestRepository $repository = null,
    ) {
    }

    /**
     * @param array<int, \Dibi\Row> $playRows
     * @return array<string, mixed>
     */
    public function data(array $playRows, string $range, ?string $user = null, ?\DateTimeImmutable $now = null, ?string $library = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $start = match ($range) {
            'week' => $now->modify('-7 days'),
            'month' => $now->modify('-30 days'),
            'year' => $now->modify('-12 months'),
            default => null,
        };

        $requests = ($this->repository ?? new SeerrRequestRepository())->eligibleForPeriod($start);
        $scopedUser = ($user !== null && $user !== '') ? $user : null;

        if ($scopedUser !== null) {
            $requests = array_values(array_filter(
                $requests,
                fn (\Dibi\Row $request): bool => $this->requestMatchesUser($request, $scopedUser)
            ));
        }

        if ($requests === []) {
            return $this->empty($scopedUser !== null);
        }

        $plays = $this->qualifyingPlays($playRows);
        $groups = [];

        foreach ($requests as $request) {
            $display = trim((string) ($request['requested_by'] ?? '')) ?: 'Unknown';
            $filterUser = trim((string) ($request['jellyfin_username'] ?? ''));
            if ($filterUser === '') {
                $filterUser = $display;
            }

            $key = mb_strtolower($filterUser);
            $groups[$key] ??= [
                'user' => $display,
                'filterUser' => $filterUser,
                'eligible' => 0,
                'watched' => 0,
                'unwatched' => [],
            ];
            $groups[$key]['eligible']++;

            $watched = $this->wasWatched($request, $plays);
            if ($watched) {
                $groups[$key]['watched']++;
            } else {
                $groups[$key]['unwatched'][] = [
                    'title' => (string) $request['title'],
                    'year' => $request['year'] !== null ? (string) $request['year'] : null,
                    'type' => (string) $request['media_type'] === 'tv' ? 'Series' : 'Movie',
                ];
            }
        }

        $users = [];
        foreach ($groups as $group) {
            $eligible = (int) $group['eligible'];
            $watched = (int) $group['watched'];
            $rate = (int) round(($watched / $eligible) * 100);
            $unwatchedCount = count($group['unwatched']);

            $users[] = [
                'user' => (string) $group['user'],
                'href' => HistoryFilters::path(
                    '/statistics',
                    $range,
                    (string) $group['filterUser'],
                    $library !== null && $library !== '' ? ['library' => $library] : [],
                ),
                'initials' => RequestMapper::initials((string) $group['user']),
                'eligible' => $eligible,
                'watched' => $watched,
                'unwatched' => $unwatchedCount,
                'rate' => $rate,
                'rateLabel' => $rate . '%',
                'unwatchedTitles' => $group['unwatched'],
            ];
        }

        usort($users, static fn (array $a, array $b): int => [$a['rate'], -$a['unwatched'], -$a['eligible']] <=> [$b['rate'], -$b['unwatched'], -$b['eligible']]);

        $eligible = array_sum(array_map(static fn (array $row): int => (int) $row['eligible'], $users));
        $watched = array_sum(array_map(static fn (array $row): int => (int) $row['watched'], $users));
        $unwatched = $scopedUser !== null
            ? $users[0]['unwatchedTitles']
            : [];

        return [
            'visible' => true,
            'isUserScoped' => $scopedUser !== null,
            'users' => $users,
            'eligible' => $eligible,
            'watched' => $watched,
            'rate' => (int) round(($watched / $eligible) * 100),
            'unwatched' => $unwatched,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(bool $isUserScoped): array
    {
        return [
            'visible' => false,
            'isUserScoped' => $isUserScoped,
            'users' => [],
            'eligible' => 0,
            'watched' => 0,
            'rate' => 0,
            'unwatched' => [],
        ];
    }

    /**
     * Plays that count as "started watching": finished, or at least 5 minutes in.
     *
     * @param array<int, \Dibi\Row> $rows
     * @return array<int, array{user: string, title: string, kind: string, started: string}>
     */
    private function qualifyingPlays(array $rows): array
    {
        $plays = [];

        foreach ($rows as $row) {
            $watchedSec = (int) ($row['watched_sec'] ?? 0);
            $finished = (int) ($row['is_finished'] ?? 0) === 1;
            if (!$finished && $watchedSec < self::MIN_WATCHED_SEC) {
                continue;
            }

            $user = mb_strtolower(trim((string) ($row['user_name'] ?? '')));
            if ($user === '') {
                continue;
            }

            $type = (string) ($row['item_type'] ?? '');
            $series = trim((string) ($row['series_name'] ?? ''));
            $name = trim((string) ($row['item_name'] ?? ''));

            if ($type === 'Episode' && $series !== '') {
                $kind = 'tv';
                $title = $series;
            } elseif ($type === 'Movie' && $name !== '') {
                $kind = 'movie';
                $title = $name;
            } else {
                continue;
            }

            $plays[] = [
                'user' => $user,
                'title' => $this->normalizeTitle($title),
                'kind' => $kind,
                'started' => $this->dateString($row['started_at']),
            ];
        }

        return $plays;
    }

    /**
     * @param array<int, array{user: string, title: string, kind: string, started: string}> $plays
     */
    private function wasWatched(\Dibi\Row $request, array $plays): bool
    {
        $kind = (string) $request['media_type'] === 'tv' ? 'tv' : 'movie';
        $title = $this->normalizeTitle((string) $request['title']);
        $requestedAt = $this->dateString($request['requested_at']);
        $user = $this->requestUserKey($request);

        if ($title === '' || $user === '') {
            return false;
        }

        foreach ($plays as $play) {
            if ($play['kind'] !== $kind || $play['title'] !== $title) {
                continue;
            }
            if ($play['user'] !== $user) {
                continue;
            }
            if ($play['started'] < $requestedAt) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function requestMatchesUser(\Dibi\Row $request, string $user): bool
    {
        $needle = mb_strtolower(trim($user));
        if ($needle === '') {
            return false;
        }

        $jellyfin = mb_strtolower(trim((string) ($request['jellyfin_username'] ?? '')));
        $display = mb_strtolower(trim((string) ($request['requested_by'] ?? '')));

        return $jellyfin === $needle || $display === $needle;
    }

    private function requestUserKey(\Dibi\Row $request): string
    {
        $jellyfin = mb_strtolower(trim((string) ($request['jellyfin_username'] ?? '')));
        if ($jellyfin !== '') {
            return $jellyfin;
        }

        return mb_strtolower(trim((string) ($request['requested_by'] ?? '')));
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $collapsed = preg_replace('/\s+/', ' ', $title);

        return is_string($collapsed) ? $collapsed : $title;
    }
}
