<?php

declare(strict_types=1);

use Mk\Framework\Container;
use Mk\Framework\Jellyfin\HistoryEditService;
use Mk\Framework\Jellyfin\PlayHistoryRepository;
use PHPUnit\Framework\TestCase;

final class HistoryEditServiceTest extends TestCase
{
    private \Dibi\Connection $dibi;
    private HistoryEditService $service;

    protected function setUp(): void
    {
        try {
            $this->dibi = Container::db()->getDibi();
            $this->service = new HistoryEditService(new PlayHistoryRepository(Container::db()));
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

    public function testPayloadListsSiblingsForTheSameTitleAndDay(): void
    {
        $id = $this->insertPlay([
            'session_key' => 'phpunit-edit-keep',
            'item_id' => 'phpunit-edit-item',
            'item_type' => 'Episode',
            'series_name' => 'The Expanse',
            'item_name' => 'It Reaches Out',
            'season_ep' => 'S3 E8',
            'watched_sec' => 1800,
            'runtime_sec' => 3120,
        ]);
        $siblingId = $this->insertPlay([
            'session_key' => 'phpunit-edit-sib',
            'item_id' => 'phpunit-edit-item',
            'watched_sec' => 400,
            'runtime_sec' => 3120,
            'started_at' => '2099-06-19 11:00:00',
        ]);

        $payload = $this->service->payload($id);

        $this->assertNotNull($payload);
        $this->assertSame('The Expanse', $payload['title']);
        $this->assertSame('S3 E8 - It Reaches Out', $payload['sub']);
        $this->assertSame(1800, $payload['watched_sec']);
        $this->assertSame(1, $payload['sibling_count']);
        $this->assertSame($siblingId, $payload['siblings'][0]['id']);
    }

    public function testUpdateWatchedMarksFinishedNearRuntime(): void
    {
        $id = $this->insertPlay([
            'session_key' => 'phpunit-edit-finish',
            'watched_sec' => 600,
            'runtime_sec' => 3600,
        ]);

        $payload = $this->service->updateWatched($id, 3500);

        $this->assertNotNull($payload);
        $this->assertSame(3500, $payload['watched_sec']);
        $this->assertTrue($payload['finished']);
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
