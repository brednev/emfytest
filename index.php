<?php

use AmoCRM\OAuth2\Client\Provider\AmoCRM;

include_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/src/bootstrap.php';

session_start();

$provider = new AmoCRM([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri' => $redirectUri,
]);

if (isset($_GET['referer'])) {
    $provider->setBaseDomain($_GET['referer']);
}

try {
    /** @var \League\OAuth2\Client\Token\AccessToken $access_token */
    $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\AuthorizationCode(), [
        'code' => $_GET['code'],
    ]);

    if (!$accessToken->hasExpired()) {
        saveToken([
            'accessToken' => $accessToken->getToken(),
            'refreshToken' => $accessToken->getRefreshToken(),
            'expires' => $accessToken->getExpires(),
            'baseDomain' => $provider->getBaseDomain(),
        ]);
    }
} catch (Exception $e) {
    die((string)$e);
}

/** @var \AmoCRM\OAuth2\Client\Provider\AmoCRMResourceOwner $ownerDetails */
$ownerDetails = $provider->getResourceOwner($accessToken);

$accessToken = getToken();

$provider->setBaseDomain($accessToken->getValues()['baseDomain']);

/**
 * Проверяем активен ли токен и делаем запрос или обновляем токен
 */
if ($accessToken->hasExpired()) {
    /**
     * Получаем токен по рефрешу
     */
    try {
        $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\RefreshToken(), [
            'refresh_token' => $accessToken->getRefreshToken(),
        ]);

        saveToken([
            'accessToken' => $accessToken->getToken(),
            'refreshToken' => $accessToken->getRefreshToken(),
            'expires' => $accessToken->getExpires(),
            'baseDomain' => $provider->getBaseDomain(),
        ]);

    } catch (Exception $e) {
        die((string)$e);
    }
}

$token = $accessToken->getToken();

try {
    /**
     * Делаем запрос к АПИ
     */
    $data = $provider->getHttpClient()
        ->request('GET', $provider->urlAccount() . 'api/v2/account', [
            'headers' => $provider->getHeaders($accessToken)
        ]);

    $parsedBody = json_decode($data->getBody()->getContents(), true);
    printf('ID аккаунта - %s, название - %s', $parsedBody['id'], $parsedBody['name']);
} catch (GuzzleHttp\Exception\GuzzleException $e) {
    var_dump((string)$e);
}
