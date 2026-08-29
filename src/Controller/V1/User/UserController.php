<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\Controller\V1\User;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use YiiRocks\Voyti\Api\Middleware\ApiTokenAuthenticationMiddleware;
use YiiRocks\Voyti\Event\User\UserEvent;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\Password\PasswordGeneratorInterface;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\User\UserCreationHelper;
use YiiRocks\Voyti\Service\User\UserUpdateHelper;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Input\Http\Attribute\Parameter\Query;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * REST CRUD endpoints for users under the `voyti-routes-api` route group, authenticated via
 * {@see ApiTokenAuthenticationMiddleware}. Returns JSON only, no view
 * rendering.
 */
final readonly class UserController
{
    private const int MAX_PER_PAGE = 100;

    public function __construct(
        private VoytiConfig $config,
        private DataResponseFactoryInterface $responseFactory,
        private PasswordGeneratorInterface $passwordGenerator,
        private PasswordHistoryService $passwordHistoryService,
        private UserCreationHelper $userCreationHelper,
        private UserUpdateHelper $userUpdateHelper,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function create(
        #[Body('email')]
        string $email = '',
        #[Body('username')]
        string $username = '',
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        $password = $password !== '' ? $password : $this->passwordGenerator->generate(12);

        // Users provisioned through this admin-only API are confirmed immediately; they bypass the
        // email confirmation flow that self-registration goes through.
        $user = $this->userCreationHelper->buildUser($email, $username, $password);
        try {
            $this->userCreationHelper->persistAndNotifySkippingConfirmation($user);
        } catch (RuntimeException $exception) {
            return $this->responseFactory->createResponse(
                ['error' => $exception->getMessage()],
                Status::BAD_REQUEST,
            );
        }

        return $this->responseFactory->createResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'message' => 'User created',
        ], Status::CREATED);
    }

    public function delete(#[RouteArgument] int $id): ResponseInterface
    {
        $user = $this->resolveUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        $user->delete();
        $this->eventDispatcher->dispatch(new UserEvent($user, UserEvent::DELETE));
        return $this->responseFactory->createResponse([
            'message' => 'User deleted',
        ]);
    }

    public function index(
        #[Query('username')]
        string $username = '',
        #[Query('email')]
        string $email = '',
        #[Query('status')]
        string $status = '',
        /**
         * @infection-ignore-all Mutating this default to 0 is behaviorally identical to 1: both are
         * floored to 1 by max(1, $page) below, so no test can observe the difference.
         */
        #[Query('page')]
        int $page = 1,
        #[Query('perPage')]
        int $perPage = 25,
    ): ResponseInterface {
        $reader = new QueryDataReader(User::searchQuery([
            'username' => $username,
            'email' => $email,
            'status' => $status,
        ]));

        $pageSize = min(max(1, $perPage), self::MAX_PER_PAGE);
        $sizedPaginator = (new OffsetPaginator($reader))->withPageSize($pageSize);
        $currentPage = min(max(1, $page), max(1, $sizedPaginator->getTotalPages()));
        $paginator = $sizedPaginator->withCurrentPage($currentPage);

        /** @infection-ignore-all — iterator keys are already 0-indexed, preserve_keys has no effect */
        /** @var list<User> $users */
        $users = iterator_to_array($paginator->read(), false);
        $items = array_map(fn(User $u) => [
            'id' => $u->getId(),
            'username' => $u->getUsername(),
            'email' => $u->getEmail(),
            'createdAt' => $u->getCreatedAt(),
            'confirmedAt' => $u->getConfirmedAt(),
            'blockedAt' => $u->getBlockedAt(),
        ], $users);

        return $this->responseFactory->createResponse([
            'items' => $items,
            'totalCount' => $paginator->getTotalItems(),
            'currentPage' => $paginator->getCurrentPage(),
            'pageSize' => $paginator->getPageSize(),
            'totalPages' => $paginator->getTotalPages(),
        ]);
    }

    public function update(
        #[RouteArgument]
        int $id,
        #[Body('password')]
        string $password = '',
        #[Body('username')]
        ?string $username = null,
        #[Body('email')]
        ?string $email = null,
    ): ResponseInterface {
        $user = $this->resolveUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        if ($password !== '' && $this->passwordHistoryService->wasUsedRecently($user, $password)) {
            return $this->responseFactory->createResponse(
                ['error' => 'This password has been used recently. Please choose a different one.'],
                Status::BAD_REQUEST,
            );
        }

        $changedFields = $this->userUpdateHelper->changedFields($user, $username, $email, $password);

        try {
            $this->userUpdateHelper->apply(
                $user,
                $changedFields,
                static function (User $user) use ($username, $email): void {
                    if ($username !== null) {
                        $user->setUsername($username);
                    }
                    if ($email !== null) {
                        $user->setEmail($email);
                    }
                },
                $password,
            );
        } catch (ActionPreventedException $exception) {
            return $this->responseFactory->createResponse(
                ['error' => $exception->getMessage()],
                Status::BAD_REQUEST,
            );
        }

        return $this->responseFactory->createResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'message' => 'User updated',
        ]);
    }

    public function view(#[RouteArgument] int $id): ResponseInterface
    {
        $user = $this->resolveUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->responseFactory->createResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'createdAt' => $user->getCreatedAt(),
            'confirmedAt' => $user->getConfirmedAt(),
            'blockedAt' => $user->getBlockedAt(),
        ]);
    }

    private function resolveUser(int $id): User|ResponseInterface
    {
        $user = User::findById($id);
        return $user ?? $this->responseFactory->createResponse(
            ['error' => 'Not found'],
            Status::NOT_FOUND,
        );
    }
}
