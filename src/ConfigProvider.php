<?php

declare(strict_types=1);

namespace HyperfEloquentFilter;

use HyperfEloquentFilter\Commands\MakeEloquentFilterCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'commands' => [
                MakeEloquentFilterCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for eloquent-filter.',
                    'source' => __DIR__ . '/../publish/eloquent_filter.php',
                    'destination' => BASE_PATH . '/config/autoload/eloquent_filter.php',
                ],
            ],
        ];
    }
}
