<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\tests\OpenApi;

use YiiRocks\Voyti\Api\OpenApi\UserOpenApiSpecContributor;
use YiiRocks\Voyti\Api\tests\TestCase;

final class UserOpenApiSpecContributorTest extends TestCase
{
    private UserOpenApiSpecContributor $contributor;

    protected function setUp(): void
    {
        $this->contributor = new UserOpenApiSpecContributor();
    }

    public function testGetMethodSpecReturnsCreateUserOperation(): void
    {
        self::assertSame([
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
        ], $this->contributor->getMethodSpec('voyti/api-v1-users-create', 'post'));
    }

    public function testGetMethodSpecReturnsDeleteUserOperation(): void
    {
        self::assertSame([
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
        ], $this->contributor->getMethodSpec('voyti/api-v1-users-delete', 'delete'));
    }

    public function testGetMethodSpecReturnsGetUserOperation(): void
    {
        self::assertSame([
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
        ], $this->contributor->getMethodSpec('voyti/api-v1-users-view', 'get'));
    }

    public function testGetMethodSpecReturnsListUsersOperation(): void
    {
        self::assertSame([
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
        ], $this->contributor->getMethodSpec('voyti/api-v1-users-index', 'get'));
    }

    public function testGetMethodSpecReturnsNullForUnknownRouteOrWrongMethod(): void
    {
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-users-index', 'post'));
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-users-view', 'post'));
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-users-create', 'get'));
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-users-update', 'get'));
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-users-delete', 'get'));
        self::assertNull($this->contributor->getMethodSpec('voyti/api-v1-unknown', 'get'));
    }

    public function testGetMethodSpecReturnsUpdateUserOperation(): void
    {
        self::assertSame([
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
        ], $this->contributor->getMethodSpec('voyti/api-v1-users-update', 'patch'));
    }

    public function testSchemasDefinesUserShapes(): void
    {
        self::assertSame([
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
        ], $this->contributor->schemas());
    }
}
