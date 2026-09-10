<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use think\Model;

final class ThinkModelContext
{
    private static bool $registered = false;

    private static ?ThinkManagerResolver $resolver = null;

    public static function register(ThinkManagerResolver $resolver): void
    {
        self::$resolver = $resolver;
        if (self::$registered) {
            return;
        }

        Model::maker(static function (Model $model): void {
            $manager = self::$resolver?->current()
                ?? throw new \LogicException('Think ORM adapter is not registered.');
            $model->setOption('db', $manager);
            if ($model->getAutoWriteTimestamp() === null) {
                $model->isAutoWriteTimestamp($manager->getConfig('auto_timestamp', true));
            }
            if ($model->getDateFormat() === null) {
                $model->setDateFormat($manager->getConfig('datetime_format', 'Y-m-d H:i:s'));
            }
        });
        self::$registered = true;
    }
}
