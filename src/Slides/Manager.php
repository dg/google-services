<?php declare(strict_types=1);

namespace DG\Google\Slides;

use Google;
use Google\Service\Slides;
use Google\Service\Slides\BatchUpdatePresentationRequest;
use Google\Service\Slides\BatchUpdatePresentationResponse;
use Google\Service\Slides\CreateSlideRequest;
use Google\Service\Slides\DeleteObjectRequest;
use Google\Service\Slides\DeleteTextRequest;
use Google\Service\Slides\Dimension;
use Google\Service\Slides\DuplicateObjectRequest;
use Google\Service\Slides\InsertTextRequest;
use Google\Service\Slides\LayoutReference;
use Google\Service\Slides\OptionalColor;
use Google\Service\Slides\Page;
use Google\Service\Slides\PageElement;
use Google\Service\Slides\Presentation;
use Google\Service\Slides\Range;
use Google\Service\Slides\ReplaceAllTextRequest;
use Google\Service\Slides\Request;
use Google\Service\Slides\SubstringMatchCriteria;
use Google\Service\Slides\TextElement;
use Google\Service\Slides\TextStyle;
use Google\Service\Slides\UpdateTextStyleRequest;


class Manager
{
	/** Predefined layouts accepted by addSlide(); maps to LayoutReference.predefinedLayout. */
	public const Layouts = [
		'BLANK',
		'CAPTION_ONLY',
		'TITLE',
		'TITLE_AND_BODY',
		'TITLE_AND_TWO_COLUMNS',
		'TITLE_ONLY',
		'SECTION_HEADER',
		'SECTION_TITLE_AND_DESCRIPTION',
		'ONE_COLUMN_TEXT',
		'MAIN_POINT',
		'BIG_NUMBER',
	];
	public const ContentFields = 'presentationId,title,revisionId,slides(objectId,'
		. 'pageElements(' . self::LeafMask . ',elementGroup(children(' . self::LeafMask . '))),'
		. 'slideProperties(notesPage(notesProperties(speakerNotesObjectId),pageElements(objectId,' . self::ShapeMask . '))))';

	// Field mask covering exactly the text-bearing parts of a presentation (the full DTO graph
	// easily exceeds the MCP token budget). autoText(content) is included so the reconstructed
	// text matches the API's UTF-16 index space; one level of group children and the notes page
	// are covered so getElementText finds elements nested in a group or living on a notes page.
	// notesProperties(speakerNotesObjectId) gives the stable ID of the speaker-notes shape so
	// notes() can surface empty notes too (the old code filtered out empty-text elements, which
	// hid them); a caller can then write into empty notes (getElementText returns '' for them).
	private const TextMask = 'text(textElements(textRun(content),autoText(content)))';
	private const ShapeMask = 'shape(placeholder(type),' . self::TextMask . ')';
	private const LeafMask = 'objectId,' . self::ShapeMask . ',table(tableRows(tableCells(' . self::TextMask . ')))';

	// Richer mask used only by getElementRuns(): like ContentFields but each text element also
	// carries its UTF-16 start/end index and the run's inline style, so a caller can inspect exactly
	// which characters are bold/italic/etc. Kept separate from ContentFields so normal reads stay lean.
	private const StyleFields = 'presentationId,slides(objectId,'
		. 'pageElements(' . self::StyleLeafMask . ',elementGroup(children(' . self::StyleLeafMask . '))),'
		. 'slideProperties(notesPage(notesProperties(speakerNotesObjectId),pageElements(objectId,' . self::StyleShapeMask . '))))';
	private const StyleTextMask = 'text(textElements(startIndex,endIndex,'
		. 'textRun(content,style(bold,italic,underline,fontSize,foregroundColor)),'
		. 'autoText(content,style(bold,italic,underline,fontSize,foregroundColor))))';
	private const StyleShapeMask = 'shape(' . self::StyleTextMask . ')';
	private const StyleLeafMask = 'objectId,' . self::StyleShapeMask;

	private Slides $service;


	public function __construct(Google\Client $client)
	{
		$this->service = new Slides($client);
	}


	/**
	 * Fetches a presentation. Pass $fields (a Slides API field mask, e.g.
	 * "title,slides.objectId") to limit the returned payload; null returns everything.
	 */
	public function getPresentation(string $presentationId, ?string $fields = null): Presentation
	{
		$params = $fields !== null ? ['fields' => $fields] : [];
		return $this->service->presentations->get($presentationId, $params);
	}


	/**
	 * Appends (or inserts at $index) a slide built from a predefined $layout
	 * (one of self::Layouts; null = BLANK). Returns the object ID of the new slide.
	 */
	public function addSlide(string $presentationId, ?string $layout = null, ?int $index = null): string
	{
		$create = new CreateSlideRequest;
		if ($layout !== null) {
			if (!in_array($layout, self::Layouts, true)) {
				throw new \InvalidArgumentException("Unknown layout '$layout'. Use one of: " . implode(', ', self::Layouts) . '.');
			}
			$create->setSlideLayoutReference(new LayoutReference(['predefinedLayout' => $layout]));
		}
		if ($index !== null) {
			$create->setInsertionIndex($index);
		}

		$reply = $this->batchUpdate($presentationId, [new Request(['createSlide' => $create])])->getReplies()[0];
		return $reply->getCreateSlide()->getObjectId();
	}


	/**
	 * Duplicates a slide (or any page element) and returns the object ID of the copy.
	 */
	public function duplicateSlide(string $presentationId, string $objectId): string
	{
		$duplicate = new DuplicateObjectRequest(['objectId' => $objectId]);
		$reply = $this->batchUpdate($presentationId, [new Request(['duplicateObject' => $duplicate])])->getReplies()[0];
		return $reply->getDuplicateObject()->getObjectId();
	}


	/**
	 * Deletes a page (slide) or page element by its object ID.
	 */
	public function deleteObject(string $presentationId, string $objectId): void
	{
		$delete = new DeleteObjectRequest(['objectId' => $objectId]);
		$this->batchUpdate($presentationId, [new Request(['deleteObject' => $delete])]);
	}


	/**
	 * Inserts $text into a shape at $insertionIndex (zero-based, in UTF-16 code units).
	 * The target $objectId must be a text-bearing shape (table cells are not supported here).
	 */
	public function insertText(string $presentationId, string $objectId, string $text, int $insertionIndex = 0): void
	{
		$insert = new InsertTextRequest([
			'objectId' => $objectId,
			'text' => $text,
			'insertionIndex' => $insertionIndex,
		]);
		$this->batchUpdate($presentationId, [new Request(['insertText' => $insert])]);
	}


	/**
	 * Replaces the whole text of the shape $objectId with $text, preserving inline formatting
	 * wherever the text is unchanged. Instead of clearing and re-inserting (which resets every
	 * run to the shape's default style, destroying bold/colors), it diffs the old and new text
	 * and rewrites only the differing middle: the common prefix and suffix keep their styles.
	 * An empty shape is a clean insert; an empty $text clears the shape. The target $objectId must
	 * be a text-bearing shape (not a table); throws if no such shape exists, so a wrong object ID
	 * fails loudly instead of silently doing nothing.
	 *
	 * Inline styling — the optional $styles list makes the result deterministic:
	 *
	 *   - Omit it and the rewritten middle inherits the style of the character before it (the Slides
	 *     API's behavior). That is right for a homogeneous shape (e.g. an all-bold heading) but means
	 *     editing right after a bold word silently bolds the new text.
	 *   - Provide it (a list of ['substring' => '…', 'bold'|'italic'|'underline' => bool,
	 *     'fontSizePt' => float, 'color' => '#RRGGBB', 'occurrence' => int] entries — only 'substring'
	 *     is required) and this method takes control of the accent styles: it first clears bold/italic/
	 *     underline on the freshly rewritten run (killing any inherited accent), then applies exactly
	 *     the styles you list, addressing them by literal substring just like styleText(). Font size
	 *     and color are NOT cleared — the new text keeps inheriting them so it matches its neighbors;
	 *     set them explicitly in an entry to override. Untouched runs keep their formatting. Text edit,
	 *     accent reset and styling all go out in ONE atomic batch, so there is no intermediate state.
	 *
	 * Returns a per-entry report ([['substring' => …, 'occurrences' => int], …], empty when no styles
	 * were given) so the caller can spot a substring that matched nothing (occurrences 0 = a typo).
	 *
	 * @param  list<array{substring?: string, bold?: bool, italic?: bool, underline?: bool, fontSizePt?: float, color?: string, occurrence?: int}>  $styles
	 * @return list<array{substring: string, occurrences: int}>
	 */
	public function setShapeText(string $presentationId, string $objectId, string $text, array $styles = []): array
	{
		$existing = $this->getElementText($presentationId, $objectId);
		if ($existing === null) {
			throw new \InvalidArgumentException("Element '$objectId' was not found or is not a text-bearing shape.");
		}

		[$requests, $report] = self::setShapeRequests($objectId, $existing, $text, $styles);
		if ($requests) {
			$this->batchUpdate($presentationId, $requests);
		}
		return $report;
	}


	/**
	 * The pure, network-free core of setShapeText(): given the shape's current $existing text, the
	 * desired $text and the $styles list, returns [requests, report] — the batch that performs the
	 * edit and styling, plus the per-entry style report. Extracted so the request planning (the diff,
	 * the trailing-newline reconciliation and the accent neutralization) is unit-testable without the
	 * API. See setShapeText() for the full contract.
	 *
	 * @param  list<array{substring?: string, bold?: bool, italic?: bool, underline?: bool, fontSizePt?: float, color?: string, occurrence?: int}>  $styles
	 * @return array{list<Request>, list<array{substring: string, occurrences: int}>}
	 */
	private static function setShapeRequests(string $objectId, string $existing, string $text, array $styles): array
	{
		// The API always keeps exactly one trailing newline at the very end of a text body and refuses
		// to delete it ("end index should not be greater than the existing text length"). Strip that
		// one so we never try to; what remains is the editable body, which may still end in newlines
		// (blank paragraphs the user typed) — those ARE editable.
		$existingBody = self::stripTrailingNewline($existing);
		$newBody = self::stripTrailingNewline($text);

		$requests = [];
		// Start/length (UTF-16) of the run rewritten by the core diff, so styling can later neutralize
		// the accents it inherited. 0 when the text did not change.
		$insertStart = 0;
		$insertLen = 0;

		if ($existingBody !== $newBody) {
			// The diff is computed on the bodies with ALL trailing newlines removed, then the trailing-
			// newline count is reconciled with a separate, tiny edit at the very end. Why: diffEdit()
			// carves one changed region out of the common prefix and suffix; a difference at the very end
			// (e.g. the shape has a trailing blank paragraph the new text drops) makes the common suffix
			// zero, collapsing the "changed region" into the entire tail. The API then deletes and re-
			// inserts that whole tail, and inserted text inherits the style of the character before it —
			// so a single bold word just before the edit point bleeds bold across everything to the end
			// of the shape. Pulling trailing newlines out keeps the real change localized.
			$existingCore = rtrim($existingBody, "\n");
			$newCore = rtrim($newBody, "\n");
			// Newlines are ASCII (one byte, never part of a multibyte sequence), so the byte-length delta
			// is exactly the count of trailing "\n" — and each is one UTF-16 unit.
			$existingTrail = strlen($existingBody) - strlen($existingCore);
			$newTrail = strlen($newBody) - strlen($newCore);

			// (1) Reconcile trailing newlines first. This edit sits at the very end (index >= core
			// length), so running it before the core edit leaves the core's lower indices untouched.
			$coreLen = self::utf16Length($existingCore);
			if ($newTrail < $existingTrail) {
				$requests[] = new Request(['deleteText' => new DeleteTextRequest([
					'objectId' => $objectId,
					'textRange' => new Range(['type' => 'FIXED_RANGE', 'startIndex' => $coreLen + $newTrail, 'endIndex' => $coreLen + $existingTrail]),
				])]);
			} elseif ($newTrail > $existingTrail) {
				$requests[] = new Request(['insertText' => new InsertTextRequest([
					'objectId' => $objectId,
					'text' => str_repeat("\n", $newTrail - $existingTrail),
					'insertionIndex' => $coreLen + $existingTrail,
				])]);
			}

			// (2) Diff the cores, which now share their trailing structure, so the suffix match works and
			// the rewritten middle stays minimal.
			if ($existingCore !== $newCore) {
				[$deleteStart, $deleteEnd, $insert] = self::diffEdit($existingCore, $newCore);
				if ($deleteEnd > $deleteStart) {
					$requests[] = new Request(['deleteText' => new DeleteTextRequest([
						'objectId' => $objectId,
						'textRange' => new Range(['type' => 'FIXED_RANGE', 'startIndex' => $deleteStart, 'endIndex' => $deleteEnd]),
					])]);
				}
				if ($insert !== '') {
					$requests[] = new Request(['insertText' => new InsertTextRequest([
						'objectId' => $objectId,
						'text' => $insert,
						'insertionIndex' => $deleteStart,
					])]);
					// The common prefix is identical in both texts, so $deleteStart is the inserted run's
					// start in the NEW text too; style ranges below are computed against $newBody.
					$insertStart = $deleteStart;
					$insertLen = self::utf16Length($insert);
				}
			}
		}

		// Inline styling is appended to the SAME batch. After the edits above the editable text equals
		// $newBody, so updateTextStyle ranges computed against $newBody address the post-edit state.
		$report = self::appendStyleRequests($requests, $objectId, $newBody, $styles, $insertStart, $insertLen);
		return [$requests, $report];
	}


	/**
	 * Appends the styling part of setShapeText() to $requests (by reference) and returns the per-entry
	 * report. When $styles is non-empty it first clears bold/italic/underline on the rewritten run
	 * [$insertStart, $insertStart + $insertLen] so an inherited accent cannot survive, then applies
	 * each entry by literal substring against $text (the post-edit body). Size and color are left to
	 * inherit unless an entry sets them.
	 *
	 * @param  list<Request>  $requests  appended to in place
	 * @param  list<array{substring?: string, bold?: bool, italic?: bool, underline?: bool, fontSizePt?: float, color?: string, occurrence?: int}>  $styles
	 * @return list<array{substring: string, occurrences: int}>
	 */
	private static function appendStyleRequests(
		array &$requests,
		string $objectId,
		string $text,
		array $styles,
		int $insertStart,
		int $insertLen,
	): array
	{
		if ($styles === []) {
			return [];
		}

		if ($insertLen > 0) {
			$requests[] = new Request(['updateTextStyle' => new UpdateTextStyleRequest([
				'objectId' => $objectId,
				'style' => new TextStyle(['bold' => false, 'italic' => false, 'underline' => false]),
				'fields' => 'bold,italic,underline',
				'textRange' => new Range(['type' => 'FIXED_RANGE', 'startIndex' => $insertStart, 'endIndex' => $insertStart + $insertLen]),
			])]);
		}

		$report = [];
		foreach ($styles as $entry) {
			$substring = (string) ($entry['substring'] ?? '');
			if ($substring === '') {
				throw new \InvalidArgumentException('Each styles entry needs a non-empty "substring".');
			}
			[$style, $fields] = self::buildTextStyle(
				$entry['bold'] ?? null,
				$entry['italic'] ?? null,
				$entry['underline'] ?? null,
				isset($entry['fontSizePt']) ? (float) $entry['fontSizePt'] : null,
				$entry['color'] ?? null,
			);
			if ($fields === []) {
				throw new \InvalidArgumentException("styles entry for '$substring' sets no style; give at least one of bold, italic, underline, fontSizePt, color.");
			}
			$ranges = self::styleRequests($objectId, $text, $substring, $style, implode(',', $fields), isset($entry['occurrence']) ? (int) $entry['occurrence'] : null);
			foreach ($ranges as $request) {
				$requests[] = $request;
			}
			$report[] = ['substring' => $substring, 'occurrences' => count($ranges)];
		}
		return $report;
	}


	/**
	 * Builds the updateTextStyle requests applying $style (with field mask $fields) to occurrences of
	 * $substring in $text. Without $occurrence every occurrence is styled; with it (zero-based) only
	 * that one (none if it is out of range). Ranges are UTF-16 code units computed from $text.
	 *
	 * @return list<Request>
	 */
	private static function styleRequests(
		string $objectId,
		string $text,
		string $substring,
		TextStyle $style,
		string $fields,
		?int $occurrence,
	): array
	{
		$ranges = self::utf16Ranges($text, $substring);
		if ($occurrence !== null) {
			$ranges = isset($ranges[$occurrence]) ? [$ranges[$occurrence]] : [];
		}
		$requests = [];
		foreach ($ranges as [$start, $end]) {
			$requests[] = new Request(['updateTextStyle' => new UpdateTextStyleRequest([
				'objectId' => $objectId,
				'style' => $style,
				'fields' => $fields,
				'textRange' => new Range(['type' => 'FIXED_RANGE', 'startIndex' => $start, 'endIndex' => $end]),
			])]);
		}
		return $requests;
	}


	/**
	 * Applies character styling to occurrences of $substring inside the shape $objectId. The
	 * UTF-16 ranges the Slides API needs are computed from the shape's current text, so callers
	 * never count code units by hand (emoji and other non-BMP characters, which the API counts as
	 * two code units, are handled correctly; auto-text such as slide numbers is accounted for too).
	 * By default every occurrence is styled; pass $occurrence (zero-based) to style a single one.
	 * Each style argument is nullable: null leaves that attribute untouched, a value sets it
	 * (false un-sets bold/italic/underline). $foregroundColor is a hex string ("#RRGGBB"). Returns
	 * the number of occurrences styled (0 if the substring was not found). At least one style
	 * argument must be given. Targets shapes only (not table cells).
	 */
	public function styleText(
		string $presentationId,
		string $objectId,
		string $substring,
		?bool $bold = null,
		?bool $italic = null,
		?bool $underline = null,
		?float $fontSizePt = null,
		?string $foregroundColor = null,
		?int $occurrence = null,
	): int
	{
		if ($substring === '') {
			throw new \InvalidArgumentException('Substring to style must not be empty.');
		}

		[$style, $fields] = self::buildTextStyle($bold, $italic, $underline, $fontSizePt, $foregroundColor);
		if ($fields === []) {
			throw new \InvalidArgumentException('No style given; set at least one of bold, italic, underline, fontSizePt, foregroundColor.');
		}

		$text = $this->getElementText($presentationId, $objectId);
		if ($text === null) {
			throw new \InvalidArgumentException("Element '$objectId' was not found or is not a text-bearing shape.");
		}

		$requests = self::styleRequests($objectId, $text, $substring, $style, implode(',', $fields), $occurrence);
		if ($requests) {
			$this->batchUpdate($presentationId, $requests);
		}
		return count($requests);
	}


	/**
	 * Replaces every occurrence of $find with $replace across the whole presentation.
	 * Returns the number of occurrences changed.
	 */
	public function replaceAllText(string $presentationId, string $find, string $replace, bool $matchCase = true): int
	{
		$request = new ReplaceAllTextRequest([
			'containsText' => new SubstringMatchCriteria(['text' => $find, 'matchCase' => $matchCase]),
			'replaceText' => $replace,
		]);
		$reply = $this->batchUpdate($presentationId, [new Request(['replaceAllText' => $request])])->getReplies()[0];
		return (int) $reply->getReplaceAllText()->getOccurrencesChanged();
	}


	/**
	 * Low-level escape hatch: applies a list of Slides API Request objects atomically.
	 * Prefer the dedicated helpers above; use this for operations they don't cover.
	 *
	 * @param  list<Request>  $requests
	 */
	public function batchUpdate(string $presentationId, array $requests): BatchUpdatePresentationResponse
	{
		$body = new BatchUpdatePresentationRequest(['requests' => $requests]);
		return $this->service->presentations->batchUpdate($presentationId, $body);
	}


	/**
	 * Returns the raw text of the shape with the given object ID (concatenated text runs and
	 * auto-text, including the trailing newline the API keeps): an empty string when the shape
	 * exists but has no text, or null when no such shape exists. Searches every slide, descending
	 * into groups, and each slide's notes page.
	 */
	public function getElementText(string $presentationId, string $objectId): ?string
	{
		$presentation = $this->getPresentation($presentationId, self::ContentFields);
		foreach ($presentation->getSlides() ?? [] as $slide) {
			foreach (self::slideElements($slide) as $element) {
				if ($element->getObjectId() === $objectId) {
					return self::shapeText($element) ?? '';
				}
			}
		}
		return null;
	}


	/**
	 * Returns the individual text runs of the shape with the given object ID, each with its UTF-16
	 * start/end index, content and inline style (bold/italic/underline/fontSizePt/color), so a caller
	 * can see exactly which characters carry which formatting — the plain text reads never expose this.
	 * An empty array when the shape exists but has no text, null when no such shape exists. Searches
	 * every slide (descending into groups) and each slide's notes page.
	 *
	 * @return list<array{start: int, end: int, content: string, bold: bool, italic: bool, underline: bool, fontSizePt: ?float, color: ?string}>|null
	 */
	public function getElementRuns(string $presentationId, string $objectId): ?array
	{
		$presentation = $this->getPresentation($presentationId, self::StyleFields);
		foreach ($presentation->getSlides() ?? [] as $slide) {
			foreach (self::slideElements($slide) as $element) {
				if ($element->getObjectId() !== $objectId) {
					continue;
				}
				$text = $element->getShape()?->getText();
				if ($text === null) {
					return [];
				}
				$runs = [];
				foreach ($text->getTextElements() ?? [] as $te) {
					$run = $te->getTextRun() ?? $te->getAutoText();
					if ($run === null) {
						continue; // paragraph markers carry no content/style
					}
					$style = $run->getStyle();
					$size = $style?->getFontSize()?->getMagnitude();
					$runs[] = [
						'start' => (int) $te->getStartIndex(),
						'end' => (int) $te->getEndIndex(),
						'content' => (string) $run->getContent(),
						'bold' => (bool) $style?->getBold(),
						'italic' => (bool) $style?->getItalic(),
						'underline' => (bool) $style?->getUnderline(),
						'fontSizePt' => $size === null ? null : (float) $size,
						'color' => self::colorHex($style),
					];
				}
				return $runs;
			}
		}
		return null;
	}


	/**
	 * Extracts a "#RRGGBB" hex string from a TextStyle's foreground color, or null when the color is
	 * unset or is a theme color (which has no literal RGB to report).
	 */
	private static function colorHex(?TextStyle $style): ?string
	{
		$rgb = $style?->getForegroundColor()?->getOpaqueColor()?->getRgbColor();
		if ($rgb === null) {
			return null;
		}
		return sprintf(
			'#%02X%02X%02X',
			(int) round(((float) $rgb->getRed()) * 255),
			(int) round(((float) $rgb->getGreen()) * 255),
			(int) round(((float) $rgb->getBlue()) * 255),
		);
	}


	/**
	 * All text-bearing-capable elements of a slide, flattened: the slide's own page elements
	 * (descending into groups) followed by its notes page elements.
	 *
	 * @return list<PageElement>
	 */
	public static function slideElements(Page $slide): array
	{
		$elements = self::walkElements($slide->getPageElements() ?? []);
		$notesPage = $slide->getSlideProperties()?->getNotesPage();
		if ($notesPage !== null) {
			$elements = array_merge($elements, self::walkElements($notesPage->getPageElements() ?? []));
		}
		return $elements;
	}


	/**
	 * Flattens a list of page elements, replacing each group with its (recursively flattened)
	 * children, so callers see only leaf elements.
	 *
	 * @param  PageElement[]  $elements
	 * @return list<PageElement>
	 */
	public static function walkElements(array $elements): array
	{
		$result = [];
		foreach ($elements as $element) {
			$group = $element->getElementGroup();
			if ($group !== null) {
				$result = array_merge($result, self::walkElements($group->getChildren() ?? []));
			} else {
				$result[] = $element;
			}
		}
		return $result;
	}


	/**
	 * Extracts the readable text of a single element with no styling: a shape's text, or the
	 * cells of a table (cells joined by " | ", rows by newline), or "" for anything else.
	 */
	public static function extractText(PageElement $element): string
	{
		// An element is a shape XOR a table (XOR something with no text); collect whichever is
		// present. No early return: the Google stubs type getShape()/getTable() as non-nullable,
		// which would make PHPStan flag the second branch as unreachable dead code.
		$parts = [];

		$shape = self::shapeText($element);
		if ($shape !== null) {
			$parts[] = $shape;
		}

		$table = $element->getTable();
		if ($table !== null) {
			$rows = [];
			foreach ($table->getTableRows() ?? [] as $row) {
				$cells = [];
				foreach ($row->getTableCells() ?? [] as $cell) {
					$cells[] = trim(self::runs($cell->getText()?->getTextElements()));
				}
				$rows[] = implode(' | ', $cells);
			}
			$parts[] = implode("\n", $rows);
		}

		return implode('', $parts);
	}


	private static function shapeText(PageElement $element): ?string
	{
		$text = $element->getShape()?->getText();
		return $text === null ? null : self::runs($text->getTextElements());
	}


	/**
	 * Concatenates the character content of a text body in index order: text runs and auto-text
	 * (slide numbers, dates) both contribute characters; paragraph markers are zero-width.
	 *
	 * @param  ?array<TextElement>  $textElements
	 */
	private static function runs(?array $textElements): string
	{
		$text = '';
		foreach ($textElements ?? [] as $te) {
			$text .= $te->getTextRun()?->getContent()
				?? $te->getAutoText()?->getContent()
				?? '';
		}
		return $text;
	}


	/**
	 * Computes the UTF-16 code-unit ranges of every non-overlapping occurrence of $needle
	 * within $haystack (both UTF-8). Non-BMP characters (most emoji) count as two units, matching
	 * how the Slides API addresses text.
	 *
	 * @return list<array{int, int}>  pairs of [startIndex, endIndex]
	 */
	private static function utf16Ranges(string $haystack, string $needle): array
	{
		$chars = preg_split('//u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$prefix = [0];
		foreach ($chars as $ch) {
			$prefix[] = $prefix[count($prefix) - 1] + (mb_ord($ch, 'UTF-8') > 0xFFFF ? 2 : 1);
		}

		$needleLen = mb_strlen($needle, 'UTF-8');
		$ranges = [];
		$offset = 0;
		while (($pos = mb_strpos($haystack, $needle, $offset, 'UTF-8')) !== false) {
			$ranges[] = [$prefix[$pos], $prefix[$pos + $needleLen]];
			$offset = $pos + $needleLen;
		}
		return $ranges;
	}


	private static function stripTrailingNewline(string $text): string
	{
		return str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;
	}


	/**
	 * Length of a UTF-8 string in UTF-16 code units (how the Slides API addresses text): non-BMP
	 * characters such as most emoji count as two units, everything else as one. Computed by
	 * re-encoding to UTF-16 and halving the byte count — surrogate pairs fall out naturally.
	 */
	private static function utf16Length(string $text): int
	{
		return strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) >> 1;
	}


	/**
	 * Computes the minimal single delete+insert that turns $existing into $new: the common prefix
	 * and suffix are left untouched, only the differing middle is rewritten. Returns the delete
	 * range as UTF-16 code-unit indices (matching the Slides API; non-BMP characters count as two)
	 * and the substring to insert at the delete start. Both inputs must already have their trailing
	 * newline stripped.
	 *
	 * @return array{int, int, string}  [deleteStartIndex, deleteEndIndex, insertText]
	 */
	private static function diffEdit(string $existing, string $new): array
	{
		$e = preg_split('//u', $existing, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$n = preg_split('//u', $new, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$lenE = count($e);
		$lenN = count($n);
		$min = min($lenE, $lenN);

		$prefix = 0;
		while ($prefix < $min && $e[$prefix] === $n[$prefix]) {
			$prefix++;
		}
		$suffix = 0;
		while ($suffix < $min - $prefix && $e[$lenE - 1 - $suffix] === $n[$lenN - 1 - $suffix]) {
			$suffix++;
		}

		$deleteStart = self::utf16Length(implode('', array_slice($e, 0, $prefix)));
		$deleteEnd = $deleteStart + self::utf16Length(implode('', array_slice($e, $prefix, $lenE - $prefix - $suffix)));
		$insert = implode('', array_slice($n, $prefix, $lenN - $prefix - $suffix));
		return [$deleteStart, $deleteEnd, $insert];
	}


	/**
	 * Builds a TextStyle and the matching field mask from the nullable style arguments.
	 *
	 * @return array{TextStyle, list<string>}
	 */
	private static function buildTextStyle(
		?bool $bold,
		?bool $italic,
		?bool $underline,
		?float $fontSizePt,
		?string $foregroundColor,
	): array
	{
		$style = new TextStyle;
		$fields = [];
		if ($bold !== null) {
			$style->setBold($bold);
			$fields[] = 'bold';
		}
		if ($italic !== null) {
			$style->setItalic($italic);
			$fields[] = 'italic';
		}
		if ($underline !== null) {
			$style->setUnderline($underline);
			$fields[] = 'underline';
		}
		if ($fontSizePt !== null) {
			$style->setFontSize(new Dimension(['magnitude' => $fontSizePt, 'unit' => 'PT']));
			$fields[] = 'fontSize';
		}
		if ($foregroundColor !== null) {
			[$r, $g, $b] = self::parseHexColor($foregroundColor);
			$style->setForegroundColor(new OptionalColor([
				'opaqueColor' => ['rgbColor' => ['red' => $r, 'green' => $g, 'blue' => $b]],
			]));
			$fields[] = 'foregroundColor';
		}
		return [$style, $fields];
	}


	/**
	 * @return array{float, float, float}  red, green, blue in 0..1
	 */
	private static function parseHexColor(string $hex): array
	{
		$hex = ltrim($hex, '#');
		if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
			throw new \InvalidArgumentException("Invalid color '$hex'; expected hex like '#RRGGBB'.");
		}
		return [
			hexdec(substr($hex, 0, 2)) / 255,
			hexdec(substr($hex, 2, 2)) / 255,
			hexdec(substr($hex, 4, 2)) / 255,
		];
	}
}
