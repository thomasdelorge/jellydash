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

    public function testUpdateWatchedSecMarksFinishedNearRuntime(): void
    {
        $id = $this->insertPlay([
            'session_key' => 'phpunit-edit',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
            'is_finished' => 0,
        ]);

        $row = $this->repository->updateWatchedSec($id, 3500);

        $this->assertNotNull($row);
        $this->assertSame(3500, (int) $row['watched_sec']);
        $this->assertSame(1, (int) $row['is_finished']);
        $this->assertNotNull($row['ended_at']);
    }

    public function testUpdateWatchedSecClearsFinishedWhenRewound(): void
    {
        $id = $this->insertPlay([
            'session_key' => 'phpunit-rewind',
            'watched_sec' => 3600,
            'runtime_sec' => 3600,
            'is_finished' => 1,
            'ended_at' => '2099-06-19 13:00:00',
        ]);

        $row = $this->repository->updateWatchedSec($id, 120);

        $this->assertNotNull($row);
        $this->assertSame(120, (int) $row['watched_sec']);
        $this->assertSame(0, (int) $row['is_finished']);
        $this->assertNull($row['ended_at']);
    }

    public function testDeleteByIdRemovesRow(): void
    {
        $id = $this->insertPlay(['session_key' => 'phpunit-delete']);

        $this->assertTrue($this->repository->deleteById($id));
        $this->assertNull($this->repository->findById($id));
        $this->assertFalse($this->repository->deleteById($id));
    }

    public function testMergePlaysSumsWatchedTimeAndClampsEachOverRuntimePlay(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-merge-keep',
            'item_id' => 'phpunit-merge-item',
            'watched_sec' => 86400,
            'runtime_sec' => 3600,
            'started_at' => '2099-06-19 20:10:00',
            'updated_at' => '2099-06-19 20:20:00',
        ]);
        $otherId = $this->insertPlay([
            'session_key' => 'phpunit-merge-other',
            'item_id' => 'phpunit-merge-item',
            'watched_sec' => 900,
            'runtime_sec' => 3600,
            'started_at' => '2099-06-19 19:50:00',
            'updated_at' => '2099-06-19 20:00:00',
        ]);

        $merged = $this->repository->mergePlays($keepId, [$otherId]);

        $this->assertNotNull($merged);
        $this->assertSame(4500, (int) $merged['watched_sec']);
        $this->assertSame(1, (int) $merged['is_finished']);
        $this->assertNull($this->repository->findById($otherId));

        $started = $merged['started_at'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($merged['started_at'])
            : new \DateTimeImmutable((string) $merged['started_at']);
        $this->assertSame('2099-06-19 19:50:00', $started->format('Y-m-d H:i:s'));
    }

    public function testMergePlaysAddsEverySelectedPlayEvenPastRuntime(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-merge-four-a',
            'item_id' => 'phpunit-merge-four',
            'watched_sec' => 400,
            'runtime_sec' => 1000,
        ]);
        $ids = [];
        foreach (['b', 'c', 'd'] as $suffix) {
            $ids[] = $this->insertPlay([
                'session_key' => 'phpunit-merge-four-' . $suffix,
                'item_id' => 'phpunit-merge-four',
                'watched_sec' => 400,
                'runtime_sec' => 1000,
            ]);
        }

        $merged = $this->repository->mergePlays($keepId, $ids);

        $this->assertNotNull($merged);
        $this->assertSame(1600, (int) $merged['watched_sec']);
        foreach ($ids as $id) {
            $this->assertNull($this->repository->findById($id));
        }
    }

    public function testMergePlaysAddsPartialWatchedTimes(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-merge-sum-a',
            'item_id' => 'phpunit-merge-sum',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
        ]);
        $otherId = $this->insertPlay([
            'session_key' => 'phpunit-merge-sum-b',
            'item_id' => 'phpunit-merge-sum',
            'watched_sec' => 900,
            'runtime_sec' => 3600,
        ]);

        $merged = $this->repository->mergePlays($keepId, [$otherId]);

        $this->assertNotNull($merged);
        $this->assertSame(1500, (int) $merged['watched_sec']);
        $this->assertSame(0, (int) $merged['is_finished']);
    }

    public function testMergePlaysRejectsDifferentItemOrUser(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-merge-a',
            'item_id' => 'phpunit-item-a',
        ]);
        $otherItem = $this->insertPlay([
            'session_key' => 'phpunit-merge-b',
            'item_id' => 'phpunit-item-b',
        ]);
        $otherUser = $this->insertPlay([
            'session_key' => 'phpunit-merge-c',
            'item_id' => 'phpunit-item-a',
            'user_id' => 'phpunit-someone-else',
            'user_name' => 'Someone Else',
        ]);

        $this->assertNull($this->repository->mergePlays($keepId, [$otherItem]));
        $this->assertNull($this->repository->mergePlays($keepId, [$otherUser]));
        $this->assertNotNull($this->repository->findById($keepId));
        $this->assertNotNull($this->repository->findById($otherItem));
        $this->assertNotNull($this->repository->findById($otherUser));
    }

    public function testMergeCandidatesListsSameWorkAndUser(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-sib-keep',
            'item_id' => 'phpunit-sib-item',
        ]);
        $matchId = $this->insertPlay([
            'session_key' => 'phpunit-sib-match',
            'item_id' => 'phpunit-sib-item',
            'started_at' => '2099-06-19 11:00:00',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-sib-other',
            'item_id' => 'phpunit-other-item',
        ]);
        $this->insertPlay([
            'session_key' => 'phpunit-sib-nextday',
            'item_id' => 'phpunit-sib-item',
            'started_at' => '2099-06-20 11:00:00',
        ]);

        $candidates = $this->repository->mergeCandidates($keepId);
        $ids = array_map(static fn ($row): int => (int) $row['id'], $candidates);

        $this->assertSame([$matchId], $ids);
    }

    public function testMergePlaysRejectsDifferentDay(): void
    {
        $keepId = $this->insertPlay([
            'session_key' => 'phpunit-merge-day-a',
            'item_id' => 'phpunit-merge-day',
            'started_at' => '2099-06-19 22:00:00',
        ]);
        $nextDay = $this->insertPlay([
            'session_key' => 'phpunit-merge-day-b',
            'item_id' => 'phpunit-merge-day',
            'started_at' => '2099-06-20 01:00:00',
        ]);

        $this->assertNull($this->repository->mergePlays($keepId, [$nextDay]));
        $this->assertNotNull($this->repository->findById($keepId));
        $this->assertNotNull($this->repository->findById($nextDay));
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

    private function cleanup(): void
    {
        $this->dibi->delete('play_history')
            ->where('session_key LIKE %s', 'phpunit-%')
            ->execute();
    }
}
