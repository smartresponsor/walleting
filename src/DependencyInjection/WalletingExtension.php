<?php

declare(strict_types=1);

namespace App\Walleting\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads Walleting services into a host Symfony application.
 */
final class WalletingExtension extends Extension
{
    public function getAlias(): string
    {
        return 'walleting';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configDirectory = __DIR__.'/../../config';
        $loader = new YamlFileLoader($container, new FileLocator($configDirectory));
        $loader->load('services.yaml');
    }
}
