<?php

namespace NPR\One\Controllers;

use GuzzleHttp\Client;
use NPR\One\DI\DI;
use NPR\One\Models\AccessTokenModel;
use NPR\One\Models\DeviceCodeModel;


/**
 * Use this controller to power your OAuth2 proxy if you are using the `device_code` grant.
 * The consumer of this codebase is responsible for setting up a router which forwards on the relevant requests
 * to the {@see DeviceCodeController::startDeviceCodeGrant()} and {@see DeviceCodeController::pollDeviceCodeGrant()}
 * public methods in this class.
 *
 * @package NPR\One\Controllers
 */
class DeviceCodeController extends AbstractOAuth2Controller
{
    /**
     * Kicks off a new device code flow
     *
     * @api
     * @param string[] $scopes
     * @return DeviceCodeModel
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function startDeviceCodeGrant(array $scopes)
    {
        $this->ensureExternalProvidersExist();

        $this->validateScopes($scopes);

        $deviceCode = $this->createDeviceCode($scopes);

        $this->getSecureStorageProvider()->set('device_code', $deviceCode->getDeviceCode(), $deviceCode->getExpiresIn());

        return $deviceCode;
    }

    /**
     * Polls the `POST /token` endpoint as part of the device code flow. It will throw an exception if the user
     * has not yet logged in, and return an access token once the user has successfully logged in.
     *
     * @api
     * @return AccessTokenModel
     * @throws \Exception
     */
    public function pollDeviceCodeGrant()
    {
        $this->ensureExternalProvidersExist();

        $deviceCode = $this->getSecureStorageProvider()->get('device_code');
        if (empty($deviceCode))
        {
            throw new \Exception('Could not locate a device code');
        }

        $accessToken = $this->createAccessToken('device_code', [
            'code' => $deviceCode
        ]);

        $this->storeRefreshToken($accessToken);

        return $accessToken;
    }

    /**
     * Creates a new device code by POSTing to the `/device` endpoint. Any error-level output will result in an
     * exception being thrown; this function will only return successfully if an access token was actually created.
     *
     * @internal
     * @param string[] $scopes
     * @return DeviceCodeModel
     * @throws \Exception
     */
    private function createDeviceCode(array $scopes)
    {
        /** @var Client $client */
        $client = DI::container()->get('GuzzleHttp\Client');
        $response = $client->request('POST', $this->getConfigProvider()->getNprApiHost() . '/authorization/v2/device', [
            'headers'     => $this->getHeaders(),
            'form_params' => [
                'client_id'     => $this->getConfigProvider()->getClientId(),
                'client_secret' => $this->getConfigProvider()->getClientSecret(),
                'scope'         => join(' ', $scopes)
            ]
        ]);

        if ($response->getStatusCode() >= 400)
        {
            throw new \Exception("Error during startDeviceCodeGrant: {$this->getResponseMessage($response)}"); // @codeCoverageIgnore
        }

        $body = $response->getBody();
        return new DeviceCodeModel($body);
    }
}
