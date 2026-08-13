<?php

declare(strict_types=1);

use Mk\Framework\Jellyfin\JellyfinUserAvatars;
use PHPUnit\Framework\TestCase;

final class JellyfinUserAvatarsTest extends TestCase
{
    public function testProxyUrlRequiresAUserId(): void
    {
        $this->assertNull(JellyfinUserAvatars::proxyUrl(''));
        $this->assertNull(JellyfinUserAvatars::proxyUrl('user/1'));
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80',
            JellyfinUserAvatars::proxyUrl('user-1')
        );
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80&tag=abc123',
            JellyfinUserAvatars::proxyUrl('user-1', 'abc123')
        );
        $this->assertSame(
            '/api/image.php?user=user-1&maxWidth=80',
            JellyfinUserAvatars::proxyUrl('user-1', 'tag with spaces')
        );
    }

    public function testUrlResolvesByIdEvenWithoutAnImageTag(): void
    {
        $avatars = new JellyfinUserAvatars();
        $avatars->loadFrom([
            [
                'Id' => 'user-with-photo',
                'Name' => 'Maya Okafor',
                'PrimaryImageTag' => 'maya-face',
            ],
            [
                'Id' => 'user-without-tag',
                'Name' => 'Jon Bell',
            ],
            [
                'Id' => 'user-image-tags',
                'Name' => 'Sam',
                'ImageTags' => ['Primary' => 'sam-face'],
            ],
        ]);

        $this->assertSame(
            '/api/image.php?user=user-with-photo&maxWidth=80&tag=maya-face',
            $avatars->url('user-with-photo')
        );
        $this->assertSame(
            '/api/image.php?user=user-with-photo&maxWidth=80&tag=maya-face',
            $avatars->url('', 'Maya Okafor')
        );
        $this->assertSame(
            '/api/image.php?user=user-without-tag&maxWidth=80',
            $avatars->url('user-without-tag')
        );
        $this->assertSame(
            '/api/image.php?user=user-without-tag&maxWidth=80',
            $avatars->url('', 'Jon Bell')
        );
        $this->assertSame(
            '/api/image.php?user=user-image-tags&maxWidth=80&tag=sam-face',
            $avatars->url('', 'sam')
        );
        $this->assertNull($avatars->url('', 'Nobody'));
    }
}
