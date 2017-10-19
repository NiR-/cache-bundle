<?php

/*
 * This file is part of php-cache\cache-bundle package.
 *
 * (c) 2015 Aaron Scherer <aequasi@gmail.com>, Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Cache\CacheBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Inject a data collector to all the cache services to be able to get detailed statistics.
 *
 * @author Aaron Scherer <aequasi@gmail.com>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class DataCollectorCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('cache.data_collector')) {
            return;
        }

        $proxyFactory        = $container->get('cache.proxy_factory');
        $collectorDefinition = $container->getDefinition('cache.data_collector');
        $serviceIds          = $container->findTaggedServiceIds('cache.provider');

        foreach (array_keys($serviceIds) as $id) {
            $poolDefinition = $proxyDefinition = $container->getDefinition($id);
            $poolClass = $poolDefinition->getClass();

            if ($poolDefinition->getFactory() !== null) {
                $factory = $container->get($poolDefinition->getFactory()[0]);

                // This is crappy: we should have a proper way to know the class of the CachePool created by each factory
                if (!is_subclass_of($factory, '\Cache\AdapterBundle\Factory\AbstractAdapterFactory')) {
                    throw new \InvalidArgumentException(sprintf('Cache factory "%s" does not inherit from AbstractAdapterFactory (this is not supported for now).'));
                }

                $factoryClass = $container->getDefinition($poolDefinition->getFactory()[0])->getClass();
                $dependenciesReflection = new \ReflectionProperty($factoryClass, 'dependencies');
                $dependenciesReflection->setAccessible(true);
                $dependencies = $dependenciesReflection->getValue($factory);
                $poolClass = $dependencies[0]['requiredClass'];

                // Rename the original service.
                $innerId = $id.'.inner';
                $container->setDefinition($innerId, $poolDefinition);

                // Create a new definition and pass it the renamed service.
                $proxyDefinition = new Definition();
                $proxyDefinition->setFactory([new Reference('cache.decorating_factory'), 'create']);
                $proxyDefinition->setArguments([new Reference($innerId)]);
                $container->setDefinition($id, $proxyDefinition);
            }

            $proxyClass = $proxyFactory->getProxyClass($poolClass);
            $proxyFile = $proxyFactory->createProxy($poolClass);

            $proxyDefinition->setClass($proxyClass);
            $proxyDefinition->setFile($proxyFile);
            $proxyDefinition->addMethodCall('__setName', [$id]);

            // Tell the collector to add the proxy instance.
            $collectorDefinition->addMethodCall('addInstance', [$id, new Reference($id)]);
        }
    }
}
