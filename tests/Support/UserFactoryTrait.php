<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\tests\Support;

use YiiRocks\Voyti\Model\User;

trait UserFactoryTrait
{
    /**
     * Builds an in-memory `User`, without persisting it - for unit tests that receive a
     * `User` as a plain argument and never look it up from the database.
     */
    private function buildUser(
        string $username = 'testuser',
        ?string $email = null,
    ): User {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email ?? $username . '@example.com');
        $user->setPasswordHash('hash');
        $user->setAuthKey('key');
        $user->setCreatedAt(time());
        $user->setUpdatedAt(time());

        return $user;
    }

    private function createUser(
        string $username = 'testuser',
        string $email = 'test@example.com',
        string $passwordHash = 'hash',
        ?int $createdAt = null,
        ?int $confirmedAt = null,
        ?int $blockedAt = null,
        ?string $lastLoginIp = null,
        ?int $dataProcessingConsentDate = null,
    ): User {
        $timestamp = $createdAt ?? time();

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPasswordHash($passwordHash);
        $user->setAuthKey('key');
        $user->setCreatedAt($timestamp);
        $user->setUpdatedAt($timestamp);
        if ($confirmedAt !== null) {
            $user->setConfirmedAt($confirmedAt);
        }
        if ($blockedAt !== null) {
            $user->setBlockedAt($blockedAt);
        }
        if ($lastLoginIp !== null) {
            $user->setLastLoginIp($lastLoginIp);
        }
        if ($dataProcessingConsentDate !== null) {
            $user->setDataProcessingConsentDate($dataProcessingConsentDate);
        }
        $user->save();

        return $user;
    }
}
