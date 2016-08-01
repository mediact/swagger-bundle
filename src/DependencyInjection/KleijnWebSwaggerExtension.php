<?php declare(strict_types = 1);
/*
 * This file is part of the KleijnWeb\SwaggerBundle package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KleijnWeb\SwaggerBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @author John Kleijn <john@kleijnweb.nl>
 */
class KleijnWebSwaggerExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $container->setParameter('swagger.document.base_path', $config['document']['base_path']);
        $container->setParameter('swagger.serializer.namespace', $config['serializer']['namespace']);

        $serializerType = $config['serializer']['type'];
        $container->setAlias('swagger.serializer', "swagger.serializer.$serializerType");

        if ($serializerType !== 'array') {
            $typeResolverRef = new Reference('swagger.serializer.type_resolver');
            $container->getDefinition('swagger.request.processor.content_decoder')->addArgument($typeResolverRef);
            $container->getDefinition('swagger.response.factory')->addArgument($typeResolverRef);
        }

        if (isset($config['document']['cache'])) {
            $resolverDefinition = $container->getDefinition('swagger.document.repository');
            $resolverDefinition->addArgument(new Reference($config['document']['cache']));
        }

        if ($config['errors']['strategy'] == 'fallthrough') {
            // Unregister the exception listener
            $container->removeDefinition('kernel.listener.swagger.exception');
        } else {
            $container->setAlias(
                'swagger.response.error_factory',
                "swagger.response.error_response_factory.{$config['errors']['strategy']}"
            );
        }

        $parameterRefBuilderDefinition = $container->getDefinition('swagger.document.parameter_ref_builder');
        $publicDocsConfig              = $config['document']['public'];
        $arguments                     = [
            $publicDocsConfig['base_url'],
            $publicDocsConfig['scheme'],
            $publicDocsConfig['host']
        ];
        $parameterRefBuilderDefinition->setArguments($arguments);
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return "swagger";
    }
}
