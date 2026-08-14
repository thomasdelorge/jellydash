<?php

declare(strict_types=1);

namespace Mk\Framework\Pages;

use Mk\Framework\Controller;
use Mk\Framework\Jellyfin\HistoryFilters;
use Mk\Framework\Jellyfin\PlaybackStatisticsService;

final class StatisticsController extends Controller
{
    public function handle(): void
    {
        $filters = HistoryFilters::fromRequest();
        $stats = (new PlaybackStatisticsService())->data(
            HistoryFilters::toStatsRange($filters->range),
            $filters->user !== '' ? $filters->user : null,
            null,
            $filters->library !== '' ? $filters->library : null,
        );

        $this->render('statistics/index', [
            'layout' => $this->layout([
                'title' => 'Statistics',
                'page' => 'statistics',
                'hide_footer' => true,
            ]),
            'stats' => $stats,
            'users' => $stats['filterUsers'],
            'filters' => $filters->view(),
            'filter_action' => '/statistics',
            'show_search' => false,
        ]);
    }
}
