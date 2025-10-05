<?php

namespace Survos\JsonlBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(
    )
    {
    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('jsonl_url', fn (string $s) => '@todo: filter '.$s),
        ];
    }

    public function getFunctions(): array
    {
        return [
            //            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }
}
