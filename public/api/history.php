<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mk\Framework\Config;
use Mk\Framework\Csrf;
use Mk\Framework\Jellyfin\HistoryEditService;
use Mk\Framework\Log;

define('ROOT_DIR', dirname(__DIR__, 2));

require_once ROOT_DIR . '/utils/@constants.php';
require_once ROOT_DIR . '/vendor/autoload.php';

Dotenv::createImmutable(ROOT_DIR)->safeLoad();

include_once ROOT_DIR . '/utils/@settings.php';
include_once ROOT_DIR . '/utils/@api-guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

    return;
}

if ($method === 'POST') {
    Csrf::checkHeader();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$service = new HistoryEditService();

try {
    if ($method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $payload = $service->payload($id);
        if ($payload === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Play not found.']);

            return;
        }

        echo json_encode($payload, JSON_THROW_ON_ERROR);

        return;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid JSON body.']);

        return;
    }

    $action = (string) ($body['action'] ?? '');
    $id = (int) ($body['id'] ?? 0);

    if ($id < 1 || $action === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Missing play id or action.']);

        return;
    }

    if ($action === 'update') {
        $watchedSec = (int) ($body['watched_sec'] ?? -1);
        if ($watchedSec < 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Watched time must be zero or more.']);

            return;
        }

        $payload = $service->updateWatched($id, $watchedSec);
        if ($payload === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Play not found.']);

            return;
        }

        echo json_encode(['ok' => true, 'play' => $payload], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'delete') {
        if (!$service->delete($id)) {
            http_response_code(404);
            echo json_encode(['error' => 'Play not found.']);

            return;
        }

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'merge') {
        $sourceIds = $body['source_ids'] ?? null;
        if (!is_array($sourceIds) || $sourceIds === []) {
            http_response_code(422);
            echo json_encode(['error' => 'Select at least one play to merge.']);

            return;
        }

        $payload = $service->merge($id, $sourceIds);
        if ($payload === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Those plays cannot be merged. Same title and user only.']);

            return;
        }

        echo json_encode(['ok' => true, 'play' => $payload], JSON_THROW_ON_ERROR);

        return;
    }

    http_response_code(422);
    echo json_encode(['error' => 'Unknown action.']);
} catch (\Throwable $e) {
    http_response_code(500);
    Log::logException($e);

    echo json_encode([
        'error' => 'Could not update history.',
        'detail' => Config::isDebug() ? $e->getMessage() : null,
    ], JSON_THROW_ON_ERROR);
}
