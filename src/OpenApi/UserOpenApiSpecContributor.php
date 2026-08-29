<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\OpenApi;

use Override;

final readonly class UserOpenApiSpecContributor implements OpenApiSpecContributorInterface
{
    #[Override]
    public function getMethodSpec(string $routeName, string $method): ?array
    {
        return match ($routeName) {
            'voyti/api-v1-users-index' => $method === 'get' ? $this->specListUsers() : null,
            'voyti/api-v1-users-view' => $method === 'get' ? $this->specGetUser() : null,
            'voyti/api-v1-users-create' => $method === 'post' ? $this->specCreateUser() : null,
            'voyti/api-v1-users-update' => $method === 'patch' ? $this->specUpdateUser() : null,
            'voyti/api-v1-users-delete' => $method === 'delete' ? $this->specDeleteUser() : null,
            default => null,
        };
    }

    #[Override]
    public function schemas(): array
    {
        return [
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'createdAt' => ['type' => 'integer'],
                    'confirmedAt' => ['type' => ['integer', 'null']],
                    'blockedAt' => ['type' => ['integer', 'null']],
                ],
            ],
            'UserCreateRequest' => [
                'type' => 'object',
                'required' => ['username', 'email'],
                'properties' => [
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'description' => 'Generated if omitted'],
                ],
            ],
            'UserUpdateRequest' => [
                'type' => 'object',
                'properties' => [
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string'],
                ],
            ],
            'UserCreatedResponse' => [
                'type' => 'object',
                'required' => ['id', 'username', 'email', 'message'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'UserUpdatedResponse' => [
                'type' => 'object',
                'required' => ['id', 'username', 'email', 'message'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'PaginatedUsers' => [
                'type' => 'object',
                'required' => ['items', 'totalCount', 'currentPage', 'pageSize', 'totalPages'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/User'],
                    ],
                    'totalCount' => ['type' => 'integer'],
                    'currentPage' => ['type' => 'integer'],
                    'pageSize' => ['type' => 'integer'],
                    'totalPages' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specCreateUser(): array
    {
        return [
            'operationId' => 'createUser',
            'summary' => 'Create a user',
            'tags' => ['Users'],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/UserCreateRequest'],
                    ],
                ],
            ],
            'responses' => [
                '201' => [
                    'description' => 'User created',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/UserCreatedResponse'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specDeleteUser(): array
    {
        return [
            'operationId' => 'deleteUser',
            'summary' => 'Delete a user',
            'tags' => ['Users'],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'User deleted',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/MessageResponse'],
                        ],
                    ],
                ],
                '404' => [
                    'description' => 'User not found',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specGetUser(): array
    {
        return [
            'operationId' => 'getUser',
            'summary' => 'Get a user by ID',
            'tags' => ['Users'],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'User details',
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/User']],
                    ],
                ],
                '404' => [
                    'description' => 'User not found',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specListUsers(): array
    {
        return [
            'operationId' => 'listUsers',
            'summary' => 'List users',
            'tags' => ['Users'],
            'parameters' => [
                ['name' => 'username', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'email', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                [
                    'name' => 'perPage',
                    'in' => 'query',
                    'schema' => ['type' => 'integer', 'default' => 25, 'maximum' => 100],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'Paginated list of users',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/PaginatedUsers'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specUpdateUser(): array
    {
        return [
            'operationId' => 'updateUser',
            'summary' => 'Update a user',
            'tags' => ['Users'],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/UserUpdateRequest'],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'User updated',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/UserUpdatedResponse'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Validation error',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
                '404' => [
                    'description' => 'User not found',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
