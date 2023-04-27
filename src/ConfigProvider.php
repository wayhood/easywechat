<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace EasyWeChat;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        ServerRequestCreator::class => __DIR__ . '/../class_map/ServerRequestCreator.php',
                    ],
                ],
            ],
        ];
    }
}
