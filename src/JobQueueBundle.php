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
                ->arrayNode('processing')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('poll_interval_ms')->defaultValue(1000)->min(0)
                            ->info('How often the job output is written and the cancellation checked while the command runs.')->end()
                        ->integerNode('output_max_bytes')->defaultValue(4194304)->min(0)
                            ->info('Cap of the stored job output in bytes (keep it well below MySQL max_allowed_packet), 0 = unlimited.')->end()
                        ->integerNode('db_failure_tolerance')->defaultValue(30)->min(1)
                            ->info('Consecutive failed database polls (about one per poll interval) after which a running job is given up.')->end()
                        ->booleanNode('rerun_on_redelivery')->defaultFalse()
                            ->info('Run the command again when the message of a job which is already RUNNING is redelivered (e.g. after a worker crash). Default: mark the job as failed.')->end()
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
        $builder->setParameter('job_queue.processing.poll_interval_ms', $config['processing']['poll_interval_ms']);
        $builder->setParameter('job_queue.processing.output_max_bytes', $config['processing']['output_max_bytes']);
        $builder->setParameter('job_queue.processing.db_failure_tolerance', $config['processing']['db_failure_tolerance']);
        $builder->setParameter('job_queue.processing.rerun_on_redelivery', $config['processing']['rerun_on_redelivery']);
    }
}
