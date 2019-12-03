<?php

namespace Hotrush\QuickBooksManager;

use Hotrush\QuickBooksManager\Http\Requests\AuthCallbackRequest;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\ServiceException;

class QuickBooksConnection
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $config;

    /**
     * @var QuickBooksToken
     */
    private $token;

    /**
     * @var DataService
     */
    private $client;

    /**
     * QuickBooksConnection constructor.
     * @param $name
     * @param array $config
     */
    public function __construct($name, array $config)
    {
        $this->name = $name;
        $this->config = $config;
        $this->token = $this->loadTokenFromDatabase();

        $this->initClient();
    }

    private function initClient($forceRefresh = false)
    {
        $this->client = DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $this->config['client_id'],
            'ClientSecret' => $this->config['client_secret'],
            'RedirectURI' => route(config('quickbooks_manager.callback_route'), ['connection' => $this->name]),
            'scope' => $this->config['scope'],
            'baseUrl' => $this->config['base_url'],
            'QBORealmID' => $this->token ? $this->token->realm_id : null,
            'accessTokenKey' => $this->token && !$this->token->isExpired() ? $this->token->access_token : null,
            'refreshTokenKey' => $this->token && $forceRefresh && $this->token->isRefreshable() ? $this->token->refresh_token : null,
        ])
            ->setLogLocation(config('quickbooks_manager.logs_path'))
            ->throwExceptionOnError(true);
    }

    /**
     * @return string
     */
    public function getAuthorizationRedirectUrl()
    {
        return $this->client->getOAuth2LoginHelper()->getAuthorizationCodeURL();
    }

    /**
     * @param AuthCallbackRequest $request
     * @throws \QuickBooksOnline\API\Exception\SdkException
     * @throws \QuickBooksOnline\API\Exception\ServiceException
     */
    public function handleAuthorizationCallback(AuthCallbackRequest $request)
    {
        $accessToken = $this->client
            ->getOAuth2LoginHelper()
            ->exchangeAuthorizationCodeForToken(
                $request->get('code'),
                $request->get('realmId')
            );

        $this->updateAccessToken($accessToken);
    }

    /**
     * @return QuickBooksToken
     */
    private function loadTokenFromDatabase()
    {
        return QuickBooksToken::where('connection', $this->name)
            ->orderBy('issued_at', 'desc')
            ->first();
    }

    /**
     * @throws \QuickBooksOnline\API\Exception\ServiceException
     */
    public function refreshToken()
    {
        $this->initClient(true);

        $accessToken = $this->client->getOAuth2LoginHelper()->refreshToken();

        $this->updateAccessToken($accessToken);
    }

    /**
     * @param OAuth2AccessToken $accessToken
     */
    private function updateAccessToken(OAuth2AccessToken $accessToken)
    {
        $this->client->updateOAuth2Token($accessToken);

        $this->token = QuickBooksToken::createFromToken($this->name, $accessToken);

        QuickBooksToken::removeExpired($this->name, [$this->token->id]);
    }

    /**
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws ServiceException
     */
    public function __call($method, $parameters)
    {
        return $this->client->$method(...$parameters);
    }
}