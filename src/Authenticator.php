<?php

declare(strict_types=1);

namespace DG\Google;

use Google;


class Authenticator
{
	private readonly Google\Client $client;


	public function __construct(
		private array $scopes,
		private string $tokenDir,
	) {
		$this->client = $this->createClient();
	}


	private function createClient(): Google\Client
	{
		$client = new Google\Client;
		$client->setAuthConfig($this->tokenDir . '/secret.json');
		$client->setScopes($this->scopes);
		$client->setAccessType('offline'); // Required for refresh token
		return $client;
	}


	/**
	 * @throws \RuntimeException
	 */
	public function authenticate(): Google\Client
	{
		$tokenPath = $this->tokenDir . '/token.json';
		if (file_exists($tokenPath)) {
			$accessToken = json_decode(file_get_contents($tokenPath), true);
			$this->client->setAccessToken($accessToken);
		}

		if ($this->client->isAccessTokenExpired()) {
			$refreshToken = $this->client->getRefreshToken();
			if ($refreshToken) {
				try {
					$this->client->fetchAccessTokenWithRefreshToken($refreshToken);
					$this->saveToken($this->client->getAccessToken());
					return $this->client;
				} catch (Google\Exception) {
				}
			}
			@unlink($tokenPath);
			throw new \RuntimeException('The access token has expired and no refresh token is available. New authorization is required.');
		}

		if (!$this->client->getAccessToken()) {
			throw new \RuntimeException('No valid access token available. Authorization is required.');
		}

		return $this->client;
	}


	public function getAuthUrl(): string
	{
		return $this->client->createAuthUrl();
	}


	public function exchangeCodeForToken(string $authCode): void
	{
		try {
			$accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
			if (array_key_exists('error', $accessToken)) {
				throw new \RuntimeException('Error obtaining access token: ' . implode(', ', $accessToken));
			}
			$this->client->setAccessToken($accessToken); // Sets the token to internal client
			$this->saveToken($accessToken);
		} catch (Google\Exception $e) {
			throw new \RuntimeException('Error when exchanging code for token: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}


	private function saveToken(array $accessToken): void
	{
		$tokenPath = $this->tokenDir . '/token.json';
		if (!file_put_contents($tokenPath, json_encode($accessToken))) {
			throw new \RuntimeException('Failed to save token to file: ' . $tokenPath);
		}
	}
}
