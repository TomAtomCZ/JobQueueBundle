<?php

namespace TomAtom\JobQueueBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;
use function dirname;

class JobQueueBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('database')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('job_table_name')->defaultValue('job_queue')->end()
                        ->scalarNode('job_recurring_table_name')->defaultValue('job_recurring_queue')->end()
                    ->end()
                ->end()
                ->arrayNode('scheduling')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('heartbeat_interval')->defaultValue('1 minute')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Load services
        $container->import($this->getPath() . '/config/services.xml');

        // Get application security roles
        $roleHierarchy = $builder->hasParameter('security.role_hierarchy.roles')
            ? $builder->getParameter('security.role_hierarchy.roles')
            : [];

        // Jobs
        $roleHierarchy[JobQueuePermissions::ROLE_JOBS] = [
            JobQueuePermissions::ROLE_JOB_LIST,
            JobQueuePermissions::ROLE_JOB_READ,
            JobQueuePermissions::ROLE_JOB_CREATE,
            JobQueuePermissions::ROLE_JOB_DELETE,
            JobQueuePermissions::ROLE_JOB_CANCEL
        ];

        // Commands
        $roleHierarchy[JobQueuePermissions::ROLE_COMMANDS] = [
            JobQueuePermissions::ROLE_COMMAND_SCHEDULE
        ];

        // Main role
        $roleHierarchy[JobQueuePermissions::ROLE_ALL] = [
            JobQueuePermissions::ROLE_JOBS,
            JobQueuePermissions::ROLE_COMMANDS
        ];

        // Extend security.role_hierarchy with our roles
        $builder->setParameter('security.role_hierarchy.roles', $roleHierarchy);

        // Set config parameters
        $builder->setParameter('job_queue.database.job_table_name', $config['database']['job_table_name']);
        $builder->setParameter('job_queue.database.job_recurring_table_name', $config['database']['job_recurring_table_name']);
        $builder->setParameter('job_queue.scheduling.heartbeat_interval', $config['scheduling']['heartbeat_interval']);
    }
}
