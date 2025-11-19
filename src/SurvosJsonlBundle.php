<?php
declare(strict_types=1);

namespace Survos\JsonlBundle;

use Survos\JsonlBundle\Command\JsonConvertDirCommand;
use Survos\JsonlBundle\Command\JsonConvertJsonCommand;
use Survos\JsonlBundle\Service\JsonlDirectoryConverter;
use Survos\JsonlBundle\Service\JsonToJsonlConverter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosJsonlBundle extends AbstractBundle
{

    protected string $extensionAlias = 'survos_jsonl';

    /**
     * Keep this bundle self-contained: register the controller + service here.
     *
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // @todo: replace injecting into the controller with a setCommand and use a trait, like loggerAware?
        array_map(fn($class) => $builder->autowire($class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$projectDir', '%kernel.project_dir%'), []);

        // Controllers
        array_map(fn(string $class) => $builder->autowire($class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('controller.service_arguments')
            ->addTag('controller.service_subscriber')
            , []);

        // Commands
        array_map(fn(string $class) => $builder->autowire($class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->addTag('console.command')
            , [JsonConvertDirCommand::class, JsonConvertJsonCommand::class]);

        // Services
        array_map(fn(string $class) => $builder->autowire($class)
            ->setPublic(true)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            , [JsonlDirectoryConverter::class, JsonToJsonlConverter::class]);

    }

}
