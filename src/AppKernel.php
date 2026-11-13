<?php

namespace App;

use App\DependencyInjection\CommandLocatorCompilerPass;
use App\DependencyInjection\EventCoordinatorCompilerPass;
use Closure;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Kernel;

final class AppKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) use ($loader) {
            $kernelLoader = $loader->getResolver()->resolve(__FILE__);
            assert($kernelLoader instanceof PhpFileLoader);
            $kernelLoader->setCurrentDir(__DIR__);
            /** @noinspection PhpPassByRefInspection */
            $instanceof = &Closure::bind(function &() {
                return $this->instanceof;
            }, $kernelLoader, $kernelLoader)();

            $this->configureContainer(new ContainerConfigurator(
                $container,
                $kernelLoader,
                $instanceof,
                __FILE__,
                __FILE__,
                $this->getEnvironment(),
            ));
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . "/{$this->getAppKey()}/cache";
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . "/{$this->getAppKey()}/logs";
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import('../config/{packages}/*.yaml');
        $container->import('../config/{packages}/' . $this->environment . '/*.yaml');

        if (is_file(\dirname(__DIR__) . '/config/services.yaml')) {
            $container->import('../config/services.yaml');
            $container->import('../config/{services}_' . $this->environment . '.yaml');
        } elseif (is_file($path = \dirname(__DIR__) . '/config/services.php')) {
            (require $path)($container->withPath($path), $this);
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new CommandLocatorCompilerPass());
        $container->addCompilerPass(new EventCoordinatorCompilerPass());
    }

    private function getAppKey(): string
    {
        // The container is only rebuilt in debug mode, so the cache key must change whenever the code changes,
        // otherwise a new version would keep using the container compiled by an older one.
        $versionFile = __DIR__ . '/../bin/appversion';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';
        $fingerprint = $version;
        $files = [__FILE__, __DIR__ . '/../vendor/composer/installed.json', ...(glob(__DIR__ . '/../config/*.yaml') ?: []), ...(glob(__DIR__ . '/../config/packages/*.yaml') ?: [])];
        foreach ($files as $file) {
            $fingerprint .= '|' . (@filemtime($file) ?: 0);
        }
        if ($version === 'dev') {
            // in development, adding/removing a class should also invalidate the container
            $fingerprint .= '|' . (@filemtime(__DIR__ . '/../vendor/composer/autoload_classmap.php') ?: 0);
            foreach (['Command', 'Service', 'Listener', 'Migration', 'Tui'] as $directory) {
                foreach (glob(__DIR__ . "/{$directory}/*.php") ?: [] as $file) {
                    $fingerprint .= '|' . $file . (@filemtime($file) ?: 0);
                }
            }
        }

        return 'gog-downloader-' . substr(hash('xxh3', $fingerprint), 0, 12);
    }
}
