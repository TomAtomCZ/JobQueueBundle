<?php

namespace TomAtom\JobQueueBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function dirname;

class JobQueueBundle extends AbstractBundle
{
    const ROLE_JQB_JOBS = 'ROLE_JQB_JOBS'; // Main role with all permissions
    const ROLE_JQB_JOB_LIST = 'ROLE_JQB_JOB_LIST';
    const ROLE_JQB_JOB_READ = 'ROLE_JQB_JOB_READ';
    const ROLE_JQB_JOB_CREATE = 'ROLE_JQB_JOB_CREATE';
    const ROLE_JQB_JOB_DELETE = 'ROLE_JQB_JOB_DELETE';

    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Load services
        $container->import($this->getPath() . '/config/services.yaml');

        // Extend security.role_hierarchy with our roles for jobs
        $roleHierarchy = $builder->hasParameter('security.role_hierarchy.roles')
            ? $builder->getParameter('security.role_hierarchy.roles')
            : [];

        $roleHierarchy[self::ROLE_JQB_JOBS] = [
            self::ROLE_JQB_JOB_LIST,
            self::ROLE_JQB_JOB_READ,
            self::ROLE_JQB_JOB_CREATE,
            self::ROLE_JQB_JOB_DELETE
        ];

        $builder->setParameter('security.role_hierarchy.roles', $roleHierarchy);
    }
}