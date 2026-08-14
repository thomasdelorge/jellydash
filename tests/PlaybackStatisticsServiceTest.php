<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use Mk\Framework\Jellyseerr\RequestMapper;
use Mk\Framework\Jellyseerr\SeerrRequestRepository;
use PHPUnit\Framework\TestCase;

final class PlaybackStatisticsServiceTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private PlaybackStatisticsService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        try {
            $this->dibi = Container::db()->getDibi();
            new PlayHistoryRepository(Container::db());
            new SeerrRequestRepository(Container::db());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->cleanup();
        $this->now = new \DateTimeImmutable('2099-08-20 12:00:00');
        $this->service = new PlaybackStatisticsService(new PlayHistoryRepository(Container::db()));
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
    }

    public function testUserFilterScopesPlaysAndPreservesUserOnRangeHrefs(): void
    {
        $this->insertPlay(['user_name' => 'PHPUnit Stats Alice', 'watched_sec' => 600, 'item_name' => 'Dune']);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-bob',
            'user_name' => 'PHPUnit Stats Bob',
            'item_id' => 'phpunit-stats-bob-item',
            'watched_sec' => 1200,
            'item_name' => 'Arrival',
        ]);

        $alice = $this->service->data('week', 'PHPUnit Stats Alice', $this->now);
        $global = $this->service->data('week', null, $this->now);

        $this->assertTrue($alice['isUserScoped']);
        $this->assertSame('PHPUnit Stats Alice', $alice['user']);
        $this->assertStringContainsString('PHPUnit Stats Alice', $alice['subLabel']);
        $this->assertSame(['Total Watch Time', 'Total Plays', 'Finish Rate', 'Transcode Rate'], array_column($alice['kpis'], 'label'));
        $this->assertContains('Active Users', array_column($global['kpis'], 'label'));
        $movieHref = $alice['mostWatched']['movies'][0]['href'] ?? '';
        $this->assertStringContainsString('user=PHPUnit+Stats+Alice', $movieHref);
        $this->assertStringContainsString('/history?', $movieHref);
        $this->assertStringContainsString('range=7', $movieHref);
        $this->assertStringContainsString('search=Dune', $movieHref);
        $this->assertSame('10m', $alice['totalWatch']);

        $aliceWatch = $this->kpi($alice, 'Total Watch Time')['value'];
        $this->assertSame('10m', $aliceWatch);
        $this->assertGreaterThanOrEqual(1800, $this->watchSecondsFromLabel($global['totalWatch']));
    }

    public function testFinishRateIgnoresLiveTvWithoutRuntime(): void
    {
        $this->insertPlay([
            'user_name' => 'PHPUnit Stats Alice',
            'watched_sec' => 3600,
            'runtime_sec' => 3600,
            'is_finished' => 1,
            'item_name' => 'Dune',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-live',
            'item_id' => 'phpunit-stats-live-item',
            'user_name' => 'PHPUnit Stats Alice',
            'item_type' => 'TvChannel',
            'item_name' => 'BBC One',
            'library' => 'Live TV',
            'watched_sec' => 900,
            'runtime_sec' => 0,
            'is_finished' => 0,
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-abandon',
            'item_id' => 'phpunit-stats-abandon-item',
            'user_name' => 'PHPUnit Stats Alice',
            'item_name' => 'Arrival',
            'watched_sec' => 120,
            'runtime_sec' => 3600,
            'is_finished' => 0,
        ]);

        $stats = $this->service->data('week', 'PHPUnit Stats Alice', $this->now);
        $finish = $this->kpi($stats, 'Finish Rate');

        $this->assertSame('50%', $finish['value']);
    }

    public function testLibraryMixSplitsWatchTimeByMediaType(): void
    {
        $this->insertPlay([
            'user_name' => 'PHPUnit Stats Alice',
            'library' => 'Movies',
            'item_type' => 'Movie',
            'item_name' => 'Dune',
            'watched_sec' => 3600,
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-tv',
            'item_id' => 'phpunit-stats-tv-item',
            'user_name' => 'PHPUnit Stats Alice',
            'item_type' => 'Episode',
            'series_name' => 'The Expanse',
            'item_name' => 'It Reaches Out',
            'library' => 'TV Shows',
            'watched_sec' => 1200,
        ]);

        $stats = $this->service->data('week', 'PHPUnit Stats Alice', $this->now);
        $byName = [];
        foreach ($stats['libraryMix']['legend'] as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(['Movies', 'TV Shows'], array_column($stats['libraryMix']['legend'], 'name'));
        $this->assertSame('75%', $byName['Movies']['pct']);
        $this->assertSame('25%', $byName['TV Shows']['pct']);
    }

    public function testHeatmapBucketsAKnownMondayEveningPlay(): void
    {
        $mondayEvening = $this->mondayThisWeek()->setTime(21, 5, 0);
        $this->insertPlay([
            'user_name' => 'PHPUnit Stats Alice',
            'started_at' => $mondayEvening->format('Y-m-d H:i:s'),
            'item_name' => 'Dune',
        ]);

        $stats = $this->service->data('week', 'PHPUnit Stats Alice', $this->now);
        $monday = $stats['heatmap']['days'][0];
        $cell = $monday['cells'][21];

        $this->assertSame('Mon', $monday['label']);
        $this->assertSame(1, $cell['count']);
        $this->assertStringContainsString('Mon 21:00', $cell['title']);
        $this->assertSame(0, $stats['heatmap']['days'][1]['cells'][21]['count']);
    }

    public function testMonthHeatmapSpreadsAllTimeWatchByCalendarMonth(): void
    {
        $this->insertPlay([
            'started_at' => '2098-12-15 18:00:00',
            'watched_sec' => 3600,
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-alice-aug',
            'item_id' => 'phpunit-stats-alice-aug-item',
            'started_at' => '2099-08-10 18:00:00',
            'watched_sec' => 600,
        ]);

        $all = $this->service->data('all', 'PHPUnit Stats Alice', $this->now);
        $week = $this->service->data('week', 'PHPUnit Stats Alice', $this->now);

        $this->assertFalse($week['monthHeatmap']['visible']);
        $this->assertTrue($all['monthHeatmap']['visible']);
        $this->assertSame(['2098', '2099'], array_column($all['monthHeatmap']['years'], 'label'));

        $december = $all['monthHeatmap']['years'][0]['cells'][11];
        $august = $all['monthHeatmap']['years'][1]['cells'][7];
        $september = $all['monthHeatmap']['years'][1]['cells'][8];

        $this->assertSame(1, $december['count']);
        $this->assertStringContainsString('Dec 2098', $december['title']);
        $this->assertSame(1, $august['count']);
        $this->assertTrue($september['future']);
        $this->assertSame(0, $september['count']);
    }

    public function testRequestWatchRateCountsOnlyTheRequesterAndIgnoresPending(): void
    {
        $this->insertRequest(92001, 'Dune', 'movie', 'Alice D', 'phpunit-stats-alice', RequestMapper::MEDIA_AVAILABLE, RequestMapper::REQUEST_APPROVED);
        $this->insertRequest(92002, 'Pending Flick', 'movie', 'Alice D', 'phpunit-stats-alice', RequestMapper::MEDIA_AVAILABLE, RequestMapper::REQUEST_PENDING);
        $this->insertRequest(92003, 'The Expanse', 'tv', 'Alice D', 'phpunit-stats-alice', RequestMapper::MEDIA_PARTIALLY_AVAILABLE, RequestMapper::REQUEST_APPROVED);

        $unwatched = $this->service->data('week', 'phpunit-stats-alice', $this->now);
        $this->assertTrue($unwatched['requestWatch']['visible']);
        $this->assertSame(0, $unwatched['requestWatch']['rate']);
        $this->assertSame(2, $unwatched['requestWatch']['eligible']);

        $this->insertPlay([
            'user_name' => 'phpunit-stats-alice',
            'item_type' => 'Movie',
            'item_name' => 'Dune',
            'library' => 'Movies',
            'watched_sec' => 600,
            'started_at' => $this->now->modify('-2 days')->format('Y-m-d H:i:s'),
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-bob-dune',
            'item_id' => 'phpunit-stats-bob-dune-item',
            'user_name' => 'PHPUnit Stats Bob',
            'item_type' => 'Movie',
            'item_name' => 'Dune',
            'library' => 'Movies',
            'watched_sec' => 3600,
            'started_at' => $this->now->modify('-2 days')->modify('+1 hour')->format('Y-m-d H:i:s'),
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-stats-expanse',
            'item_id' => 'phpunit-stats-expanse-item',
            'user_name' => 'phpunit-stats-alice',
            'item_type' => 'Episode',
            'series_name' => 'The Expanse',
            'item_name' => 'It Reaches Out',
            'library' => 'TV Shows',
            'watched_sec' => 900,
            'started_at' => $this->now->modify('-1 day')->format('Y-m-d H:i:s'),
        ]);

        $watched = $this->service->data('week', 'phpunit-stats-alice', $this->now);
        $this->assertSame(100, $watched['requestWatch']['rate']);
        $this->assertSame(2, $watched['requestWatch']['watched']);
        $this->assertSame([], $watched['requestWatch']['unwatched']);

        $bob = $this->service->data('week', 'PHPUnit Stats Bob', $this->now);
        $this->assertFalse($bob['requestWatch']['visible']);
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function kpi(array $stats, string $label): array
    {
        foreach ($stats['kpis'] as $kpi) {
            if ($kpi['label'] === $label) {
                return $kpi;
            }
        }

        $this->fail('Missing KPI: ' . $label);
    }

    private function mondayThisWeek(): \DateTimeImmutable
    {
        $dow = (int) $this->now->format('N');

        return $this->now->modify('-' . ($dow - 1) . ' days');
    }

    private function watchSecondsFromLabel(string $label): int
    {
        if (preg_match('/^(\d+)h(?:\s+(\d+)m)?$/', $label, $m) === 1) {
            return ((int) $m[1] * 3600) + ((int) ($m[2] ?? 0) * 60);
        }
        if (preg_match('/^(\d+)m$/', $label, $m) === 1) {
            return (int) $m[1] * 60;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function insertPlay(array $override = []): void
    {
        $row = array_merge([
            'session_key' => 'phpunit-stats-alice',
            'user_name' => 'PHPUnit Stats Alice',
            'item_id' => 'phpunit-stats-alice-item',
            'item_type' => 'Movie',
            'item_name' => 'Dune',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'is_finished' => 0,
            'started_at' => $this->now->modify('-2 days')->format('Y-m-d H:i:s'),
            'updated_at' => $this->now->modify('-2 days')->modify('+10 minutes')->format('Y-m-d H:i:s'),
        ], $override);

        $this->dibi->insert('play_history', $row)->execute();
    }

    private function insertRequest(
        int $requestId,
        string $title,
        string $mediaType,
        string $requestedBy,
        string $jellyfinUsername,
        int $mediaStatus,
        int $requestStatus,
    ): void {
        $this->dibi->insert('seerr_requests', [
            'request_id' => $requestId,
            'media_type' => $mediaType,
            'tmdb_id' => $requestId,
            'title' => $title,
            'year' => '2021',
            'requested_by' => $requestedBy,
            'jellyfin_username' => $jellyfinUsername,
            'request_status' => $requestStatus,
            'media_status' => $mediaStatus,
            'is_4k' => 0,
            'requested_at' => $this->now->modify('-3 days')->format('Y-m-d H:i:s'),
            'notified' => 1,
            'created_at' => $this->now->modify('-3 days')->format('Y-m-d H:i:s'),
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-stats-%')
            ->execute();
        $this->dibi->delete('seerr_requests')
            ->where('request_id >= %i', 92000)
            ->where('request_id < %i', 93000)
            ->execute();
    }
}
