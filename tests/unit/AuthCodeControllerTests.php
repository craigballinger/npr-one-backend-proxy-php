<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

use NPR\One\Controllers\AuthCodeController;
use NPR\One\DI\DI;
use NPR\One\Interfaces\ConfigInterface;
use NPR\One\Interfaces\StorageInterface;
use NPR\One\Models\AccessTokenModel;
use NPR\One\Providers\CookieProvider;
use NPR\One\Providers\EncryptionProvider;
use NPR\One\Providers\SecureCookieProvider;


class AuthCodeControllerTests extends PHPUnit_Framework_TestCase
{
    const ACCESS_TOKEN_RESPONSE = '{"access_token": "LT8gvVDyeKwQJVVf6xwKAWdK0bOik64faketoken","token_type": "Bearer","expires_in": 690448786,"refresh_token": "6KVn9BOhHhUFR1Yqi2T2pzpTWI9WIfakerefresh"}';
    const ACCESS_TOKEN_RESPONSE_2 = '{"access_token": "LT8gvVDyeKwQJVVf6xwKAWdK0bOik64faketoken","token_type": "Bearer","expires_in": 690448786}';

    /** @var CookieProvider */
    private $mockCookie;
    /** @var SecureCookieProvider */
    private $mockSecureCookie;
    /** @var EncryptionProvider */
    private $mockEncryption;
    /** @var StorageInterface */
    private $mockStorage;
    /** @var ConfigInterface */
    private $mockConfig;
    /** @var Client */
    private $mockClient;

    /** @var string */
    private static $clientId = 'fake_client_id';


    public function setUp()
    {
        $this->mockCookie = $this->getMock(CookieProvider::class);

        $this->mockSecureCookie = $this->getMock(SecureCookieProvider::class);

        $this->mockEncryption = $this->getMock(EncryptionProvider::class);
        $this->mockEncryption->method('isValid')->willReturn(true);
        $this->mockEncryption->method('set')->willReturn(true);

        $this->mockStorage = $this->getMock(StorageInterface::class);
        $this->mockStorage->method('compare')->willReturn(true);

        $this->mockConfig = $this->getMock(ConfigInterface::class);
        $this->mockConfig->method('getClientId')->willReturn(self::$clientId);
        $this->mockConfig->method('getNprAuthorizationServiceHost')->willReturn('https://authorization.api.npr.org');
        $this->mockConfig->method('getClientUrl')->willReturn('https://one.example.com');
        $this->mockConfig->method('getAuthCodeCallbackUrl')->willReturn('https://one.example.com/oauth2/callback');
        $this->mockConfig->method('getCookieDomain')->willReturn('.example.com');
        $this->mockConfig->method('getEncryptionSalt')->willReturn('asYh&%D9ne!j8HKQ');

        $this->mockClient = new Client(['handler' => HandlerStack::create(new MockHandler())]);

        DI::container()->set(CookieProvider::class, $this->mockCookie);
        DI::container()->set(SecureCookieProvider::class, $this->mockSecureCookie);
        DI::container()->set(EncryptionProvider::class, $this->mockEncryption);
        DI::container()->set(Client::class, $this->mockClient); // just in case
    }

    public function testGeoIpHeadersSetInConstructor()
    {
        $_SERVER['GEOIP_LATITUDE'] = 37.24;
        $_SERVER['GEOIP_LONGITUDE'] = -77.91;

        $controller = new AuthCodeController();

        $this->assertArrayHasKey('X-Latitude', $controller->getHeaders(), 'Latitude header not found');
        $this->assertEquals($controller->getHeaders()['X-Latitude'], 37.24, 'Latitude is not the correct value');
        $this->assertArrayHasKey('X-Longitude', $controller->getHeaders(), 'Longitude header not found');
        $this->assertEquals($controller->getHeaders()['X-Longitude'], -77.91, 'Latitude is not the correct value');
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp   #ConfigProvider must be set. See.*setConfigProvider#
     */
    public function testConfigProviderException()
    {
        $controller = new AuthCodeController();
        $controller->startAuthorizationGrant(['fake_scope']);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp   #StorageProvider must be set. See.*setStorageProvider#
     */
    public function testStorageProviderException()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->startAuthorizationGrant(['fake_scope']);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp   #WARNING: It is strongly discouraged to use CookieProvider as your secure storage provider.#
     */
    public function testSecureStorageProviderException()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->setSecureStorageProvider($this->mockCookie);
        $controller->startAuthorizationGrant(['fake_scope']);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp   #EncryptionProvider must be valid. See.*EncryptionInterface::isValid#
     */
    public function testEncryptionProviderException()
    {
        $mockEncryption = $this->getMock(EncryptionProvider::class);
        $mockEncryption->method('isValid')->willReturn(false);

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->setEncryptionProvider($mockEncryption);
        $controller->startAuthorizationGrant(['fake_scope']);
    }

    public function testGetRedirectUri()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);

        $url = $controller->getRedirectUri();

        $this->assertNotEmpty($url, 'Url should not be empty');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testStartAuthorizationGrantMissingScopes()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->startAuthorizationGrant([]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testStartAuthorizationGrantInvalidScope()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->startAuthorizationGrant([new \stdClass()]);
    }

    public function testStartAuthorizationGrant()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);

        $url = $controller->startAuthorizationGrant(['fake_scope']);

        $this->assertContains('/v2/authorize', $url);
        $this->assertContains('client_id=' . self::$clientId, $url);
        $this->assertContains('redirect_uri=', $url);
        $this->assertContains('state=', $url);
        $this->assertContains('response_type=code', $url);
        $this->assertContains('scope=fake_scope', $url);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testCompleteAuthorizationGrantMissingCode()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->completeAuthorizationGrant(null, null);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testCompleteAuthorizationGrantMissingState()
    {
        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->completeAuthorizationGrant('fake_grant_code', null);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessageRegExp #Invalid state returned from OAuth server.*#
     */
    public function testCompleteAuthorizationGrantStateFailure()
    {
        $this->mockStorage->method('compare')->willReturn(false);

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $controller->completeAuthorizationGrant('fake_grant_code', 'fake_state');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessageRegExp #Invalid state returned from OAuth server, colon separator missing.*#
     */
    public function testCompleteAuthorizationGrantWithSwapAuthCodeNoSeparatorFailure()
    {
        $mock = new MockHandler([
            new Response(500, [], ''),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'http_errors' => false]);

        DI::container()->set(Client::class, $client);

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);

        $controller->completeAuthorizationGrant('fake_grant_code', 'fake_state_without_colon');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessageRegExp #Invalid state returned from OAuth server, server state .*#
     */
    public function testCompleteAuthorizationGrantWithSwapAuthCodeBadState()
    {
        $mock = new MockHandler([
            new Response(500, [], ''),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'http_errors' => false]);

        DI::container()->set(Client::class, $client);

        $mockStorage = $this->getMock(StorageInterface::class);
        $mockStorage->method('compare')->willReturn(false);

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($mockStorage);

        $controller->completeAuthorizationGrant('fake_grant_code', 'fake:state');
    }

    public function testCompleteAuthorizationGrant()
    {
        $mock = new MockHandler([
            new Response(200, [], self::ACCESS_TOKEN_RESPONSE),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        DI::container()->set(Client::class, $client);

        $this->mockCookie->expects($this->once())->method('set'); //access token

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $accessToken = $controller->completeAuthorizationGrant('fake_grant_code', 'fake:state');

        $this->assertInstanceOf(AccessTokenModel::class, $accessToken, 'completeAuthorizationGrant response was not of type AccessTokenModel: ' . print_r($accessToken, 1));
        $this->assertEquals(0, $mock->count(), 'Expected additional HTTP requests to be made');
    }

    public function testCompleteAuthorizationGrantNoRefreshToken()
    {
        $mock = new MockHandler([
            new Response(200, [], self::ACCESS_TOKEN_RESPONSE_2),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        DI::container()->set(Client::class, $client);

        $this->mockCookie->expects($this->once())->method('set'); //access token

        $controller = new AuthCodeController();
        $controller->setConfigProvider($this->mockConfig);
        $controller->setStorageProvider($this->mockStorage);
        $accessToken = $controller->completeAuthorizationGrant('fake_grant_code', 'fake:state');

        $this->assertInstanceOf(AccessTokenModel::class, $accessToken, 'completeAuthorizationGrant response was not of type AccessTokenModel: ' . print_r($accessToken, 1));
        $this->assertEquals(0, $mock->count(), 'Expected additional HTTP requests to be made');
    }
}
