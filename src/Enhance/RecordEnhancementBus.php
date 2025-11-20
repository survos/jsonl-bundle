<?php declare(strict_types=1);

namespace Survos\JsonlBundle\Enhance;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Synchronous enhancement bus:
 *
 *  - Wraps each record in a context
 *  - Invokes all enhancement listeners
 *  - Returns the (possibly modified) record
 *
 * Listeners are injected via #[AutowireIterator] using the tag
 * "survos_jsonl.record_enhancement_listener" set up in SurvosJsonlBundle.
 */
final class RecordEnhancementBus
{
    /**
     * @param iterable<RecordEnhancementListenerInterface> $listeners
     */
    public function __construct(
        #[AutowireIterator(tag: 'survos_jsonl.record_enhancement_listener')]
        public readonly iterable $listeners,
    ) {}

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function enhance(
        string $dataset,
        array $record,
        ?string $originFile = null,
        ?string $originFormat = null,
    ): array {
        $context = new RecordEnhancementContext(
            dataset: $dataset,
            record: $record,
            originFile: $originFile,
            originFormat: $originFormat,
        );

        foreach ($this->listeners as $listener) {
            $listener($context);
        }

        return $context->record;
    }
}
