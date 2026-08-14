<?php

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class PlayHistoryRepositoryTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private PlayHistoryRepository $repository;

    protected function setUp(): void
    {
        try {
            $this->dibi = Container::db()->getDibi();
            $this->repository = new PlayHistoryRepository(Container::db());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->dibi)) {
            $this->cleanup();
        }
    }

    public function testLogActiveStreamsInsertsAndKeepsHighestWatchedProgress(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');

        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);
        $this->repository->logActiveStreams([$this->stream(600, 3600)], $now->modify('+5 seconds'));

        $row = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', 'phpunit-session')
            ->where('item_id = %s', 'phpunit-item')
            ->fetch();

        $this->assertNotNull($row);
        $this->assertSame(900, (int) $row['watched_sec']);
        $this->assertSame(3600, (int) $row['runtime_sec']);
        $this->assertSame('PHPUnit Viewer', $row['user_name']);
        $this->assertSame('TV Shows', $row['library']);
        $this->assertSame('Audio Transcode', $row['play_method_detail']);
        $this->assertSame('HEVC', $row['source_video_codec']);
        $this->assertSame('AAC', $row['target_audio_codec']);
        $this->assertSame(1, (int) $row['is_video_direct']);
        $this->assertSame(0, (int) $row['is_audio_direct']);
        $this->assertStringContainsString('Audio codec not supported', (string) $row['transcode_reasons']);
    }

    public function testLogActiveStreamsStartsFreshPlayAfterLongGap(): void
    {
        $start = new \DateTimeImmutable('2099-06-19 12:00:00');

        // First viewing reaches the half-way point (not finished).
        $this->repository->logActiveStreams([$this->stream(1800, 3600)], $start);

        // Three hours later the same long-lived session+item shows up again near
        // the beginning: a re-watch, which must become a fresh play, not an
        // update of the old row.
        $this->repository->logActiveStreams([$this->stream(120, 3600)], $start->modify('+3 hours'));

        $row = $this->dibi->select('*')
            ->from('play_history')
            ->where('session_key = %s', 'phpunit-session')
            ->where('item_id = %s', 'phpunit-item')
            ->fetch();

        $started = $row['started_at'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($row['started_at'])
            : new \DateTimeImmutable((string) $row['started_at']);

        $this->assertNotNull($row);
        $this->assertSame(120, (int) $row['watched_sec']); // reset to the new play, not kept at 1800
        $this->assertSame('2099-06-19 15:00:00', $started->format('Y-m-d H:i:s'));
    }

    public function testWatchTimeTodaySumsRowsForCurrentDay(): void
    {
        $now = new \DateTimeImmutable('2099-06-19 12:00:00');

        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);

        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-yesterday',
            'item_id' => 'phpunit-yesterday-item',
            'item_type' => 'Movie',
            'play_method' => 'DirectPlay',
            'watched_sec' => 1200,
            'runtime_sec' => 1200,
            'started_at' => '2099-06-18 12:00:00',
            'updated_at' => '2099-06-18 12:05:00',
        ])->execute();

        $this->assertSame(900, $this->repository->watchTimeToday($now));
    }

    public function testHistoryRowsApplyFiltersAndExposeUsers(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $this->repository->logActiveStreams([$this->stream(900, 3600)], $now);

        $this->dibi->insert('play_history', [
            'session_key' => 'phpunit-movie',
            'user_id' => 'phpunit-user-2',
            'user_name' => 'Jon Bell',
            'item_id' => 'phpunit-movie-item',
            'item_type' => 'Movie',
            'item_name' => 'Arrival',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'client' => 'Web',
            'device' => 'MacBook',
            'watched_sec' => 1200,
            'runtime_sec' => 1200,
            'started_at' => '2026-06-19 11:00:00',
            'updated_at' => '2026-06-19 11:20:00',
        ])->execute();

        $movieRows = $this->repository->historyRows(new HistoryFilters(library: 'Movies', range: 'all'), $now);
        $searchRows = $this->repository->historyRows(new HistoryFilters(search: 'expanse', range: 'all'), $now);

        $this->assertCount(1, $movieRows);
        $this->assertSame('Arrival', $movieRows[0]['item_name']);
        $this->assertCount(1, $searchRows);
        $this->assertSame('The Expanse', $searchRows[0]['series_name']);
        $this->assertSame(1, $this->repository->historyTotal(new HistoryFilters(user: 'PHPUnit Viewer', range: 'all'), $now));
        $this->assertContains('Jon Bell', $this->repository->users());
        $this->assertContains('PHPUnit Viewer', $this->repository->users());
    }

    public function testHistoryRowsHonorLimitAndOffset(): void
    {
        $now = new \DateTimeImmutable('2026-06-19 12:00:00');
        $this->insertPlay([
            'session_key' => 'phpunit-page-a',
            'item_name' => 'Newest',
            'started_at' => '2026-06-19 12:00:00',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-page-b',
            'item_name' => 'Middle',
            'started_at' => '2026-06-19 11:00:00',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-page-c',
            'item_name' => 'Oldest',
            'started_at' => '2026-06-19 10:00:00',
        ]);

        $pageTwo = $this->repository->historyRows(new HistoryFilters(
            range: 'all',
            limit: 1,
            offset: 1,
        ), $now);

        $this->assertCount(1, $pageTwo);
        $this->assertSame('Middle', $pageTwo[0]['item_name']);
    }

    public function testUpdateRuntimeForItemRewritesEveryPlayAndFinishedFlag(): void
    {
        $shortId = $this->insertPlay([
            'session_key' => 'phpunit-runtime-a',
            'item_id' => 'phpunit-runtime-item',
            'watched_sec' => 3641,
            'runtime_sec' => 3641,
            'is_finished' => 1,
        ]);
        $longId = $this->insertPlay([
            'session_key' => 'phpunit-runtime-b',
            'item_id' => 'phpunit-runtime-item',
            'watched_sec' => 6000,
            'runtime_sec' => 6000,
            'is_finished' => 1,
        ]);
        $otherId = $this->insertPlay([
            'session_key' => 'phpunit-runtime-other',
            'item_id' => 'phpunit-runtime-other',
            'watched_sec' => 400,
            'runtime_sec' => 400,
            'is_finished' => 1,
        ]);

        $this->assertSame(2, $this->repository->updateRuntimeForItem('phpunit-runtime-item', 6120));

        $short = $this->repository->findById($shortId);
        $long = $this->repository->findById($longId);
        $other = $this->repository->findById($otherId);

        $this->assertNotNull($short);
        $this->assertNotNull($long);
        $this->assertNotNull($other);
        $this->assertSame(6120, (int) $short['runtime_sec']);
        $this->assertSame(6120, (int) $long['runtime_sec']);
        $this->assertSame(0, (int) $short['is_finished']);
        $this->assertSame(1, (int) $long['is_finished']);
        $this->assertSame(400, (int) $other['runtime_sec']);
        $this->assertSame(1, (int) $other['is_finished']);
    }

    public function testItemIdsNeedingRuntimeLookupSkipsPlaysUnderRuntime(): void
    {
        $overId = $this->insertPlay([
            'session_key' => 'phpunit-lookup-over',
            'item_id' => 'phpunit-widow',
            'watched_sec' => 3488,
            'runtime_sec' => 3303,
        ]);
        $okId = $this->insertPlay([
            'session_key' => 'phpunit-lookup-ok',
            'item_id' => 'phpunit-ok-runtime',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
        ]);
        $equalId = $this->insertPlay([
            'session_key' => 'phpunit-lookup-equal',
            'item_id' => 'phpunit-dolittle',
            'watched_sec' => 3641,
            'runtime_sec' => 3641,
        ]);

        $rows = [
            $this->repository->findById($overId),
            $this->repository->findById($okId),
            $this->repository->findById($equalId),
        ];

        $ids = $this->repository->itemIdsNeedingRuntimeLookup($rows);

        $this->assertContains('phpunit-widow', $ids);
        $this->assertContains('phpunit-dolittle', $ids);
        $this->assertNotContains('phpunit-ok-runtime', $ids);
    }

    public function testApplyLookedUpRuntimesClearsOverRuntimeWhenTitleIsLonger(): void
    {
        $id = $this->insertPlay([
            'session_key' => 'phpunit-lookup-apply',
            'item_id' => 'phpunit-widow-apply',
            'watched_sec' => 3488,
            'runtime_sec' => 3303,
            'is_finished' => 1,
        ]);
        $row = $this->repository->findById($id);
        $this->assertNotNull($row);

        $patched = $this->repository->applyLookedUpRuntimes([$row], [
            'phpunit-widow-apply' => 8040,
        ]);

        $this->assertSame(8040, (int) $patched[0]['runtime_sec']);
        $this->assertSame(0, (int) $patched[0]['is_finished']);
        $this->assertFalse(3488 > (int) $patched[0]['runtime_sec']);

        $stored = $this->repository->findById($id);
        $this->assertNotNull($stored);
        $this->assertSame(8040, (int) $stored['runtime_sec']);
    }

    /**
     * @return array<string, mixed>
     */
    private function stream(int $watchedSec, int $runtimeSec): array
    {
        return [
            'id' => 'phpunit-session',
            'itemId' => 'phpunit-item',
            'itemType' => 'Episode',
            'itemName' => 'It Reaches Out',
            'seriesName' => 'The Expanse',
            'seasonEp' => 'S3 E8',
            'library' => 'TV Shows',
            'userId' => 'phpunit-user',
            'user' => 'PHPUnit Viewer',
            'client' => 'Android TV',
            'device' => 'Living Room Shield',
            'playMethod' => 'Transcode',
            'methodLabel' => 'Audio Transcode',
            'watchedSec' => $watchedSec,
            'runtimeSec' => $runtimeSec,
            'sourceVideoCodec' => 'HEVC',
            'sourceAudioCodec' => 'AC3',
            'sourceContainer' => 'MKV',
            'targetVideoCodec' => 'H.264',
            'targetAudioCodec' => 'AAC',
            'targetContainer' => 'MP4',
            'isVideoDirect' => true,
            'isAudioDirect' => false,
            'transcodeReasons' => ['Audio codec not supported'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPlay(array $overrides = []): int
    {
        $this->dibi->insert('play_history', array_merge([
            'session_key' => 'phpunit-' . bin2hex(random_bytes(4)),
            'user_id' => 'phpunit-user',
            'user_name' => 'PHPUnit Viewer',
            'item_id' => 'phpunit-item',
            'item_type' => 'Movie',
            'item_name' => 'Arrival',
            'library' => 'Movies',
            'play_method' => 'DirectPlay',
            'client' => 'Web',
            'device' => 'MacBook',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'started_at' => '2099-06-19 12:00:00',
            'updated_at' => '2099-06-19 12:10:00',
            'is_finished' => 0,
            'notified' => 1,
        ], $overrides))->execute();

        return (int) $this->dibi->getInsertId();
    }

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-%')
            ->execute();
    }
}
