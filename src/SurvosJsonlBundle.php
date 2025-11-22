<?php
declare(strict_types=1);

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlReaderInterface;
use Survos\JsonlBundle\Service\JsonlProfiler;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosJsonlBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // No config (yet)
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        $services = $container->services();

        // Profiler concrete service
        $services
            ->set(JsonlProfiler::class)
            ->autowire()
            ->autoconfigure();

        // Alias interface -> implementation
        $builder
            ->setAlias(JsonlProfilerInterface::class, JsonlProfiler::class)
            ->setPublic(false);

        // JsonlReader: we allow autowire by concrete class
        $services
            ->set(JsonlReader::class)
            ->autowire()
            ->autoconfigure();
// Optional: alias interface to concrete
        $builder
            ->setAlias(JsonlReaderInterface::class, JsonlReader::class)
            ->setPublic(false);
    }
}
