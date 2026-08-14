<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/**
 * Builds proxy URLs for Jellyfin profile pictures. A user id is enough: the
 * browser fetches /api/image.php, which 404s when the account has no photo
 * (initials stay). /Users is only used to resolve a name to an id, and to
 * attach an image tag for cache-busting when Jellyfin provides one.
 */
final class JellyfinUserAvatars
{
    /** @var array<string, string> user id => image tag */
    private array $tagsById = [];
    /** @var array<string, string> lowercased name => user id */
    private array $idsByName = [];
    private bool $loaded = false;

    public function __construct(private ?JellyfinClient $client = null)
    {
    }

    /**
     * Proxy URL for a user's Jellyfin profile image, or null when we cannot
     * resolve a user id.
     */
    public function url(?string $userId, ?string $userName = null, int $maxWidth = 80): ?string
    {
        $id = trim((string) $userId);
        if ($id === '') {
            $this->ensureLoaded();
            $name = mb_strtolower(trim((string) $userName));
            $id = $name !== '' ? ($this->idsByName[$name] ?? '') : '';
        } else {
            $this->ensureLoaded();
        }

        if ($id === '') {
            return null;
        }

        return self::proxyUrl($id, $this->tagsById[$id] ?? '', $maxWidth);
    }

    /**
     * @param array<int, array<string, mixed>> $users
     */
    public function loadFrom(array $users): void
    {
        $this->tagsById = [];
        $this->idsByName = [];

        foreach ($users as $user) {
            $id = trim((string) ($user['Id'] ?? ''));
            if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                continue;
            }

            $name = trim((string) ($user['Name'] ?? ''));
            if ($name !== '') {
                $this->idsByName[mb_strtolower($name)] = $id;
            }

            $tag = trim((string) ($user['PrimaryImageTag'] ?? ''));
            if ($tag === '' && isset($user['ImageTags']) && is_array($user['ImageTags'])) {
                $tag = trim((string) ($user['ImageTags']['Primary'] ?? ''));
            }

            if ($tag !== '') {
                $this->tagsById[$id] = $tag;
            }
        }

        $this->loaded = true;
    }

    public static function proxyUrl(string $userId, string $imageTag = '', int $maxWidth = 80): ?string
    {
        $userId = trim($userId);
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $userId)) {
            return null;
        }

        $url = '/api/image.php?user=' . rawurlencode($userId)
            . '&maxWidth=' . max(32, min(256, $maxWidth));

        $imageTag = trim($imageTag);
        if ($imageTag !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $imageTag)) {
            $url .= '&tag=' . rawurlencode($imageTag);
        }

        return $url;
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        try {
            $this->loadFrom(($this->client ?? new JellyfinClient())->users());
        } catch (\Throwable) {
            $this->tagsById = [];
            $this->idsByName = [];
        }
    }
}
