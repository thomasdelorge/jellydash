<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

final class PlayHistoryRepository
{
    private \Dibi\Connection $db;
    private DatabasePlatform $platform;
    /** @var \WeakMap<\Dibi\Connection, true>|null */
    private static ?\WeakMap $schemaConnections = null;

    // A gap longer than this between updates means the previous play ended and a
    // new viewing started (e.g. a re-watch on a client that keeps one session id
    // alive). We deliberately do NOT treat a backwards position jump as a new
    // play; that's just the viewer seeking within the same play.
    private const PLAY_GAP_SECONDS = 1800;

    public function __construct(?Database $database = null)
    {
        $database ??= Container::db();
        $this->db = $database->getDibi();
        $this->platform = $database->getPlatform();
        $this->ensureSchema();
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     */
    public function logActiveStreams(array $streams, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now');
        $nowSql = $now->format('Y-m-d H:i:s');

        foreach ($streams as $stream) {
            $sessionKey = (string) ($stream['id'] ?? '');
            $itemId = (string) ($stream['itemId'] ?? '');

            if ($sessionKey === '' || $itemId === '') {
                continue;
            }

            $existing = $this->db->select('id, watched_sec, updated_at, is_finished')
                ->from('play_history')
                ->where('session_key = %s', $sessionKey)
                ->where('item_id = %s', $itemId)
                ->fetch();

            $position = max(0, (int) ($stream['watchedSec'] ?? 0));
            $runtimeSec = max(0, (int) ($stream['runtimeSec'] ?? 0));

            // Continuation of the existing row, or a brand-new play?
            $isNewPlay = false;
            if ($existing) {
                $secondsSinceUpdate = $now->getTimestamp()
                    - (new \DateTimeImmutable((string) $existing['updated_at']))->getTimestamp();

                // A finished row only becomes a new play when the position
                // jumped back well below what was already watched (an actual
                // restart). A finished item still playing out (credits) is a
                // continuation; treating it as new would reset the row and
                // re-fire its alert on every poll until the session ends.
                $restarted = (bool) $existing['is_finished']
                    && $position + 60 < (int) $existing['watched_sec'];

                $isNewPlay = $restarted || $secondsSinceUpdate > self::PLAY_GAP_SECONDS;
            }

            $previousWatchedSec = ($existing && !$isNewPlay) ? (int) $existing['watched_sec'] : 0;
            $watchedSec = max($position, $previousWatchedSec);
            $isFinished = $runtimeSec > 0 && $watchedSec >= (int) floor($runtimeSec * 0.95);

            $data = [
                'user_id' => $this->nullableString($stream['userId'] ?? null),
                'user_name' => $this->nullableString($stream['user'] ?? null),
                'item_type' => (string) ($stream['itemType'] ?? ''),
                'series_name' => $this->nullableString($stream['seriesName'] ?? null),
                'item_name' => $this->nullableString($stream['itemName'] ?? null),
                'season_ep' => $this->nullableString($stream['seasonEp'] ?? null),
                'library' => $this->nullableString($stream['library'] ?? null),
                'play_method' => (string) ($stream['playMethod'] ?? ''),
                'play_method_detail' => $this->nullableString($stream['methodLabel'] ?? null),
                'client' => $this->nullableString($stream['client'] ?? null),
                'device' => $this->nullableString($stream['device'] ?? null),
                'source_video_codec' => $this->nullableString($stream['sourceVideoCodec'] ?? null),
                'source_audio_codec' => $this->nullableString($stream['sourceAudioCodec'] ?? null),
                'source_container' => $this->nullableString($stream['sourceContainer'] ?? null),
                'target_video_codec' => $this->nullableString($stream['targetVideoCodec'] ?? null),
                'target_audio_codec' => $this->nullableString($stream['targetAudioCodec'] ?? null),
                'target_container' => $this->nullableString($stream['targetContainer'] ?? null),
                'is_video_direct' => isset($stream['isVideoDirect']) ? (filter_var($stream['isVideoDirect'], FILTER_VALIDATE_BOOL) ? 1 : 0) : null,
                'is_audio_direct' => isset($stream['isAudioDirect']) ? (filter_var($stream['isAudioDirect'], FILTER_VALIDATE_BOOL) ? 1 : 0) : null,
                'transcode_reasons' => $this->encodedReasons($stream['transcodeReasons'] ?? []),
                'watched_sec' => $watchedSec,
                'runtime_sec' => $runtimeSec,
                'updated_at' => $nowSql,
                'ended_at' => $isFinished ? $nowSql : null,
                'is_finished' => $isFinished ? 1 : 0,
            ];

            if ($existing) {
                // A fresh play reuses the row (session_key + item_id is unique)
                // but resets the start time so the history shows the new viewing.
                // Resetting `notified` lets the alert fire again for the re-watch;
                // a plain continuation leaves it untouched so it never re-fires.
                if ($isNewPlay) {
                    $data['started_at'] = $nowSql;
                    $data['notified'] = 0;
                }

                $this->db->update('play_history', $data)
                    ->where('id = %i', (int) $existing['id'])
                    ->execute();
                continue;
            }

            $data['session_key'] = $sessionKey;
            $data['item_id'] = $itemId;
            $data['started_at'] = $nowSql;
            $data['notified'] = 0;

            try {
                $this->db->insert('play_history', $data)->execute();
            } catch (\Dibi\UniqueConstraintViolationException) {
                // Another writer (the poller and an open dashboard both call this)
                // inserted the same session+item first, so update that row instead.
                unset($data['session_key'], $data['item_id'], $data['started_at']);
                $this->db->update('play_history', $data)
                    ->where('session_key = %s', $sessionKey)
                    ->where('item_id = %s', $itemId)
                    ->execute();
            }
        }
    }

    /**
     * Atomically claim freshly-started plays that haven't been notified yet.
     * Every matched row is flipped to notified=1 (so an alert never fires twice,
     * even across the poller and an open dashboard both recording), but only the
     * rows worth alerting on (a real user, not one of $ignoreUsers) are
     * returned. Plays older than $withinSeconds are retired silently so a poller
     * that was down doesn't fire a stale "started watching" minutes late.
     *
     * @param array<int, string> $ignoreUsers
     * @return array<int, \Dibi\Row>
     */
    public function claimUnnotifiedPlays(array $ignoreUsers, int $withinSeconds, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $since = $now->modify('-' . max(1, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s');

        // Retire anything too old to alert about so it neither fires late nor
        // lingers unnotified forever.
        $this->db->update('play_history', ['notified' => 1])
            ->where('notified = 0')
            ->where('started_at < %s', $since)
            ->execute();

        $rows = $this->db->select('*')
            ->from('play_history')
            ->where('notified = 0')
            ->where('started_at >= %s', $since)
            ->orderBy('started_at')->asc()
            ->fetchAll();

        if ($rows === []) {
            return [];
        }

        // Claim row by row with a conditional update: if another writer (a
        // second poller, a manual run) flipped the flag first, the update
        // touches nothing and that row is skipped, so an alert can't double up.
        $claimed = [];
        foreach ($rows as $row) {
            $this->db->update('play_history', ['notified' => 1])
                ->where('id = %i', (int) $row['id'])
                ->where('notified = 0')
                ->execute();

            if ($this->db->getAffectedRows() === 1) {
                $claimed[] = $row;
            }
        }

        $ignore = array_map(
            static fn (string $u): string => mb_strtolower(trim($u)),
            $ignoreUsers
        );

        return array_values(array_filter($claimed, static function ($r) use ($ignore): bool {
            $user = mb_strtolower(trim((string) ($r['user_name'] ?? '')));

            return $user !== '' && !in_array($user, $ignore, true);
        }));
    }

    public function watchTimeToday(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now');
        $start = $now->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        return (int) $this->db->select('COALESCE(SUM(watched_sec), 0)')
            ->from('play_history')
            ->where('started_at BETWEEN %s AND %s', $start, $end)
            ->fetchSingle();
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function historyRows(HistoryFilters $filters, ?\DateTimeImmutable $now = null): array
    {
        $selection = $this->filteredSelection($filters, $now)
            ->orderBy('started_at')->desc()
            ->limit($filters->limit)
            ->offset($filters->offset);

        return $selection->fetchAll();
    }

    public function historyTotal(HistoryFilters $filters, ?\DateTimeImmutable $now = null): int
    {
        return (int) $this->filteredSelection($filters, $now, 'COUNT(*)')
            ->fetchSingle();
    }

    public function totalRows(): int
    {
        return (int) $this->db->select('COUNT(*)')
            ->from('play_history')
            ->fetchSingle();
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function statisticsRows(string $range, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');

        $since = match ($range) {
            'week' => $now->modify('-7 days'),
            'month' => $now->modify('-30 days'),
            'year' => $now->modify('-12 months'),
            default => null,
        };

        return $this->statisticsRowsForPeriod($since, null);
    }

    /**
     * @param array<int, string> $libraries
     * @return array<int, \Dibi\Row>
     */
    public function rowsForLibraries(array $libraries): array
    {
        $selection = $this->db->select('*')->from('play_history');

        if ($libraries !== []) {
            $selection->where('library IN %in', $libraries);
        }

        return $selection->orderBy('started_at')->asc()->fetchAll();
    }

    /**
     * @return array<int, \Dibi\Row>
     */
    public function statisticsRowsForPeriod(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $selection = $this->db->select('*')->from('play_history');

        if ($start !== null) {
            $selection->where('started_at >= %s', $start->format('Y-m-d H:i:s'));
        }

        if ($end !== null) {
            $selection->where('started_at < %s', $end->format('Y-m-d H:i:s'));
        }

        return $selection->orderBy('started_at')->asc()->fetchAll();
    }

    public function findById(int $id): ?\Dibi\Row
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->select('*')
            ->from('play_history')
            ->where('id = %i', $id)
            ->fetch();

        return $row instanceof \Dibi\Row ? $row : null;
    }

    public function deleteById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        $this->db->delete('play_history')
            ->where('id = %i', $id)
            ->execute();

        return $this->db->getAffectedRows() === 1;
    }

    public function updateWatchedSec(int $id, int $watchedSec): ?\Dibi\Row
    {
        $row = $this->findById($id);
        if ($row === null) {
            return null;
        }

        $watchedSec = max(0, $watchedSec);
        $runtimeSec = (int) $row['runtime_sec'];
        $finished = $this->isFinished($watchedSec, $runtimeSec);

        $this->db->update('play_history', [
            'watched_sec' => $watchedSec,
            'is_finished' => $finished ? 1 : 0,
            'ended_at' => $finished ? $this->endedAtForFinished($row) : null,
        ])
            ->where('id = %i', $id)
            ->execute();

        return $this->findById($id);
    }

    /**
     * Other plays of the same item by the same user on the same day, newest first.
     *
     * @return array<int, \Dibi\Row>
     */
    public function mergeCandidates(int $id, int $limit = 50): array
    {
        $row = $this->findById($id);
        if ($row === null) {
            return [];
        }

        $selection = $this->sameWorkSelection($row)
            ->where('id != %i', $id)
            ->orderBy('started_at')->desc()
            ->limit(max(1, $limit));

        return $selection->fetchAll();
    }

    /**
     * Fold $sourceIds into $keepId: earliest start, summed watched time
     * (each over-runtime play is clamped first so a 24h glitch cannot dominate),
     * then delete the extras.
     *
     * @param array<int, int> $sourceIds
     */
    public function mergePlays(int $keepId, array $sourceIds): ?\Dibi\Row
    {
        $sourceIds = $this->normalizedIds($sourceIds, $keepId);
        if ($keepId < 1 || $sourceIds === []) {
            return null;
        }

        $this->db->begin();

        try {
            $keep = $this->findById($keepId);
            if ($keep === null) {
                $this->db->rollback();

                return null;
            }

            $sources = $this->sameWorkSelection($keep)
                ->where('id IN %in', $sourceIds)
                ->fetchAll();

            if (count($sources) !== count($sourceIds)) {
                $this->db->rollback();

                return null;
            }

            $rows = [$keep, ...$sources];
            $runtimeSec = 0;
            $watchedSec = 0;
            $startedAt = $this->sqlDateValue($keep['started_at']);
            $updatedAt = $this->sqlDateValue($keep['updated_at']);

            foreach ($rows as $row) {
                $rowRuntime = (int) $row['runtime_sec'];
                $rowWatched = (int) $row['watched_sec'];
                if ($rowRuntime > 0 && $rowWatched > $rowRuntime) {
                    $rowWatched = $rowRuntime;
                }

                $runtimeSec = max($runtimeSec, $rowRuntime);
                $watchedSec += $rowWatched;
                $startedAt = min($startedAt, $this->sqlDateValue($row['started_at']));
                $updatedAt = max($updatedAt, $this->sqlDateValue($row['updated_at']));
            }

            $finished = $this->isFinished($watchedSec, $runtimeSec);
            $endedAt = null;
            if ($finished) {
                $endedAt = $updatedAt;
                foreach ($rows as $row) {
                    if ($row['ended_at'] !== null && $row['ended_at'] !== '') {
                        $endedAt = max($endedAt, $this->sqlDateValue($row['ended_at']));
                    }
                }
            }

            $this->db->update('play_history', [
                'watched_sec' => $watchedSec,
                'runtime_sec' => $runtimeSec,
                'started_at' => $startedAt,
                'updated_at' => $updatedAt,
                'ended_at' => $finished ? $endedAt : null,
                'is_finished' => $finished ? 1 : 0,
            ])
                ->where('id = %i', $keepId)
                ->execute();

            $this->db->delete('play_history')
                ->where('id IN %in', $sourceIds)
                ->execute();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->findById($keepId);
    }

    /**
     * @return array<int, string>
     */
    public function users(): array
    {
        $pairs = $this->db->select('DISTINCT user_name')
            ->from('play_history')
            ->where('user_name IS NOT NULL')
            ->orderBy('user_name')
            ->fetchPairs(null, 'user_name');

        return array_values(array_map('strval', $pairs));
    }

    private function ensureSchema(): void
    {
        self::$schemaConnections ??= new \WeakMap();
        if (isset(self::$schemaConnections[$this->db])) {
            return;
        }

        $this->platform->createTable(
            'CREATE TABLE IF NOT EXISTS `play_history` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `session_key` varchar(128) NOT NULL,
                `user_id` varchar(64) DEFAULT NULL,
                `user_name` varchar(128) DEFAULT NULL,
                `item_id` varchar(64) NOT NULL,
                `item_type` varchar(16) NOT NULL,
                `series_name` varchar(255) DEFAULT NULL,
                `item_name` varchar(255) DEFAULT NULL,
                `season_ep` varchar(32) DEFAULT NULL,
                `library` varchar(64) DEFAULT NULL,
                `play_method` varchar(32) NOT NULL,
                `play_method_detail` varchar(64) DEFAULT NULL,
                `client` varchar(64) DEFAULT NULL,
                `device` varchar(64) DEFAULT NULL,
                `source_video_codec` varchar(64) DEFAULT NULL,
                `source_audio_codec` varchar(64) DEFAULT NULL,
                `source_container` varchar(64) DEFAULT NULL,
                `target_video_codec` varchar(64) DEFAULT NULL,
                `target_audio_codec` varchar(64) DEFAULT NULL,
                `target_container` varchar(64) DEFAULT NULL,
                `is_video_direct` tinyint(1) DEFAULT NULL,
                `is_audio_direct` tinyint(1) DEFAULT NULL,
                `transcode_reasons` text DEFAULT NULL,
                `watched_sec` int NOT NULL DEFAULT 0,
                `runtime_sec` int NOT NULL DEFAULT 0,
                `started_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `ended_at` datetime DEFAULT NULL,
                `is_finished` tinyint(1) NOT NULL DEFAULT 0,
                `notified` tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_session_item` (`session_key`, `item_id`),
                KEY `idx_started_at` (`started_at`),
                KEY `idx_user_name` (`user_name`),
                KEY `idx_library` (`library`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `play_history` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `session_key` TEXT NOT NULL,
                `user_id` TEXT DEFAULT NULL,
                `user_name` TEXT DEFAULT NULL,
                `item_id` TEXT NOT NULL,
                `item_type` TEXT NOT NULL,
                `series_name` TEXT DEFAULT NULL,
                `item_name` TEXT DEFAULT NULL,
                `season_ep` TEXT DEFAULT NULL,
                `library` TEXT DEFAULT NULL,
                `play_method` TEXT NOT NULL,
                `play_method_detail` TEXT DEFAULT NULL,
                `client` TEXT DEFAULT NULL,
                `device` TEXT DEFAULT NULL,
                `source_video_codec` TEXT DEFAULT NULL,
                `source_audio_codec` TEXT DEFAULT NULL,
                `source_container` TEXT DEFAULT NULL,
                `target_video_codec` TEXT DEFAULT NULL,
                `target_audio_codec` TEXT DEFAULT NULL,
                `target_container` TEXT DEFAULT NULL,
                `is_video_direct` INTEGER DEFAULT NULL,
                `is_audio_direct` INTEGER DEFAULT NULL,
                `transcode_reasons` TEXT DEFAULT NULL,
                `watched_sec` INTEGER NOT NULL DEFAULT 0,
                `runtime_sec` INTEGER NOT NULL DEFAULT 0,
                `started_at` TEXT NOT NULL,
                `updated_at` TEXT NOT NULL,
                `ended_at` TEXT DEFAULT NULL,
                `is_finished` INTEGER NOT NULL DEFAULT 0,
                `notified` INTEGER NOT NULL DEFAULT 0,
                UNIQUE (`session_key`, `item_id`)
            )'
        );

        $this->platform->createSqliteIndex('idx_started_at', 'play_history', ['started_at']);
        $this->platform->createSqliteIndex('idx_user_name', 'play_history', ['user_name']);
        $this->platform->createSqliteIndex('idx_library', 'play_history', ['library']);

        $this->ensureColumn('play_method_detail', '`play_method_detail` varchar(64) DEFAULT NULL AFTER `play_method`', '`play_method_detail` TEXT DEFAULT NULL');
        $this->ensureColumn('source_video_codec', '`source_video_codec` varchar(64) DEFAULT NULL AFTER `device`', '`source_video_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('source_audio_codec', '`source_audio_codec` varchar(64) DEFAULT NULL AFTER `source_video_codec`', '`source_audio_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('source_container', '`source_container` varchar(64) DEFAULT NULL AFTER `source_audio_codec`', '`source_container` TEXT DEFAULT NULL');
        $this->ensureColumn('target_video_codec', '`target_video_codec` varchar(64) DEFAULT NULL AFTER `source_container`', '`target_video_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('target_audio_codec', '`target_audio_codec` varchar(64) DEFAULT NULL AFTER `target_video_codec`', '`target_audio_codec` TEXT DEFAULT NULL');
        $this->ensureColumn('target_container', '`target_container` varchar(64) DEFAULT NULL AFTER `target_audio_codec`', '`target_container` TEXT DEFAULT NULL');
        $this->ensureColumn('is_video_direct', '`is_video_direct` tinyint(1) DEFAULT NULL AFTER `target_container`', '`is_video_direct` INTEGER DEFAULT NULL');
        $this->ensureColumn('is_audio_direct', '`is_audio_direct` tinyint(1) DEFAULT NULL AFTER `is_video_direct`', '`is_audio_direct` INTEGER DEFAULT NULL');
        $this->ensureColumn('transcode_reasons', '`transcode_reasons` text DEFAULT NULL AFTER `is_audio_direct`', '`transcode_reasons` TEXT DEFAULT NULL');

        // Playback-notification flag. On an existing install, backfill every row
        // to "already notified" so adding the column never fires a burst of
        // alerts for historical plays.
        if (!$this->platform->columnExists('play_history', 'notified')) {
            $this->platform->addColumn(
                'play_history',
                '`notified` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_finished`',
                '`notified` INTEGER NOT NULL DEFAULT 0',
            );
            $this->db->query('UPDATE `play_history` SET `notified` = 1');
        }

        self::$schemaConnections[$this->db] = true;
    }

    private function ensureColumn(string $column, string $mariaDbDefinition, string $sqliteDefinition): void
    {
        if (!$this->platform->columnExists('play_history', $column)) {
            $this->platform->addColumn('play_history', $mariaDbDefinition, $sqliteDefinition);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function encodedReasons(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $reasons = array_values(array_filter(array_map('strval', $value)));

        return $reasons === [] ? null : json_encode($reasons, JSON_THROW_ON_ERROR);
    }

    private function isFinished(int $watchedSec, int $runtimeSec): bool
    {
        return $runtimeSec > 0 && $watchedSec >= (int) floor($runtimeSec * 0.95);
    }

    private function endedAtForFinished(\Dibi\Row $row): string
    {
        if ($row['ended_at'] !== null && $row['ended_at'] !== '') {
            return $this->sqlDateValue($row['ended_at']);
        }

        return $this->sqlDateValue($row['updated_at']);
    }

    private function sameWorkSelection(\Dibi\Row $row): \Dibi\Fluent
    {
        $selection = $this->db->select('*')
            ->from('play_history')
            ->where('item_id = %s', (string) $row['item_id']);

        $userId = trim((string) ($row['user_id'] ?? ''));
        $userName = trim((string) ($row['user_name'] ?? ''));
        $dayStart = substr($this->sqlDateValue($row['started_at']), 0, 10) . ' 00:00:00';
        $dayEnd = (new \DateTimeImmutable(substr($dayStart, 0, 10)))
            ->modify('+1 day')
            ->format('Y-m-d') . ' 00:00:00';

        if ($userId !== '' && $userName !== '') {
            $selection->where('(user_id = %s OR ((user_id IS NULL OR user_id = %s) AND user_name = %s))', $userId, '', $userName);
        } elseif ($userId !== '') {
            $selection->where('user_id = %s', $userId);
        } elseif ($userName !== '') {
            $selection->where('user_name = %s', $userName);
        } else {
            $selection->where('id = %i', 0);
        }

        $selection->where('started_at >= %s', $dayStart);
        $selection->where('started_at < %s', $dayEnd);

        return $selection;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function normalizedIds(array $ids, int $keepId): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $keepId) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    private function sqlDateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    }

    private function filteredSelection(HistoryFilters $filters, ?\DateTimeImmutable $now, string $columns = '*'): \Dibi\Fluent
    {
        $selection = $this->db->select($columns)->from('play_history');

        $rangeDays = $filters->rangeDays();
        if ($rangeDays !== null) {
            $now ??= new \DateTimeImmutable('now');
            $since = $now->modify('-' . $rangeDays . ' days')->format('Y-m-d H:i:s');
            $selection->where('started_at >= %s', $since);
        }

        if ($filters->user !== '') {
            $selection->where('user_name = %s', $filters->user);
        }

        if ($filters->library !== '') {
            $selection->where('library = %s', $filters->library);
        }

        if ($filters->search !== '') {
            $like = '%' . $filters->search . '%';
            $selection->where(
                '(series_name LIKE %s OR item_name LIKE %s OR user_name LIKE %s OR client LIKE %s OR device LIKE %s)',
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        return $selection;
    }
}
