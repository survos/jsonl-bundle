<?php
declare(strict_types=1);

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\Sqlite\JsonlIndexer;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Survos\JsonlBundle\Service\JsonlStateService;
use Survos\JsonlBundle\Service\JsonlCompressService;
use Survos\JsonlBundle\Service\JsonlCountService;
use Survos\JsonlBundle\Service\JsonlProfiler;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Survos\JsonlBundle\Service\JsonlSidecarNamer;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\JsonlBundle\Service\SidecarService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Survos\JsonlBundle\IO\JsonlWriter;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosJsonlBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->integerNode('compression_level')->min(0)->max(9)->defaultValue(1)->end()
        ->end();
    }

    public function boot(): void
    {
        JsonlWriter::setDefaultCompressionLevel($this->container->getParameter('survos_jsonl.compression_level'));
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        parent::loadExtension($config, $container, $builder);
        $container->parameters()->set('survos_jsonl.compression_level', $config['compression_level']);
        $services = $container->services();

        // Core services
        $services
            ->set(JsonlProfiler::class)
            ->autowire()
            ->autoconfigure();

        $builder
            ->setAlias(JsonlProfilerInterface::class, JsonlProfiler::class)
            ->setPublic(false);

        $services
            ->set(SidecarService::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlStateService::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlCountService::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlIndexer::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(SqlProfiler::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlStateRepository::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlCompressService::class)
            ->autowire()
            ->autoconfigure();

        // NOTE:
        // Do NOT register JsonlReader as a service: it requires a file path constructor arg.
        // Use JsonlReader::open($path) instead.
    }
}
