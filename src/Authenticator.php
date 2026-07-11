<?php declare(strict_types=1);

namespace DG\Google;

use Google;


class Authenticator
{
	private ?Google\Client $client = null;


	public function __construct(
		/** @var string[] */
		private array $scopes,
		private string $tokenDir,
	) {
	}


	/**
	 * The Google\Client is built lazily on first use. setAuthConfig() throws when secret.json is
	 * missing/unreadable; building eagerly in the constructor would crash the MCP server before the
	 * handshake (the host sees only "server died"). Deferring it lets the failure surface as an
	 * AuthException at the first tool call, which McpTools converts to a self-correctable tool error.
	 */
	private function getClient(): Google\Client
	{
		if ($this->client !== null) {
			return $this->client;
		}
		try {
			return $this->client = $this->createClient();
		} catch (Google\Exception | \LogicException $e) {
			// setAuthConfig() throws InvalidArgumentException when secret.json is missing and a plain
			// LogicException when it is malformed JSON. Convert both to AuthException so getManager()
			// renders a re-authorize hint instead of an opaque tool error.
			throw new AuthException(
				"Failed to initialize the Google client (is {$this->tokenDir}/secret.json present and valid?): " . $e->getMessage(),
				0,
				$e,
			);
		}
	}


	private function createClient(): Google\Client
	{
		$client = new Google\Client;
		$client->setAuthConfig($this->tokenDir . '/secret.json');
		$client->setScopes($this->scopes);
		$client->setAccessType('offline'); // Required for refresh token
		$client->setPrompt('select_account consent');

		// In a long-running process the library refreshes the access token transparently on the
		// request that finds it expired. But Google's DEFAULT token_callback replaces the in-memory
		// token with just {access_token, expires_in, created} — it drops the refresh_token and never
		// writes anything to disk. The NEXT expiry then sees no refresh_token in memory, so authorize()
		// attaches the stale access token as-is and every subsequent request 401s "Invalid Credentials"
		// until the process restarts. Our callback keeps the refresh_token and scope and persists the
		// refreshed token, so both survive across refreshes and match the disk snapshot.
		$client->setTokenCallback(function (string $cacheKey, string $accessToken) use ($client): void {
			$current = $client->getAccessToken();
			$token = [
				'access_token' => $accessToken,
				'expires_in' => 3600, // Google default; the callback only receives the token string
				'created' => time(),
			];
			foreach (['refresh_token', 'scope'] as $carry) {
				if (isset($current[$carry])) {
					$token[$carry] = $current[$carry];
				}
			}
			$client->setAccessToken($token);
			$this->saveToken($token);
		});

		return $client;
	}


	/**
	 * @throws AuthException  when no usable token is available (missing, malformed,
	 *   refresh failed, refresh token revoked); callers should treat this as a
	 *   recoverable "user must re-authorize" state, not a programming bug.
	 */
	public function authenticate(): Google\Client
	{
		$client = $this->getClient();
		$tokenPath = $this->tokenDir . '/token.json';
		if (file_exists($tokenPath)) {
			$accessToken = json_decode(
				file_get_contents($tokenPath) ?: throw new AuthException("Failed to read token file: $tokenPath"),
				true,
			);
			if (!is_array($accessToken)) {
				// Malformed/truncated token.json (e.g. a crash outside the atomic write path, or a hand-edit).
				// Treat as a re-auth state, not a TypeError from setAccessToken(null).
				throw new AuthException("Malformed token file (not a JSON object): $tokenPath. Re-authorization is required.");
			}
			$client->setAccessToken($accessToken);
		}

		if ($client->isAccessTokenExpired()) {
			$refreshToken = $client->getRefreshToken();
			if ($refreshToken) {
				try {
					$newAccessToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
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

				$client->setAccessToken($newAccessToken);
				$this->saveToken($newAccessToken);

				return $client;
			}

			@unlink($tokenPath);
			throw new AuthException('The access token has expired and no refresh token is available/valid. New authorization is required.');
		}

		if (!$client->getAccessToken()) {
			throw new AuthException('No valid access token available. Authorization is required.');
		}

		return $client;
	}


	public function getAuthUrl(): string
	{
		return $this->getClient()->createAuthUrl();
	}


	public function exchangeCodeForToken(string $authCode): void
	{
		try {
			$client = $this->getClient();
			$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
			if (array_key_exists('error', $accessToken)) {
				throw new AuthException('Error obtaining access token: ' . json_encode($accessToken));
			}
			$client->setAccessToken($accessToken); // Sets the token to internal client
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
