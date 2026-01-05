<?php
declare(strict_types=1);

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\Command\JsonlCountCommand;
use Survos\JsonlBundle\Command\JsonlInfoCommand;
use Survos\JsonlBundle\Service\JsonlCountService;
use Survos\JsonlBundle\Service\JsonlProfiler;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Survos\JsonlBundle\Service\SidecarService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosJsonlBundle extends AbstractBundle
{
    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
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
            ->set(JsonlCountService::class)
            ->autowire()
            ->autoconfigure();

        // Console commands
        $services
            ->set(JsonlCountCommand::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set(JsonlInfoCommand::class)
            ->autowire()
            ->autoconfigure();

        // NOTE:
        // Do NOT register JsonlReader as a service: it requires a file path constructor arg.
        // Use JsonlReader::open($path) instead.
    }
}
