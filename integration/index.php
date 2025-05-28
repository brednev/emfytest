<?php
include_once __DIR__ . '/../src/bootstrap.php';

echo $apiClient->getOAuthClient()->getOAuthButton(
[
'title' => 'Установить интеграцию',
'class_name' => 'className',
'color' => 'default',
'error_callback' => 'handleOauthError',
]
);
