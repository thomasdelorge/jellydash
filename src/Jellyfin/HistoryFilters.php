<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Main;

/**
 * Shared History filter bar (?search=, ?user=, ?library=, ?range=7|30|all).
 * Statistics uses the same bar so both pages keep the current view.
 */
final readonly class HistoryFilters
{
    public function __construct(
        public string $search = '',
        public string $user = '',
        public string $library = '',
        public string $range = '30',
        public int $limit = 100,
        public int $offset = 0,
    ) {
    }

    public static function fromRequest(): self
    {
        return new self(
            search: trim((string) (Main::captureGetString('search') ?? '')),
            user: trim((string) (Main::captureGetString('user') ?? '')),
            library: trim((string) (Main::captureGetString('library') ?? '')),
            range: self::toBarRange(Main::captureGetString('range') ?? '30'),
        );
    }

    /**
     * Values the History filter bar submits and displays: 7, 30, or all.
     */
    public static function toBarRange(?string $range): string
    {
        return match ((string) $range) {
            'week', '7' => '7',
            'month', '30' => '30',
            'all' => 'all',
            default => '30',
        };
    }

    /**
     * PlaybackStatisticsService range keys: week, month, or all.
     */
    public static function toStatsRange(?string $range): string
    {
        return match ((string) $range) {
            'week', '7' => 'week',
            'month', '30' => 'month',
            default => 'all',
        };
    }

    /**
     * Query string for History/Statistics sidebar links. Only echoes params that
     * are actually in the request, so other pages don't invent a range.
     */
    public static function navSuffix(): string
    {
        $query = [];
        $rawRange = Main::captureGetString('range');
        if ($rawRange !== null && $rawRange !== '') {
            $query['range'] = self::toBarRange($rawRange);
        }

        $user = trim((string) (Main::captureGetString('user') ?? ''));
        if ($user !== '') {
            $query['user'] = $user;
        }

        $library = trim((string) (Main::captureGetString('library') ?? ''));
        if ($library !== '') {
            $query['library'] = $library;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * @param array<string, string> $extra
     */
    public static function path(string $path, string $range, ?string $user = null, array $extra = []): string
    {
        $query = array_merge(['range' => self::toBarRange($range)], $extra);
        if ($user !== null && $user !== '') {
            $query['user'] = $user;
        }

        return $path . '?' . http_build_query($query);
    }

    public function rangeDays(): ?int
    {
        return match ($this->range) {
            '7', 'week' => 7,
            '30', 'month' => 30,
            'year' => 365,
            default => null,
        };
    }

    /**
     * @return array{search: string, user: string, library: string, range: string}
     */
    public function view(): array
    {
        return [
            'search' => $this->search,
            'user' => $this->user,
            'library' => $this->library,
            'range' => $this->range,
        ];
    }
}
