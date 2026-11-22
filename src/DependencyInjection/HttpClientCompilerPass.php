<?php

namespace App\DependencyInjection;

use App\Service\Factory\HttpClientFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Creates the http client through HttpClientFactory, so curl is used in the Windows executable as well.
 */
final readonly class HttpClientCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('http_client.transport')) {
            return;
        }
        $container->getDefinition('http_client.transport')->setFactory([HttpClientFactory::class, 'create']);
    }
}
