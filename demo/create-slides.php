<?php declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DG\Google\Slides\Manager;

$googleClient = googleAuthenticate();

// The Manager works on an existing presentation, so create an empty one first
// (the official service is the only way to create — the Manager wraps editing).
$service = new Google\Service\Slides($googleClient);
$presentation = $service->presentations->create(
	new Google\Service\Slides\Presentation(['title' => 'GoogleServices demo ' . date('Y-m-d H:i')]),
);
$id = $presentation->getPresentationId();
echo "Created presentation: https://docs.google.com/presentation/d/{$id}/edit\n";

$slides = new Manager($googleClient);

// Add a title slide and find its title / subtitle placeholders.
$slideId = $slides->addSlide($id, 'TITLE');
[$titleId, $subtitleId] = findPlaceholders($slides, $id, $slideId, ['CENTERED_TITLE', 'SUBTITLE']);

// Fill the placeholders. setShapeText overwrites the whole shape, insertText appends.
$slides->setShapeText($id, $titleId, 'GoogleServices');
$slides->setShapeText($id, $subtitleId, 'ergonomic wrapper over Google Slides API');

// Inline formatting addressed by literal substring — no UTF-16 index math.
$slides->styleText($id, $titleId, 'Services', bold: true, foregroundColor: '#1a73e8');
$slides->styleText($id, $subtitleId, 'ergonomic', italic: true);

// A second slide with a bullet body.
$bodySlideId = $slides->addSlide($id, 'TITLE_AND_BODY');
[$headingId, $bodyId] = findPlaceholders($slides, $id, $bodySlideId, ['TITLE', 'BODY']);
$slides->setShapeText($id, $headingId, 'What the Manager does');
$slides->setShapeText($id, $bodyId, implode("\n", [
	'addSlide / duplicateSlide / deleteObject',
	'insertText / setShapeText (keeps inline formatting)',
	'styleText (bold, italic, color by substring)',
	'replaceAllText (deck-wide find & replace)',
]));

// Deck-wide find & replace.
$changed = $slides->replaceAllText($id, 'Manager', 'DG\Google\Slides\Manager');
echo "replaceAllText changed {$changed} occurrence(s)\n";

echo "Done.\n";


/**
 * Finds placeholder object IDs on a slide by their placeholder type, in the requested order.
 *
 * @param  list<string>  $types
 * @return list<string>
 */
function findPlaceholders(Manager $slides, string $presentationId, string $slideId, array $types): array
{
	$page = $slides->getPresentation($presentationId, 'slides(objectId,pageElements(objectId,shape.placeholder.type))');
	$byType = [];
	foreach ($page->getSlides() as $slide) {
		if ($slide->getObjectId() !== $slideId) {
			continue;
		}
		foreach ($slide->getPageElements() as $element) {
			$placeholder = $element->getShape()?->getPlaceholder();
			if ($placeholder !== null) {
				$byType[$placeholder->getType()] = $element->getObjectId();
			}
		}
	}

	$result = [];
	foreach ($types as $type) {
		if (!isset($byType[$type])) {
			throw new RuntimeException("Placeholder '$type' not found on slide '$slideId'.");
		}
		$result[] = $byType[$type];
	}
	return $result;
}
