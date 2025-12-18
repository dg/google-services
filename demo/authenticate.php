<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// see https://console.cloud.google.com/apis/credentials
// create OAuth 2.0 Client
// add Authorized redirect URI to oauth2callback.php
// save secret token to ./tokens/secret.json

googleAuthenticate();
