<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Read/write helpers for the history edit modal: payload shaping plus the
 * delete / watched-time / merge mutations.
 */
final class HistoryEditService
{
    public function __construct(
        private PlayHistoryRepository $repository = new PlayHistoryRepository(),
        private ?JellyfinClient $jellyfin = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(int $id): ?array
    {
        $row = $this->repository->findById($id);
        if ($row === null) {
            return null;
        }

        $row = $this->hydrateRuntime($row);

        $play = $this->playView($row);
        $siblings = [];

        foreach ($this->repository->mergeCandidates($id) as $candidate) {
            $siblings[] = $this->siblingView($candidate);
        }

        $play['siblings'] = $siblings;
        $play['sibling_count'] = count($siblings);

        return $play;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateWatched(int $id, int $watchedSec): ?array
    {
        $row = $this->repository->updateWatchedSec($id, $watchedSec);

        return $row === null ? null : $this->payload((int) $row['id']);
    }

    public function delete(int $id): bool
    {
        return $this->repository->deleteById($id);
    }

    /**
     * @param array<int, mixed> $sourceIds
     * @return array<string, mixed>|null
     */
    public function merge(int $id, array $sourceIds): ?array
    {
        $ids = [];
        foreach ($sourceIds as $sourceId) {
            $ids[] = (int) $sourceId;
        }

        $row = $this->repository->mergePlays($id, $ids);

        return $row === null ? null : $this->payload((int) $row['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function playView(\Dibi\Row $row): array
    {
        $watchedSec = (int) $row['watched_sec'];
        $runtimeSec = (int) $row['runtime_sec'];
        $startedAt = $this->dateTime($row['started_at']);
        $itemType = (string) $row['item_type'];
        $seriesName = (string) ($row['series_name'] ?? '');
        $itemName = (string) ($row['item_name'] ?? 'Unknown title');

        return [
            'id' => (int) $row['id'],
            'title' => $itemType === 'Episode' && $seriesName !== '' ? $seriesName : $itemName,
            'sub' => $itemType === 'Episode'
                ? trim((string) ($row['season_ep'] ?? '') . ' - ' . $itemName, ' -')
                : (string) ($row['library'] ?? $itemType),
            'user' => (string) ($row['user_name'] ?? 'Unknown user'),
            'client' => (string) ($row['client'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'started_label' => $startedAt->format('D, M j, Y · H:i'),
            'watched_sec' => $watchedSec,
            'runtime_sec' => $runtimeSec,
            'watched_label' => $this->durationLabel($watchedSec),
            'runtime_label' => $runtimeSec > 0 ? $this->durationLabel($runtimeSec) : '',
            'exceeds_runtime' => $runtimeSec > 0 && $watchedSec > $runtimeSec,
            'finished' => (bool) $row['is_finished'] || ($runtimeSec > 0 && $watchedSec >= (int) floor($runtimeSec * 0.95)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siblingView(\Dibi\Row $row): array
    {
        $startedAt = $this->dateTime($row['started_at']);
        $watchedSec = (int) $row['watched_sec'];

        return [
            'id' => (int) $row['id'],
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'started_label' => $startedAt->format('D, M j, Y · H:i'),
            'watched_sec' => $watchedSec,
            'watched_label' => $this->durationLabel($watchedSec),
            'client' => (string) ($row['client'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
        ];
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Imported Playback Reporting rows stored play duration as runtime_sec.
     * Replace that with the title length from Jellyfin when we can.
     */
    private function hydrateRuntime(\Dibi\Row $row): \Dibi\Row
    {
        if ($this->jellyfin === null) {
            return $row;
        }

        $itemId = (string) $row['item_id'];
        $stored = (int) $row['runtime_sec'];
        try {
            $runtimeSec = $this->jellyfin->itemRuntimeSec($itemId);
        } catch (\Throwable) {
            return $row;
        }

        if ($runtimeSec === null || $runtimeSec === $stored) {
            return $row;
        }

        $this->repository->updateRuntimeForItem($itemId, $runtimeSec);
        $fresh = $this->repository->findById((int) $row['id']);

        return $fresh instanceof \Dibi\Row ? $fresh : $row;
    }

    private function durationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remain = $seconds % 60;

        if ($hours > 0) {
            return $remain > 0
                ? $hours . 'h ' . $minutes . 'm ' . $remain . 's'
                : $hours . 'h ' . $minutes . 'm';
        }

        if ($minutes > 0) {
            return $remain > 0 ? $minutes . 'm ' . $remain . 's' : $minutes . 'm';
        }

        return $remain . 's';
    }
}
