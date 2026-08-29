<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\tests\Controller\V1\User;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use YiiRocks\Voyti\Api\Controller\V1\User\UserController;
use YiiRocks\Voyti\Api\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Event\User\AfterAccountUpdateEvent;
use YiiRocks\Voyti\Event\User\BeforeAccountUpdateEvent;
use YiiRocks\Voyti\Event\User\UserEvent;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserPasswordHistory;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordGeneratorInterface;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\Password\RandomPasswordGenerator;
use YiiRocks\Voyti\Service\User\UserCreationHelper;
use YiiRocks\Voyti\Service\User\UserUpdateHelper;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\Middleware\JsonDataResponseMiddleware;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\View\View;

#[AllowMockObjectsWithoutExpectations]
final class UserControllerTest extends DatabaseTestCase
{
    use UserFactoryTrait;

    private VoytiConfig $config;
    private EventCaptureDispatcher $eventDispatcher;
    private MailService $mailService;
    private PasswordGeneratorInterface&MockObject $passwordGenerator;
    private DataResponseFactoryInterface&MockObject $responseFactory;
    private UserCreationHelper $userCreationHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = VoytiConfigFactory::create();
        $this->eventDispatcher = new EventCaptureDispatcher();
        $mailer = new MailCapture();
        $url = $this->createMock(UrlGeneratorInterface::class);
        $this->mailService = new MailService(
            $mailer,
            '/tmp',
            new View(),
            $this->createTranslator(),
            $url,
            'Test',
        );
        $this->userCreationHelper = $this->createUserCreationHelper($this->config);
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
        $this->passwordGenerator = $this->createMock(PasswordGeneratorInterface::class);
        $this->passwordGenerator->method('generate')->willReturn('fallback-generated-password');
    }

    public static function createProvider(): iterable
    {
        yield 'success creates user' => [
            static function (self $test): void {
                $response = $test->expectResponse($test->callback(static function (array $data): bool {
                    return (is_int($data['id']) || is_string($data['id']))
                        && $data['username'] === 'newuser'
                        && $data['email'] === 'new@example.com'
                        && $data['message'] === 'User created'
                        && array_keys($data) === ['id', 'username', 'email', 'message'];
                }), 201);
                $result = $test->createController()->create(email: 'new@example.com', username: 'newuser', password: 'secret123');
                $test->assertSame($response, $result);
                $created = User::findByEmail('new@example.com');
                $test->assertNotNull($created);
                $test->assertNotEmpty($created->getAuthKey());
                $test->assertNotNull($created->getConfirmedAt());
            },
        ];
        yield 'without password uses generated' => [
            static function (self $test): void {
                $test->passwordGenerator = $test->createMock(PasswordGeneratorInterface::class);
                $test->passwordGenerator->expects($test->once())->method('generate')->with(12)->willReturn('generated-secret');
                $test->expectResponse($test->callback(static function (array $data): bool {
                    return (is_int($data['id']) || is_string($data['id']))
                        && $data['username'] === 'generateduser'
                        && $data['email'] === 'generated@example.com'
                        && $data['message'] === 'User created';
                }), 201);
                $test->createController()->create(email: 'generated@example.com', username: 'generateduser');
                $created = User::findByEmail('generated@example.com');
                $test->assertNotNull($created);
                $test->assertTrue(password_verify('generated-secret', $created->getPasswordHash()));
            },
        ];
        yield 'with history records password history' => [
            static function (self $test): void {
                $config = VoytiConfigFactory::create(maxPasswordAge: 90);
                $test->expectResponse($test->callback(static function (array $data): bool {
                    return (is_int($data['id']) || is_string($data['id']))
                        && $data['username'] === 'historyuser'
                        && $data['email'] === 'history@example.com'
                        && $data['message'] === 'User created';
                }), 201);
                $test->createController($config)->create(email: 'history@example.com', username: 'historyuser', password: 'secret123');
                $created = User::findByEmail('history@example.com');
                $test->assertNotNull($created);
                $test->assertCount(1, UserPasswordHistory::findByUserId((int) $created->getId()));
            },
        ];
        yield 'email already exists returns error' => [
            static function (self $test): void {
                $test->createUser('existing', 'existing@example.com');
                $response = $test->expectResponse(['error' => 'Email already exists'], 400);
                $result = $test->createController()->create(email: 'existing@example.com', username: 'newuser', password: 'secret123');
                $test->assertSame($response, $result);
            },
        ];
    }

    public static function deleteProvider(): iterable
    {
        yield 'success deletes user' => [
            static function (self $test): void {
                $user = $test->createUser('deleteuser', 'delete@example.com');
                $userId = (int) $user->getId();
                $response = $test->expectResponse($test->callback(static function (array $data): bool {
                    return $data['message'] === 'User deleted'
                        && array_keys($data) === ['message'];
                }), 200);
                $result = $test->createController()->delete($userId);
                $test->assertSame($response, $result);
                $test->assertNull(User::findById($userId));
                $event = $test->eventDispatcher->getEvent(UserEvent::class);
                $test->assertInstanceOf(UserEvent::class, $event);
                $test->assertSame(UserEvent::DELETE, $event->getType());
            },
        ];
        yield 'not found returns error' => [
            static function (self $test): void {
                $response = $test->expectResponse(['error' => 'Not found'], 404);
                $result = $test->createController()->delete(999999);
                $test->assertSame($response, $result);
            },
        ];
    }

    public static function indexProvider(): iterable
    {
        yield 'clamps per-page above maximum' => [
            [],
            ['perPage' => 500],
            static fn(array $data): bool => $data['pageSize'] === 100,
        ];
        yield 'clamps per-page below minimum' => [
            [],
            ['perPage' => 0],
            static fn(array $data): bool => $data['pageSize'] === 1,
        ];
        yield 'custom per-page' => [
            [
                static fn(self $test): User => $test->createUser('user0', 'user0@example.com'),
                static fn(self $test): User => $test->createUser('user1', 'user1@example.com'),
                static fn(self $test): User => $test->createUser('user2', 'user2@example.com'),
            ],
            ['perPage' => 2],
            static fn(array $data): bool => $data['pageSize'] === 2 && $data['totalPages'] === 2 && count($data['items']) === 2,
        ];
        yield 'default page with multiple users' => [
            [
                static function (self $test): void {
                    for ($i = 0; $i < 26; $i++) {
                        $test->createUser("user$i", "$i@example.com");
                    }
                },
            ],
            [],
            static fn(array $data): bool => $data['currentPage'] === 1
                && count($data['items']) === 25
                && $data['totalCount'] === 26
                && $data['totalPages'] === 2,
        ];
        yield 'empty database returns page one' => [
            [],
            [],
            static fn(array $data): bool => $data['items'] === []
                && $data['totalCount'] === 0
                && $data['currentPage'] === 1
                && $data['totalPages'] === 0,
        ];
        yield 'filters by status' => [
            [
                static fn(self $test): User => $test->createUser('blocked', 'blocked@example.com', blockedAt: time()),
                static fn(self $test): User => $test->createUser('active', 'active@example.com'),
            ],
            ['status' => 'blocked'],
            static fn(array $data): bool => count($data['items']) === 1 && $data['items'][0]['username'] === 'blocked',
        ];
        yield 'filters by username' => [
            [
                static fn(self $test): User => $test->createUser('alice', 'alice@example.com'),
                static fn(self $test): User => $test->createUser('bob', 'bob@example.com'),
            ],
            ['username' => 'alice'],
            static fn(array $data): bool => count($data['items']) === 1 && $data['items'][0]['username'] === 'alice',
        ];
        yield 'page beyond total clamps to last' => [
            [static fn(self $test): User => $test->createUser('testuser', 'test@example.com')],
            ['page' => 999],
            static fn(array $data): bool => $data['currentPage'] === 1 && count($data['items']) === 1,
        ];
        yield 'page two with multiple pages' => [
            [
                static function (self $test): void {
                    for ($i = 0; $i < 26; $i++) {
                        $test->createUser("user$i", "$i@example.com");
                    }
                },
            ],
            ['page' => 2],
            static fn(array $data): bool => $data['currentPage'] === 2
                && count($data['items']) === 1
                && $data['totalCount'] === 26
                && $data['totalPages'] === 2,
        ];
        yield 'page zero clamps to one' => [
            [static fn(self $test): User => $test->createUser('testuser', 'test@example.com')],
            ['page' => 0],
            static fn(array $data): bool => $data['currentPage'] === 1 && count($data['items']) === 1,
        ];
    }

    #[DataProvider('createProvider')]
    public function testCreate(Closure $test): void
    {
        $test($this);
    }

    public function testCreateJsonFormattable(): void
    {
        $controller = new UserController(
            config: $this->config,
            responseFactory: new DataResponseFactory(new Psr17Factory()),
            passwordGenerator: new RandomPasswordGenerator(),
            passwordHistoryService: new PasswordHistoryService(TestPasswordHasherFactory::create(), $this->config),
            userCreationHelper: $this->userCreationHelper,
            userUpdateHelper: $this->createUserUpdateHelper($this->config),
            eventDispatcher: $this->eventDispatcher,
        );

        $response = $controller->create(email: 'real@example.com', username: 'realuser', password: 'secret123');

        $handler = new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $formatted = (new JsonDataResponseMiddleware())->process(
            $this->createMock(ServerRequestInterface::class),
            $handler,
        );

        self::assertSame(201, $formatted->getStatusCode());
        self::assertStringContainsString('application/json', $formatted->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $formatted->getBody(), true);
        self::assertSame('realuser', $body['username']);
        self::assertSame('real@example.com', $body['email']);
        self::assertSame('User created', $body['message']);
    }

    #[DataProvider('deleteProvider')]
    public function testDelete(Closure $test): void
    {
        $test($this);
    }

    #[DataProvider('indexProvider')]
    public function testIndex(array $setup, array $indexArgs, Closure $assertData): void
    {
        foreach ($setup as $setupUser) {
            $setupUser($this);
        }

        $response = $this->expectResponse(self::callback(static function (array $data) use ($assertData): bool {
            $validPagination = $assertData($data)
                && is_array($data['items'])
                && is_int($data['totalCount'])
                && is_int($data['currentPage'])
                && is_int($data['pageSize'])
                && is_int($data['totalPages'])
                && array_keys($data) === ['items', 'totalCount', 'currentPage', 'pageSize', 'totalPages'];

            if (!$validPagination) {
                return false;
            }

            foreach ($data['items'] as $userItem) {
                if (!is_array($userItem)) {
                    return false;
                }
                if (array_keys($userItem) !== ['id', 'username', 'email', 'createdAt', 'confirmedAt', 'blockedAt']) {
                    return false;
                }
                if (!(is_int($userItem['id']) || is_string($userItem['id']))) {
                    return false;
                }
                if (!is_string($userItem['username']) || !is_string($userItem['email'])) {
                    return false;
                }
                if (!(is_int($userItem['createdAt']) || is_string($userItem['createdAt']))) {
                    return false;
                }
                if (!($userItem['confirmedAt'] === null || is_int($userItem['confirmedAt']) || is_string($userItem['confirmedAt']))) {
                    return false;
                }
                if (!($userItem['blockedAt'] === null || is_int($userItem['blockedAt']) || is_string($userItem['blockedAt']))) {
                    return false;
                }
            }

            return true;
        }), 200);

        $controller = $this->createController();
        $result = $controller->index(...$indexArgs);

        $this->assertSame($response, $result);
    }

    #[DataProvider('updateProvider')]
    public function testUpdate(Closure $test): void
    {
        $test($this);
    }

    #[DataProvider('viewProvider')]
    public function testView(Closure $test): void
    {
        $test($this);
    }

    public static function updateProvider(): iterable
    {
        yield 'success updates user' => [
            static function (self $test): void {
                $user = $test->createUser('testuser', 'test@example.com');
                $userId = (int) $user->getId();
                $userIdStr = (string) $userId;
                $user->setUpdatedAt(1000);
                $user->save();
                $beforeUpdate = time();
                $response = $test->expectResponse($test->callback(static function (array $data) use ($userId, $userIdStr): bool {
                    return ($data['id'] === $userIdStr || $data['id'] === $userId)
                        && $data['username'] === 'updated'
                        && $data['email'] === 'updated@example.com'
                        && $data['message'] === 'User updated'
                        && array_keys($data) === ['id', 'username', 'email', 'message'];
                }), 200);
                $result = $test->createController()->update(username: 'updated', email: 'updated@example.com', id: $userId);
                $test->assertSame($response, $result);
                $updated = User::findById($userId);
                $test->assertNotNull($updated);
                $test->assertSame('updated', $updated->getUsername());
                $afterUpdate = time();
                $updatedTimestamp = $updated->getUpdatedAt();
                $test->assertIsInt($updatedTimestamp ?? 0);
                $test->assertGreaterThanOrEqual($beforeUpdate, $updatedTimestamp ?? 0, 'updatedAt should be set to current time');
                $test->assertLessThanOrEqual($afterUpdate, $updatedTimestamp ?? 0);
                $test->assertNotSame(1000, $updatedTimestamp, 'updatedAt should be refreshed from 1000 to current time');
                $beforeEvent = $test->eventDispatcher->getEvent(BeforeAccountUpdateEvent::class);
                $test->assertInstanceOf(BeforeAccountUpdateEvent::class, $beforeEvent);
                $test->assertSame(['username', 'email'], $beforeEvent->getChangedFields());
                $afterEvent = $test->eventDispatcher->getEvent(AfterAccountUpdateEvent::class);
                $test->assertInstanceOf(AfterAccountUpdateEvent::class, $afterEvent);
                $test->assertSame(['username', 'email'], $afterEvent->getChangedFields());
            },
        ];
        yield 'changed field tracking excludes unmodified fields' => [
            static function (self $test): void {
                // Unchanged username is excluded, changed email is included.
                $user = $test->createUser('samename', 'samename@example.com');
                $userId = (int) $user->getId();
                $test->expectResponse($test->callback(static fn(array $data): bool => $data['message'] === 'User updated'), 200);
                $test->createController()->update(username: 'samename', email: 'changed@example.com', id: $userId);
                $afterEvent = $test->eventDispatcher->getEvent(AfterAccountUpdateEvent::class);
                $test->assertInstanceOf(AfterAccountUpdateEvent::class, $afterEvent);
                $test->assertSame(['email'], $afterEvent->getChangedFields());

                // Unchanged email is excluded, changed username is included.
                $test->eventDispatcher = new EventCaptureDispatcher();
                $test->responseFactory = $test->createMock(DataResponseFactoryInterface::class);
                $user = $test->createUser('sameemailuser', 'sameemail@example.com');
                $userId = (int) $user->getId();
                $test->expectResponse($test->callback(static fn(array $data): bool => $data['message'] === 'User updated'), 200);
                $test->createController()->update(username: 'changedname', email: 'sameemail@example.com', id: $userId);
                $afterEvent = $test->eventDispatcher->getEvent(AfterAccountUpdateEvent::class);
                $test->assertInstanceOf(AfterAccountUpdateEvent::class, $afterEvent);
                $test->assertSame(['username'], $afterEvent->getChangedFields());

                // No fields changed at all dispatches no update events.
                $test->eventDispatcher = new EventCaptureDispatcher();
                $test->responseFactory = $test->createMock(DataResponseFactoryInterface::class);
                $user = $test->createUser('nochangeuser', 'nochange@example.com');
                $userId = (int) $user->getId();
                $test->expectResponse($test->callback(static fn(array $data): bool => $data['message'] === 'User updated'), 200);
                $test->createController()->update(id: $userId);
                $test->assertFalse($test->eventDispatcher->hasEvent(BeforeAccountUpdateEvent::class));
                $test->assertFalse($test->eventDispatcher->hasEvent(AfterAccountUpdateEvent::class));
            },
        ];
        yield 'without password no history' => [
            static function (self $test): void {
                $config = VoytiConfigFactory::create(maxPasswordAge: 90);
                $user = $test->createUser('testuser2', 'test2@example.com');
                $userId = (int) $user->getId();
                $test->expectResponse($test->callback(static function (array $data): bool {
                    return (is_int($data['id']) || is_string($data['id']))
                        && $data['username'] === 'updated2'
                        && is_string($data['email'])
                        && $data['message'] === 'User updated';
                }), 200);
                $test->createController($config)->update(username: 'updated2', id: $userId);
                $test->assertCount(0, UserPasswordHistory::findByUserId($userId));
            },
        ];
        yield 'with password records history' => [
            static function (self $test): void {
                $user = $test->createUser('testuser3', 'test3@example.com');
                $userId = (int) $user->getId();
                $userIdStr = (string) $userId;
                $originalHash = $user->getPasswordHash();
                $response = $test->expectResponse($test->callback(static function (array $data) use ($userId, $userIdStr): bool {
                    return ($data['id'] === $userIdStr || $data['id'] === $userId)
                        && $data['username'] === 'updated3'
                        && $data['email'] === 'updated3@example.com'
                        && $data['message'] === 'User updated';
                }), 200);
                $result = $test->createController()->update(password: 'newpass', username: 'updated3', email: 'updated3@example.com', id: $userId);
                $test->assertSame($response, $result);
                $updated = User::findById($userId);
                $test->assertNotNull($updated);
                $test->assertNotSame($originalHash, $updated->getPasswordHash());
                $afterEvent = $test->eventDispatcher->getEvent(AfterAccountUpdateEvent::class);
                $test->assertInstanceOf(AfterAccountUpdateEvent::class, $afterEvent);
                $test->assertSame(['username', 'email', 'password'], $afterEvent->getChangedFields());
            },
        ];
        yield 'not found returns error' => [
            static function (self $test): void {
                $response = $test->expectResponse(['error' => 'Not found'], 404);
                $result = $test->createController()->update(id: 999999);
                $test->assertSame($response, $result);
            },
        ];
        yield 'before update event prevented returns error' => [
            static function (self $test): void {
                $user = $test->createUser('testuser5', 'test5@example.com');
                $userId = (int) $user->getId();
                $dispatcher = $test->createMock(EventDispatcherInterface::class);
                $dispatcher->method('dispatch')->willThrowException(new ActionPreventedException('Update prevented'));
                $controller = new UserController(
                    config: $test->config,
                    responseFactory: $test->responseFactory,
                    passwordGenerator: $test->passwordGenerator,
                    passwordHistoryService: new PasswordHistoryService(TestPasswordHasherFactory::create(), $test->config),
                    userCreationHelper: $test->userCreationHelper,
                    userUpdateHelper: $test->createUserUpdateHelper($test->config, $dispatcher),
                    eventDispatcher: $test->eventDispatcher,
                );
                $response = $test->expectResponse(['error' => 'Update prevented'], 400);
                $result = $controller->update(username: 'updated5', id: $userId);
                $test->assertSame($response, $result);
                $unchanged = User::findById($userId);
                $test->assertNotNull($unchanged);
                $test->assertSame('testuser5', $unchanged->getUsername());
            },
        ];
        yield 'previously used password returns error' => [
            static function (self $test): void {
                $config = VoytiConfigFactory::create(maxPasswordAge: 90);
                $user = $test->createUser('testuser4', 'test4@example.com');
                $userId = (int) $user->getId();
                $passwordHasher = TestPasswordHasherFactory::create();
                $user->setPasswordHash($passwordHasher->hash('originalpass'));
                $user->save();
                (new PasswordHistoryService($passwordHasher, $config))->record($user);
                $response = $test->expectResponse(['error' => 'This password has been used recently. Please choose a different one.'], 400);
                $result = $test->createController($config)->update(password: 'originalpass', id: $userId);
                $test->assertSame($response, $result);
            },
        ];
    }

    public static function viewProvider(): iterable
    {
        yield 'success returns user' => [
            static function (self $test): void {
                $user = $test->createUser('testuser', 'test@example.com');
                $userId = (int) $user->getId();
                $userIdStr = (string) $userId;
                $response = $test->expectResponse($test->callback(static function (array $data) use ($userId, $userIdStr): bool {
                    return ($data['id'] === $userIdStr || $data['id'] === $userId)
                        && $data['username'] === 'testuser'
                        && $data['email'] === 'test@example.com'
                        && (is_int($data['createdAt']) || is_string($data['createdAt']))
                        && ($data['confirmedAt'] === null || is_int($data['confirmedAt']) || is_string($data['confirmedAt']))
                        && ($data['blockedAt'] === null || is_int($data['blockedAt']) || is_string($data['blockedAt']))
                        && array_keys($data) === ['id', 'username', 'email', 'createdAt', 'confirmedAt', 'blockedAt'];
                }), 200);
                $result = $test->createController()->view($userId);
                $test->assertSame($response, $result);
            },
        ];
        yield 'not found returns error' => [
            static function (self $test): void {
                $response = $test->expectResponse(['error' => 'Not found'], 404);
                $result = $test->createController()->view(999999);
                $test->assertSame($response, $result);
            },
        ];
    }

    private function createController(?VoytiConfig $config = null): UserController
    {
        $config ??= $this->config;

        return new UserController(
            config: $config,
            responseFactory: $this->responseFactory,
            passwordGenerator: $this->passwordGenerator,
            passwordHistoryService: new PasswordHistoryService(TestPasswordHasherFactory::create(), $config),
            userCreationHelper: $config === $this->config ? $this->userCreationHelper : $this->createUserCreationHelper($config),
            userUpdateHelper: $this->createUserUpdateHelper($config),
            eventDispatcher: $this->eventDispatcher,
        );
    }

    private function createUserCreationHelper(VoytiConfig $config): UserCreationHelper
    {
        $passwordHasher = TestPasswordHasherFactory::create();

        return new UserCreationHelper(
            $this->mailService,
            new EventCaptureDispatcher(),
            $passwordHasher,
            $config,
            new PasswordHistoryService($passwordHasher, $config),
        );
    }

    private function createUserUpdateHelper(VoytiConfig $config, ?EventDispatcherInterface $dispatcher = null): UserUpdateHelper
    {
        return new UserUpdateHelper(
            new SystemClock(),
            $dispatcher ?? $this->eventDispatcher,
            new PasswordHistoryService(TestPasswordHasherFactory::create(), $config),
        );
    }

    private function expectResponse(mixed $with, int $status): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $this->responseFactory->expects($this->once())
            ->method('createResponse')
            ->with($with, $status)
            ->willReturn($response);

        return $response;
    }
}
