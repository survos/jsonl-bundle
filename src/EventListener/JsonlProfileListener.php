<?php declare(strict_types=1);

// File: src/EventListener/JsonlProfileListener.php
// jsonl-bundle v0.12
// Listens to JsonlConvertStartedEvent, JsonlRecordEvent, JsonlConvertFinishedEvent
// and writes a profiling artifact (JSON) beside the JSONL output, using JsonlProfile + FieldStats.

namespace Survos\JsonlBundle\EventListener;

use Survos\JsonlBundle\Event\JsonlConvertFinishedEvent;
use Survos\JsonlBundle\Event\JsonlConvertStartedEvent;
use Survos\JsonlBundle\Event\JsonlRecordEvent;
use Survos\JsonlBundle\Model\JsonlProfile;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class JsonlProfileListener
{
    private ?JsonlProfile $profile = null;

    #[AsEventListener(event: JsonlConvertStartedEvent::class)]
    public function onConvertStarted(JsonlConvertStartedEvent $event): void
    {
        $this->profile = new JsonlProfile(
            input: $event->input,
            output: $event->output,
            recordCount: 0,
            tags: $event->tags,
        );
    }

    #[AsEventListener(event: JsonlRecordEvent::class)]
    public function onRecord(JsonlRecordEvent $event): void
    {
        if (!$this->profile) {
            return;
        }

        // For now, rely on JsonlConvertFinishedEvent's recordCount; we could also increment here.
        foreach ($event->record as $field => $value) {
            $stats = $this->profile->ensureField((string)$field);
            $stats->push($value);
        }
    }

    #[AsEventListener(event: JsonlConvertFinishedEvent::class)]
    public function onConvertFinished(JsonlConvertFinishedEvent $event): void
    {
        if (!$this->profile || $this->profile->output !== $event->output) {
            return;
        }

        $this->profile->recordCount = $event->recordCount;

        $artifactArray = $this->profile->toArray();
        $artifactPath = $this->profile->output . '.profile.json';

        $dir = \dirname($artifactPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s" for profile artifact.', $dir));
            }
        }

        $json = json_encode($artifactArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = file_put_contents($artifactPath, $json);
        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to write profile artifact to "%s".', $artifactPath));
        }

        // Reset profile to avoid accidental reuse across runs
        $this->profile = null;
    }
}
