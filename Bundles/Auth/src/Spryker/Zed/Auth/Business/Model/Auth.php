<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Auth\Business\Model;

use Generated\Shared\Transfer\UserTransfer;
use Spryker\Client\Session\SessionClientInterface;
use Spryker\Shared\Auth\AuthConstants;
use Spryker\Zed\Auth\AuthConfig;
use Spryker\Zed\Auth\Business\Client\StaticToken;
use Spryker\Zed\Auth\Business\Exception\UserNotLoggedException;
use Spryker\Zed\Auth\Dependency\Facade\AuthToUserInterface;
use Spryker\Zed\User\Business\Exception\UserNotFoundException;

class Auth implements AuthInterface
{

    /**
     * @var \Spryker\Client\Session\SessionClientInterface
     */
    protected $session;

    /**
     * @var \Spryker\Zed\Auth\Dependency\Facade\AuthToUserInterface
     */
    protected $userFacade;

    /**
     * @var \Spryker\Zed\Auth\Business\AuthBusinessFactory
     */
    protected $businessFactory;

    /**
     * @var \Spryker\Zed\Auth\AuthConfig
     */
    protected $authConfig;

    /**
     * @var \Spryker\Zed\Auth\Business\Client\StaticToken
     */
    protected $staticToken;

    /**
     * @param \Spryker\Client\Session\SessionClientInterface $session
     * @param \Spryker\Zed\Auth\Dependency\Facade\AuthToUserInterface $userFacade
     * @param \Spryker\Zed\Auth\AuthConfig $authConfig
     * @param \Spryker\Zed\Auth\Business\Client\StaticToken $staticToken
     */
    public function __construct(
        SessionClientInterface $session,
        AuthToUserInterface $userFacade,
        AuthConfig $authConfig,
        StaticToken $staticToken
    ) {
        $this->session = $session;
        $this->userFacade = $userFacade;
        $this->authConfig = $authConfig;
        $this->staticToken = $staticToken;
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return bool
     */
    public function authenticate($username, $password)
    {
        $hasUser = $this->userFacade->hasUserByUsername($username);
        if (!$hasUser) {
            return false;
        }

        $userTransfer = $this->userFacade->getUserByUsername($username);
        try {
            $this->userFacade->getActiveUserById($userTransfer->getIdUser());
        } catch (UserNotFoundException $e) {
            return false;
        }

        $isValidPassword = $this->userFacade->isValidPassword($password, $userTransfer->getPassword());
        if (!$isValidPassword) {
            return false;
        }

        $userTransfer->setLastLogin((new \DateTime())->format(\DateTime::ATOM));

        $token = $this->generateToken($userTransfer);

        $this->registerAuthorizedUser($token, $userTransfer);

        $userTransfer->setPassword(null);
        $this->userFacade->updateUser($userTransfer);

        return true;
    }

    /**
     * @param \Generated\Shared\Transfer\UserTransfer $user
     *
     * @return string
     */
    public function generateToken(UserTransfer $user)
    {
        return hash('sha256', sprintf('%s%s', $user->getPassword(), $user->getIdUser()));
    }

    /**
     * @param string $token
     *
     * @return string
     */
    protected function getSessionKey($token)
    {
        return sprintf(AuthConstants::AUTH_CURRENT_USER_KEY, AuthConstants::AUTH_SESSION_KEY, $token);
    }

    /**
     * @param string $token
     * @param \Generated\Shared\Transfer\UserTransfer $userTransfer
     *
     * @return string
     */
    protected function registerAuthorizedUser($token, UserTransfer $userTransfer)
    {
        $key = $this->getSessionKey($token);
        $this->session->set($key, serialize($userTransfer));

        $this->userFacade->setCurrentUser($userTransfer);

        return unserialize($this->session->get($key));
    }

    /**
     * @return void
     */
    public function logout()
    {
        $token = $this->getCurrentUserToken();
        $key = $this->getSessionKey($token);

        $this->session->remove($key);
    }

    /**
     * @param string $token
     *
     * @return bool
     */
    public function isAuthorized($token)
    {
        if ($this->authorizeStaticToken($token)) {
            return true;
        }

        return $this->authorizeUserToken($token);
    }

    /**
     * This is based on sessions so the token will only be valid during a session lifetime
     *
     * @param string $token
     *
     * @return bool
     */
    protected function authorizeUserToken($token)
    {
        if ($this->userTokenIsValid($token) === false) {
            return false;
        }

        $currentUser = $this->getCurrentUser($token);

        try {
            $realUser = $this->userFacade->getActiveUserById($currentUser->getIdUser());
        } catch (UserNotFoundException $e) {
            return false;
        }

        return $realUser->getPassword() === $currentUser->getPassword();
    }

    /**
     * @return string
     */
    public function getCurrentUserToken()
    {
        $user = $this->userFacade->getCurrentUser();

        return $this->generateToken($user);
    }

    /**
     * @return bool
     */
    public function hasCurrentUser()
    {
        return $this->userFacade->hasCurrentUser();
    }

    /**
     * @param string $hash
     *
     * @return bool
     */
    protected function authorizeStaticToken($hash)
    {
        try {
            $user = $this->getSystemUserByHash($hash);
            $this->registerAuthorizedUser($hash, $user);

            return true;
        } catch (UserNotFoundException $e) {
            return false;
        }
    }

    /**
     * @param string $hash
     *
     * @return bool
     */
    public function hasSystemUserByHash($hash)
    {
        $credentials = $this->authConfig->getUsersCredentials();
        $token = $this->staticToken;
        foreach ($credentials as $username => $credential) {
            $token->setRawToken($credential['token']);
            if ($token->check($hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $hash
     *
     * @throws \Spryker\Zed\User\Business\Exception\UserNotFoundException
     *
     * @return \Generated\Shared\Transfer\UserTransfer
     */
    public function getSystemUserByHash($hash)
    {
        $user = new UserTransfer();

        $credentials = $this->authConfig->getUsersCredentials();
        $token = $this->staticToken;
        foreach ($credentials as $username => $credential) {
            $token->setRawToken($credential['token']);
            if ($token->check($hash) === true) {
                $user->setFirstName($username);
                $user->setLastName($username);
                $user->setUsername($username);
                $user->setPassword($username);

                return $user;
            }
        }

        throw new UserNotFoundException();
    }

    /**
     * @param string $token
     *
     * @return \Generated\Shared\Transfer\UserTransfer
     */
    public function getCurrentUser($token)
    {
        $user = $this->unserializeUserFromSession($token);

        return $user;
    }

    /**
     * @param string $token
     *
     * @throws \Spryker\Zed\Auth\Business\Exception\UserNotLoggedException
     *
     * @return \Generated\Shared\Transfer\UserTransfer
     */
    public function unserializeUserFromSession($token)
    {
        $key = $this->getSessionKey($token);
        $user = unserialize($this->session->get($key));

        if ($user === false) {
            throw new UserNotLoggedException();
        }

        return $user;
    }

    /**
     * @param string $token
     *
     * @return bool
     */
    public function userTokenIsValid($token)
    {
        $key = $this->getSessionKey($token);
        $user = unserialize($this->session->get($key));

        return $user !== false;
    }

    /**
     * @param string $bundle
     * @param string $controller
     * @param string $action
     *
     * @return bool
     */
    public function isIgnorablePath($bundle, $controller, $action)
    {
        $ignorable = $this->authConfig->getIgnorable();
        foreach ($ignorable as $ignore) {
            if (($bundle === $ignore['bundle'] || $ignore['bundle'] === AuthConstants::AUTHORIZATION_WILDCARD) &&
                ($controller === $ignore['controller'] || $ignore['controller'] === AuthConstants::AUTHORIZATION_WILDCARD) &&
                ($action === $ignore['action'] || $ignore['action'] === AuthConstants::AUTHORIZATION_WILDCARD)
            ) {
                return true;
            }
        }

        return false;
    }

}
