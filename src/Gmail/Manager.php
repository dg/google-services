<?php declare(strict_types=1);

namespace DG\Google\Gmail;

use Google\Http\Batch;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Nette\Mail\HtmlComposer;
use Nette\Mail\Message as MailMessage;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;


class Manager
{
	/** Gmail caps the total *encoded* RFC 2822 message at 25 MB. base64 expands by ~33 %, so cap raw input at 18 MB. */
	private const MaxAttachmentBytes = 18 * 1024 * 1024;

	private ?string $myAddress = null;


	public function __construct(
		private readonly Gmail $service,
		private readonly string $userId = 'me',
	) {
	}


	/**
	 * Lightweight search over threads (same query syntax as Gmail web UI).
	 * Returns only metadata of the latest message in each thread; does NOT fetch full bodies.
	 * Pass `nextPageToken` from a previous response back as `$pageToken` to continue.
	 *
	 * @return array{threads: list<array{threadId: string, subject: string, sender: Recipient, date: \DateTimeImmutable, snippet: string, messageCount: int, labelIds: string[]}>, nextPageToken: ?string}
	 */
	public function searchThreads(string $query, int $pageSize = 20, ?string $pageToken = null): array
	{
		$params = ['q' => $query, 'maxResults' => $pageSize];
		if ($pageToken !== null) {
			$params['pageToken'] = $pageToken;
		}
		$response = $this->service->users_threads->listUsersThreads($this->userId, $params);
		$nextPageToken = $response->getNextPageToken() ?: null;

		$stubs = $response->getThreads() ?? [];
		if (!$stubs) {
			return ['threads' => [], 'nextPageToken' => $nextPageToken];
		}

		// listUsersThreads returns only IDs and snippets; batch the metadata fetches.
		$responses = $this->withBatch(function ($batch) use ($stubs): void {
			foreach ($stubs as $stub) {
				$batch->add(
					$this->service->users_threads->get($this->userId, $stub->getId(), [
						'format' => 'metadata',
						'metadataHeaders' => ['From', 'Subject', 'Date'],
					]),
					$stub->getId(),
				);
			}
		});

		$result = [];
		foreach ($stubs as $stub) {
			$raw = $responses['response-' . $stub->getId()] ?? null;
			if ($raw instanceof GoogleException) {
				throw $raw;
			}
			if (!$raw instanceof Gmail\Thread) {
				continue;
			}
			$messages = $raw->getMessages();
			$last = end($messages);
			if ($last === false) {
				continue;
			}
			$headers = self::indexHeaders($last->getPayload()->getHeaders());
			$senders = self::splitAddressList($headers['from'] ?? '');

			$result[] = [
				'threadId' => $raw->getId(),
				'subject' => $headers['subject'] ?? '',
				'sender' => $senders[0] ?? new Recipient(''),
				'date' => (new \DateTimeImmutable)->setTimestamp(intdiv((int) $last->getInternalDate(), 1000)),
				'snippet' => $last->getSnippet(),
				'messageCount' => count($messages),
				'labelIds' => $last->getLabelIds(),
			];
		}
		return ['threads' => $result, 'nextPageToken' => $nextPageToken];
	}


	/**
	 * Lightweight list of all drafts (same query syntax as Gmail web UI; empty query returns all).
	 * Returns metadata only — full bodies are not exposed by this method.
	 *
	 * No pagination: Gmail's drafts.list caps at 500 per call and a typical mailbox
	 * holds at most a handful, so a single call is enough.
	 *
	 * @return list<array{draftId: string, messageId: string, threadId: string, subject: string, to: list<string>, date: \DateTimeImmutable, snippet: string}>
	 */
	public function listDrafts(string $query = ''): array
	{
		$params = ['maxResults' => 500];
		if ($query !== '') {
			$params['q'] = $query;
		}
		$response = $this->service->users_drafts->listUsersDrafts($this->userId, $params);

		$stubs = $response->getDrafts() ?? [];
		if (!$stubs) {
			return [];
		}

		// drafts.list returns only IDs; batch the metadata fetches into one round-trip.
		$responses = $this->withBatch(function ($batch) use ($stubs): void {
			foreach ($stubs as $stub) {
				$batch->add(
					$this->service->users_drafts->get($this->userId, $stub->getId(), [
						'format' => 'metadata',
						'metadataHeaders' => ['To', 'Subject', 'Date'],
					]),
					$stub->getId(),
				);
			}
		});

		$result = [];
		foreach ($stubs as $stub) {
			$raw = $responses['response-' . $stub->getId()] ?? null;
			if ($raw instanceof GoogleException) {
				throw $raw;
			}
			if (!$raw instanceof Gmail\Draft) {
				continue;
			}
			$msg = $raw->getMessage();
			$headers = self::indexHeaders($msg->getPayload()->getHeaders());

			$result[] = [
				'draftId' => $raw->getId(),
				'messageId' => $msg->getId(),
				'threadId' => $msg->getThreadId(),
				'subject' => $headers['subject'] ?? '',
				'to' => self::extractEmails(self::splitAddressList($headers['to'] ?? '')),
				'date' => (new \DateTimeImmutable)->setTimestamp(intdiv((int) $msg->getInternalDate(), 1000)),
				'snippet' => $msg->getSnippet(),
			];
		}
		return $result;
	}


	/**
	 * Fetches a thread at the given format ('full' or 'metadata'). With 'metadata',
	 * Gmail returns headers + label IDs but no body data and no nested parts content,
	 * so Message bodies and attachments come back as null/empty — sufficient for
	 * reply-header derivation.
	 */
	public function fetchThread(string $threadId, string $format = 'full'): Thread
	{
		$raw = $this->service->users_threads->get($this->userId, $threadId, [
			'format' => $format,
		]);

		$messages = [];
		foreach ($raw->getMessages() as $msg) {
			$messages[] = $this->parseMessage($msg);
		}
		return new Thread($raw->getId(), $messages);
	}


	/**
	 * Saves a built MailMessage as a Gmail draft. Pass $threadId to attach the draft
	 * to an existing thread (e.g. a reply); leave null for a standalone draft.
	 *
	 * @return string  draft ID
	 */
	public function saveDraft(MailMessage $mail, ?string $threadId = null): string
	{
		return $this->service->users_drafts->create($this->userId, $this->buildDraft($mail, $threadId))->getId();
	}


	/**
	 * Overwrites an existing draft with a freshly built MailMessage. Pass $threadId to keep the draft
	 * attached to its thread (a reply draft); null for a standalone draft. Returns the (unchanged) draft ID.
	 */
	public function updateDraft(string $draftId, MailMessage $mail, ?string $threadId = null): string
	{
		return $this->service->users_drafts->update($this->userId, $draftId, $this->buildDraft($mail, $threadId))->getId();
	}


	private function buildDraft(MailMessage $mail, ?string $threadId): Gmail\Draft
	{
		$message = new Gmail\Message;
		$message->setRaw(self::base64UrlEncode($mail->generateMessage()));
		if ($threadId !== null) {
			$message->setThreadId($threadId);
		}

		$draft = new Gmail\Draft;
		$draft->setMessage($message);
		return $draft;
	}


	/**
	 * Sends an existing draft.
	 *
	 * @return string  message ID of the sent message
	 */
	public function sendDraft(string $draftId): string
	{
		$draft = new Gmail\Draft;
		$draft->setId($draftId);
		$sent = $this->service->users_drafts->send($this->userId, $draft);
		return $sent->getId();
	}


	/**
	 * Deletes a draft permanently. A 404 (draft already gone) is treated as success
	 * so the operation is idempotent and matches the tool's idempotentHint.
	 */
	public function deleteDraft(string $draftId): void
	{
		try {
			$this->service->users_drafts->delete($this->userId, $draftId);
		} catch (GoogleException $e) {
			if ($e->getCode() !== 404) {
				throw $e;
			}
		}
	}


	/**
	 * Sends a reply into the thread immediately.
	 *
	 * @param list<array{filename: string, path: string}> $attachments
	 * @return string  message ID
	 */
	public function sendReply(string $threadId, string $body, array $attachments = []): string
	{
		$mail = $this->createReplyMessage($threadId, $body, $attachments);

		$message = new Gmail\Message;
		$message->setRaw(self::base64UrlEncode($mail->generateMessage()));
		$message->setThreadId($threadId);

		$sent = $this->service->users_messages->send($this->userId, $message);
		return $sent->getId();
	}


	/**
	 * @return list<array{id: string, name: string, type: string}>
	 */
	public function listLabels(): array
	{
		$response = $this->service->users_labels->listUsersLabels($this->userId);
		$result = [];
		foreach ($response->getLabels() ?? [] as $label) {
			$result[] = [
				'id' => $label->getId(),
				'name' => $label->getName(),
				'type' => $label->getType() ?? 'user',
			];
		}
		return $result;
	}


	/**
	 * @param string[] $add
	 * @param string[] $remove
	 */
	public function modifyThreadLabels(string $threadId, array $add = [], array $remove = []): void
	{
		$request = new Gmail\ModifyThreadRequest;
		if ($add) {
			$request->setAddLabelIds($add);
		}
		if ($remove) {
			$request->setRemoveLabelIds($remove);
		}
		$this->service->users_threads->modify($this->userId, $threadId, $request);
	}


	/**
	 * @param string[] $add
	 * @param string[] $remove
	 */
	public function modifyMessageLabels(string $messageId, array $add = [], array $remove = []): void
	{
		$request = new Gmail\ModifyMessageRequest;
		if ($add) {
			$request->setAddLabelIds($add);
		}
		if ($remove) {
			$request->setRemoveLabelIds($remove);
		}
		$this->service->users_messages->modify($this->userId, $messageId, $request);
	}


	public function getMyAddress(): string
	{
		// Cached for the lifetime of this Manager: address is immutable for a given userId,
		// and reply-building hits this on every call.
		return $this->myAddress ??= $this->service->users->getProfile($this->userId)->getEmailAddress();
	}


	/**
	 * Builds a Nette\Mail\Message reply for the given thread: subject (Re: prefix),
	 * To/Cc, In-Reply-To and References are derived from the last message; the user's
	 * own address is stripped from Cc.
	 *
	 * @param list<array{filename: string, path: string}> $attachments
	 */
	public function createReplyMessage(string $threadId, string $body, array $attachments): MailMessage
	{
		// metadata-only fetch: we need headers (From/To/Cc/Subject/Message-Id/References), not bodies
		$thread = $this->fetchThread($threadId, 'metadata');
		$last = end($thread->messages);
		if ($last === false) {
			throw new Exception("Thread $thread->id has no messages.");
		}

		$myAddress = $this->getMyAddress();
		// Whom to address. Reply-To wins when present. Otherwise reply to the sender — EXCEPT when the
		// last message is our own (we sent it), where Gmail addresses the original recipients instead;
		// replying "to the sender" there would just mail ourselves.
		$lastFromMe = strcasecmp($last->sender->email, $myAddress) === 0;
		$tos = match (true) {
			$last->replyToRecipients !== [] => self::extractEmails($last->replyToRecipients),
			$lastFromMe => self::extractEmails($last->toRecipients),
			default => self::extractEmails([$last->sender]),
		};
		if ($tos === []) {
			// our own last message with no To (Bcc-only) leaves nobody to address the reply to
			throw new Exception("Cannot determine reply recipients: the last message in thread $thread->id has no usable To or Reply-To address.");
		}
		$ccs = array_values(array_diff(
			self::extractEmails(array_merge($last->toRecipients, $last->ccRecipients)),
			$tos,
			[$myAddress],
		));

		$mail = self::createMessage(
			$tos,
			preg_match('/^re:\s/i', $last->subject) ? $last->subject : 'Re: ' . $last->subject,
			$body,
			$ccs,
			[],
			$attachments,
		);
		if ($last->messageIdHeader !== null) {
			$mail->setHeader('In-Reply-To', $last->messageIdHeader);
			$mail->setHeader('References', trim(($last->referencesHeader ?? '') . ' ' . $last->messageIdHeader));
		}
		return $mail;
	}


	/**
	 * Builds a Nette\Mail\Message with recipients, subject, body and attachments.
	 * Nette handles MIME structure, encoding, RFC 2047 header encoding, line folding
	 * and CRLF sanitation.
	 *
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $bcc
	 * @param list<array{filename: string, path: string}> $attachments
	 */
	public static function createMessage(
		array $to,
		string $subject,
		string $body,
		array $cc,
		array $bcc,
		array $attachments,
	): MailMessage
	{
		if (!$to) {
			throw new Exception('At least one "to" recipient is required.');
		}
		$mail = new MailMessage;
		$mail->setBody($body);
		$totalBytes = 0;
		foreach ($attachments as $att) {
			// pre-check via filesize() so a single oversize file can't be slurped fully into RAM before the cap throws
			$size = @filesize($att['path']);
			if ($size === false) {
				throw new Exception("Attachment is not a readable file: {$att['path']}");
			}
			$totalBytes += $size;
			if ($totalBytes > self::MaxAttachmentBytes) {
				throw new Exception(sprintf(
					'Total attachment size exceeds %d MB limit (Gmail caps the encoded message at 25 MB).',
					intdiv(self::MaxAttachmentBytes, 1024 * 1024),
				));
			}
			$mail->addAttachment($att['filename'], FileSystem::read($att['path']));
		}
		foreach ($to as $email) {
			$mail->addTo($email);
		}
		foreach ($cc as $email) {
			$mail->addCc($email);
		}
		foreach ($bcc as $email) {
			$mail->addBcc($email);
		}
		$mail->setSubject($subject);
		return $mail;
	}


	/**
	 * Runs $fn against a Google batch context. setUseBatch flips global state on
	 * the underlying Google\Client; the try/finally keeps the flag balanced even
	 * when $fn or execute() throws. Safe in single-threaded PHP, NOT safe when the
	 * same Client is shared across concurrent requests (swoole/parallel transports).
	 *
	 * @param  \Closure(Batch): void  $fn  receives the batch to populate via $batch->add(request, "id")
	 * @return array<string, mixed>  responses keyed "response-<id>"; entries may be GoogleException on per-item failure
	 */
	private function withBatch(\Closure $fn): array
	{
		$client = $this->service->getClient();
		$client->setUseBatch(true);
		try {
			$batch = $this->service->createBatch();
			$fn($batch);
			return $batch->execute();
		} finally {
			$client->setUseBatch(false);
		}
	}


	private function parseMessage(Gmail\Message $msg): Message
	{
		$payload = $msg->getPayload();
		$headers = self::indexHeaders($payload->getHeaders());
		$senders = self::splitAddressList($headers['from'] ?? '');

		[$plain, $html] = self::extractBodies($payload);

		return new Message(
			id: $msg->getId(),
			date: (new \DateTimeImmutable)->setTimestamp(intdiv((int) $msg->getInternalDate(), 1000)),
			sender: $senders[0] ?? new Recipient(''),
			toRecipients: self::splitAddressList($headers['to'] ?? ''),
			ccRecipients: self::splitAddressList($headers['cc'] ?? ''),
			replyToRecipients: self::splitAddressList($headers['reply-to'] ?? ''),
			subject: $headers['subject'] ?? '',
			snippet: $msg->getSnippet(),
			plaintextBody: $plain,
			htmlBody: $html,
			messageIdHeader: $headers['message-id'] ?? null,
			referencesHeader: $headers['references'] ?? null,
			labelIds: $msg->getLabelIds(),
			attachments: self::extractAttachments($payload),
		);
	}


	/**
	 * Walks the MIME tree and returns metadata for parts that carry an attachmentId.
	 * Inline parts (Content-Disposition: inline, typically embedded images) are skipped.
	 *
	 * @return list<array{attachmentId: string, filename: string, mimeType: string, sizeBytes: int}>
	 */
	private static function extractAttachments(Gmail\MessagePart $part): array
	{
		$result = [];
		foreach (self::walkParts($part) as $p) {
			$body = $p->getBody();
			$attachmentId = $body?->getAttachmentId();
			$filename = $p->getFilename();
			if (!$attachmentId || !$filename) {
				continue;
			}
			$disposition = self::indexHeaders($p->getHeaders())['content-disposition'] ?? '';
			if (stripos(ltrim($disposition), 'inline') === 0) {
				continue;
			}
			$result[] = [
				'attachmentId' => $attachmentId,
				'filename' => $filename,
				'mimeType' => $p->getMimeType(),
				'sizeBytes' => (int) $body->getSize(),
			];
		}
		return $result;
	}


	/**
	 * Downloads attachment bytes by message + attachment ID.
	 */
	public function getAttachment(string $messageId, string $attachmentId): string
	{
		$attachment = $this->service->users_messages_attachments->get($this->userId, $messageId, $attachmentId);
		return self::base64UrlDecode($attachment->getData());
	}


	/**
	 * Converts an HTML body to readable plain text, for messages that ship HTML only. Delegates to
	 * Nette\Mail\HtmlComposer::htmlToText (the same converter nette uses to build the text alternative
	 * of an HTML mail — it drops script/style, renders <a href> as "text <url>", separates table cells
	 * and breaks paragraphs/headings/list items/<br>) after a tiny pre-pass turning <div> boundaries
	 * into <br>, since HtmlComposer does not break on <div> and Gmail wraps every visual line in one.
	 * The point is to spare the model a raw-HTML round-trip (and the tokens of embedded CSS), not to
	 * reproduce the layout. Output is forced to valid UTF-8.
	 */
	public static function htmlToPlainText(string $html): string
	{
		$html = preg_replace('#</?div\b[^>]*>#i', '<br>', $html);
		return Strings::fixEncoding(HtmlComposer::htmlToText($html));
	}


	/**
	 * @param Gmail\MessagePartHeader[] $headers
	 * @return array<string, string>
	 */
	private static function indexHeaders(array $headers): array
	{
		$result = [];
		foreach ($headers as $h) {
			$result[strtolower($h->getName())] = self::decodeHeader($h->getValue());
		}
		return $result;
	}


	/**
	 * Decodes RFC 2047 encoded-words (e.g. `=?UTF-8?B?...?=` in Subject or a display name) to plain
	 * UTF-8. Gmail returns headers verbatim, so without this a non-ASCII subject or sender name would
	 * reach the model still encoded. A header with no encoded-word is returned unchanged (ASCII
	 * Message-Id/References/Date are unaffected). The result is forced to valid UTF-8 so a mislabeled
	 * charset can't produce a string that later breaks json_encode.
	 */
	private static function decodeHeader(string $value): string
	{
		if (!str_contains($value, '=?')) {
			return $value;
		}
		$decoded = @mb_decode_mimeheader($value);
		return Strings::fixEncoding($decoded);
	}


	/**
	 * @return array{?string, ?string}  [plaintext, html]
	 */
	private static function extractBodies(Gmail\MessagePart $part): array
	{
		$plain = $html = null;
		foreach (self::walkParts($part) as $p) {
			$data = $p->getBody()?->getData();
			if (!$data) {
				continue;
			}
			$mimeType = $p->getMimeType();
			if ($mimeType === 'text/plain' && $plain === null) {
				$plain = self::decodeTextPart($p, $data);
			} elseif ($mimeType === 'text/html' && $html === null) {
				$html = self::decodeTextPart($p, $data);
			}
		}
		return [$plain, $html];
	}


	/**
	 * Decodes a text part's base64url data to a UTF-8 string, honoring the part's declared charset.
	 * Older mail is often ISO-8859-2 / windows-1250 (typical for Czech); left as raw bytes those would
	 * either display as mojibake or, worse, make json_encode of the whole MCP response fail. The result
	 * is always valid UTF-8: converted from the declared charset when known, then run through
	 * Strings::fixEncoding as a final guard against mislabeled or corrupt input.
	 */
	private static function decodeTextPart(Gmail\MessagePart $part, string $data): string
	{
		$bytes = self::base64UrlDecode($data);
		$charset = self::partCharset($part);
		if ($charset !== null && !in_array(strtoupper($charset), ['UTF-8', 'US-ASCII', 'ASCII'], true)) {
			try {
				$converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
				if (is_string($converted)) {
					return Strings::fixEncoding($converted);
				}
			} catch (\ValueError) {
				// charset unknown to mbstring (e.g. some windows-* aliases) — try iconv before giving up
				$converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
				if ($converted !== false) {
					return $converted;
				}
			}
		}
		return Strings::fixEncoding($bytes);
	}


	/**
	 * Reads the charset parameter from a part's Content-Type header (e.g. `text/plain; charset="…"`),
	 * or null when none is declared.
	 */
	private static function partCharset(Gmail\MessagePart $part): ?string
	{
		foreach ($part->getHeaders() as $h) {
			if (strcasecmp($h->getName(), 'Content-Type') === 0) {
				return preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $h->getValue(), $m) ? $m[1] : null;
			}
		}
		return null;
	}


	/**
	 * @return \Generator<Gmail\MessagePart>
	 */
	private static function walkParts(Gmail\MessagePart $part): \Generator
	{
		yield $part;
		foreach ($part->getParts() as $child) {
			yield from self::walkParts($child);
		}
	}


	/**
	 * @param Recipient[] $recipients
	 * @return list<string>  bare email addresses, deduplicated
	 */
	private static function extractEmails(array $recipients): array
	{
		$emails = [];
		foreach ($recipients as $r) {
			if ($r->email !== '' && !in_array($r->email, $emails, true)) {
				$emails[] = $r->email;
			}
		}
		return $emails;
	}


	/**
	 * Parses an RFC 2822 address-list header into Recipient objects.
	 * Splits only on commas outside double-quoted display-name segments.
	 *
	 * @return list<Recipient>
	 */
	private static function splitAddressList(string $header): array
	{
		if ($header === '') {
			return [];
		}
		$result = [];
		foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $header) as $addr) {
			$addr = trim($addr);
			if ($addr === '') {
				continue;
			}
			if (preg_match('/^(.*?)<([^>]+)>\s*$/', $addr, $m)) {
				$name = trim($m[1], " \t\"");
				$result[] = new Recipient(trim($m[2]), $name === '' ? null : $name);
			} else {
				$result[] = new Recipient($addr);
			}
		}
		return $result;
	}


	private static function base64UrlEncode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}


	private static function base64UrlDecode(string $data): string
	{
		$padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
		$decoded = base64_decode($padded, true);
		if ($decoded === false) {
			throw new Exception('Invalid base64url data.');
		}
		return $decoded;
	}
}
