<?php

namespace TomAtom\JobQueueBundle;

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

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Load services
        $container->import($this->getPath() . '/config/services.yaml');

        // Extend security.role_hierarchy with our roles
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

        $builder->setParameter('security.role_hierarchy.roles', $roleHierarchy);
    }
}
