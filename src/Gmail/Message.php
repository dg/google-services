<?php declare(strict_types=1);

namespace DG\Google\Gmail;


class Message
{
	public function __construct(
		public string $id,
		public \DateTimeImmutable $date,
		public Recipient $sender,
		/** @var Recipient[] */
		public array $toRecipients,
		/** @var Recipient[] */
		public array $ccRecipients,
		/** @var Recipient[] */
		public array $replyToRecipients,
		public string $subject,
		public string $snippet,
		public ?string $plaintextBody,
		public ?string $htmlBody,
		public ?string $messageIdHeader,
		public ?string $referencesHeader,
		/** @var string[] */
		public array $labelIds = [],
		/** @var list<array{attachmentId: string, filename: string, mimeType: string, sizeBytes: int}> */
		public array $attachments = [],
	) {
	}
}
