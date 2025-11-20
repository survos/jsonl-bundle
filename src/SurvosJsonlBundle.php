<?php
declare(strict_types=1);

// File: src/SurvosJsonlBundle.php
// JsonlBundle Enhancement Pipeline v0.4
// This iteration: wire JsonEnhanceCommand and core converters; listeners use #[AsEventListener].

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\Command\JsonlConvertCommand;
use Survos\JsonlBundle\EventListener\JsonlProfileListener;
use Survos\JsonlBundle\Service\JsonlDirectoryConverter;
use Survos\JsonlBundle\Service\JsonlProfileSummaryRenderer;
use Survos\JsonlBundle\Service\JsonToJsonlConverter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosJsonlBundle extends AbstractBundle
{
    protected string $extensionAlias = 'survos_jsonl';

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $console = [JsonlConvertCommand::class];
        $autowirePublic = static function (ContainerBuilder $builder, array $classes): void {
            array_map(
                static fn (string $class) => $builder->autowire($class)
                    ->setPublic(true)
                    ->setAutowired(true)
                    ->setAutoconfigured(true),
                $classes
            );
        };

        // Console commands (Symfony 7.3 invokable style in the classes themselves)
        $autowirePublic($builder, $console);
        foreach ($console as $class) {
            $builder->getDefinition($class)->addTag('console.command');
        }

        // Core services (Jsonl + converters + optional DatasetJsonlWriter)
        $autowirePublic($builder, [
            JsonlDirectoryConverter::class,
            JsonToJsonlConverter::class,
            JsonlProfileListener::class,
            JsonlProfileSummaryRenderer::class,
        ]);

        // Event listeners are registered via #[AsEventListener] in the app and autoconfigure.
    }
}
