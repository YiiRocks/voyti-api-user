<?php

declare(strict_types=1);

use YiiRocks\Voyti\Api\Controller\V1\User\UserController;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    'yiirocks/voyti' => [
        'api' => [
            // Wrapped in the shared auth + admin-access middleware group voyti-api owns and defines in
            // its own config/routes.php; the `v1/` path prefix and `voyti/api-v1-` name prefix live on
            // the outer Group, and the `users` resource segment on the nested one, so neither is
            // repeated per route.
            'routes' => [
                Group::create('v1/')
                    ->namePrefix('voyti/api-v1-')
                    ->routes(
                        Group::create('users')
                            ->namePrefix('users-')
                            ->routes(
                                Route::get('')->name('index')->action([UserController::class, 'index']),
                                Route::get('/{id:\d+}')->name('view')->action([UserController::class, 'view']),
                                Route::post('')->name('create')->action([UserController::class, 'create']),
                                Route::patch('/{id:\d+}')->name('update')->action([UserController::class, 'update']),
                                Route::delete('/{id:\d+}')->name('delete')->action([UserController::class, 'delete']),
                            ),
                    ),
            ],
        ],
    ],
];
