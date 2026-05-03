<?php declare(strict_types=1);

namespace DG\Google;

use Google;


class Authenticator
{
	private readonly Google\Client $client;


	public function __construct(
		/** @var string[] */
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
		$client->setPrompt('select_account consent');
		return $client;
	}


	/**
	 * @throws AuthException  when no usable token is available (missing, malformed,
	 *   refresh failed, refresh token revoked); callers should treat this as a
	 *   recoverable "user must re-authorize" state, not a programming bug.
	 */
	public function authenticate(): Google\Client
	{
		$tokenPath = $this->tokenDir . '/token.json';
		if (file_exists($tokenPath)) {
			$accessToken = json_decode(
				file_get_contents($tokenPath) ?: throw new AuthException("Failed to read token file: $tokenPath"),
				true,
			);
			$this->client->setAccessToken($accessToken);
		}

		if ($this->client->isAccessTokenExpired()) {
			$refreshToken = $this->client->getRefreshToken();
			if ($refreshToken) {
				try {
					$newAccessToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
				} catch (Google\Exception $e) {
					// network/transport failure: keep the stored token so the user can retry later
					throw new AuthException('Token refresh failed (transport): ' . $e->getMessage(), 0, $e);
				}

				if (isset($newAccessToken['error'])) {
					// only invalid_grant means the refresh token is permanently revoked/expired
					if ($newAccessToken['error'] === 'invalid_grant') {
						@unlink($tokenPath);
						throw new AuthException('Refresh token revoked or expired: ' . json_encode($newAccessToken) . '. Re-authorization is required.');
					}
					throw new AuthException('Refresh token rejected: ' . json_encode($newAccessToken));
				}

				if (!isset($newAccessToken['refresh_token'])) {
					$newAccessToken['refresh_token'] = $refreshToken;
				}

				$this->client->setAccessToken($newAccessToken);
				$this->saveToken($newAccessToken);

				return $this->client;
			}

			@unlink($tokenPath);
			throw new AuthException('The access token has expired and no refresh token is available/valid. New authorization is required.');
		}

		if (!$this->client->getAccessToken()) {
			throw new AuthException('No valid access token available. Authorization is required.');
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
				throw new AuthException('Error obtaining access token: ' . json_encode($accessToken));
			}
			$this->client->setAccessToken($accessToken); // Sets the token to internal client
			$this->saveToken($accessToken);
		} catch (Google\Exception $e) {
			throw new AuthException('Error when exchanging code for token: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}


	/** @param array<string, mixed> $accessToken */
	private function saveToken(array $accessToken): void
	{
		$tokenPath = $this->tokenDir . '/token.json';
		// atomic: write to a sibling tmp file and rename, so a crash mid-write cannot corrupt token.json
		$tmpPath = $tokenPath . '.tmp';
		if (file_put_contents($tmpPath, json_encode($accessToken)) === false) {
			throw new AuthException('Failed to save token to file: ' . $tmpPath);
		}
		if (!rename($tmpPath, $tokenPath)) {
			@unlink($tmpPath);
			throw new AuthException('Failed to rename token file: ' . $tmpPath . ' -> ' . $tokenPath);
		}
	}
}
