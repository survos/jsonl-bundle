<?php

declare(strict_types=1);

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\Command\JsonlCountCommand;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlReaderInterface;
use Survos\JsonlBundle\Service\JsonlCountService;
use Survos\JsonlBundle\Service\JsonlProfiler;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Survos JSONL Bundle
 *
 * Provides services for reading, writing, and converting JSONL (JSON Lines) files.
 * Includes profiling capabilities and event-driven record processing.
 */
final class SurvosJsonlBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // No configuration required at this time
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        $services = $container->services();

        $services->set(JsonlCountCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('console.command');

        foreach([JsonlProfiler::class, JsonlCountService::class] as $service) {
            $services
                ->set($service)
                ->autowire()
                ->autoconfigure();

        }


        $builder
            ->setAlias(JsonlProfilerInterface::class, JsonlProfiler::class)
            ->setPublic(false);

        // Register JsonlReader service with interface alias
        $services
            ->set(JsonlReader::class)
            ->autowire()
            ->autoconfigure();

        $builder
            ->setAlias(JsonlReaderInterface::class, JsonlReader::class)
            ->setPublic(false);
    }
}
