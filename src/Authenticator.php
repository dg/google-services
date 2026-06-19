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


	/**
	 * Non-sensitive snapshot of the stored token's lifecycle metadata, for diagnosing auth
	 * failures in a long-running server. Reads token.json directly (ground truth on disk) and
	 * never returns the access or refresh token values themselves. `locallyExpired` mirrors
	 * Google\Client::isAccessTokenExpired() math (created + expires_in - 30 < now), so a log
	 * entry reveals whether a 401 hit while the token still looked valid locally (points at
	 * revocation / clock skew / a token the lib refused to refresh) or only after it expired
	 * (points at a refresh that never ran). `memoryMatchesDisk` compares the live client's
	 * in-memory token against disk: `false` means the client refreshed/replaced its token without
	 * persisting it, so a request can be rejected with a value this disk snapshot can't see.
	 *
	 * @return array<string, mixed>
	 */
	public function getTokenDiagnostics(): array
	{
		$tokenPath = $this->tokenDir . '/token.json';
		$now = time();
		$diag = [
			'now' => date(\DATE_ATOM, $now),
			'scopes' => $this->scopes,
			'tokenFileExists' => file_exists($tokenPath),
		];
		if (!$diag['tokenFileExists']) {
			return $diag;
		}

		$raw = @file_get_contents($tokenPath);
		$token = $raw === false ? null : json_decode($raw, true);
		if (!is_array($token)) {
			$diag['tokenFileReadable'] = false;
			return $diag;
		}

		$created = isset($token['created']) ? (int) $token['created'] : null;
		$expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : null;
		$diag['tokenFileReadable'] = true;
		$diag['hasAccessToken'] = isset($token['access_token']);
		$diag['hasRefreshToken'] = isset($token['refresh_token']);
		$diag['created'] = $created;
		$diag['expiresIn'] = $expiresIn;
		$diag['createdAt'] = $created !== null ? date(\DATE_ATOM, $created) : null;
		$diag['expiresAt'] = $created !== null && $expiresIn !== null ? date(\DATE_ATOM, $created + $expiresIn) : null;
		$diag['ageSeconds'] = $created !== null ? $now - $created : null;
		$diag['locallyExpired'] = $created === null || $expiresIn === null
			? true
			: ($created + $expiresIn - 30) < $now;

		// Compare the live client's in-memory token with disk. A mismatch means the lib refreshed
		// the access token in this process but never wrote it back, so the rejected request used a
		// token that none of the disk fields above describe.
		$memory = $this->client->getAccessToken();
		if (is_array($memory) && $memory !== []) {
			$memCreated = isset($memory['created']) ? (int) $memory['created'] : null;
			$diag['memoryCreated'] = $memCreated;
			$diag['memoryCreatedAt'] = $memCreated !== null ? date(\DATE_ATOM, $memCreated) : null;
			$diag['memoryMatchesDisk'] = isset($memory['access_token'], $token['access_token'])
				&& hash_equals((string) $token['access_token'], (string) $memory['access_token']);
		}

		return $diag;
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
