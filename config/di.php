<?php

declare(strict_types=1);

use YiiRocks\Voyti\Api\OpenApi\UserOpenApiSpecContributor;

/** @var array $params */

return [
    // Tagged so voyti-api's OpenApiSpecBuilder merges this package's paths/schemas into the shared
    // openapi.json spec.
    UserOpenApiSpecContributor::class => [
        'class' => UserOpenApiSpecContributor::class,
        'tags' => ['voyti-api.openapi-contributor'],
    ],
];
