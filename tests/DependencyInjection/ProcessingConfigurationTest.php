<?php

namespace TomAtom\JobQueueBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use TomAtom\JobQueueBundle\JobQueueBundle;
use TomAtom\JobQueueBundle\MessageHandler\JobMessageHandler;

class ProcessingConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame(1000, $container->getParameter('job_queue.processing.poll_interval_ms'));
        self::assertSame(4194304, $container->getParameter('job_queue.processing.output_max_bytes'));
        self::assertSame(30, $container->getParameter('job_queue.processing.db_failure_tolerance'));
        self::assertFalse($container->getParameter('job_queue.processing.rerun_on_redelivery'));
        self::assertSame('job_queue', $container->getParameter('job_queue.database.job_table_name'));
    }

    public function testCustomValues(): void
    {
        $container = $this->load([
            'processing' => [
                'poll_interval_ms' => 250,
                'output_max_bytes' => 0,
                'db_failure_tolerance' => 5,
                'rerun_on_redelivery' => true,
            ],
        ]);

        self::assertSame(250, $container->getParameter('job_queue.processing.poll_interval_ms'));
        self::assertSame(0, $container->getParameter('job_queue.processing.output_max_bytes'));
        self::assertSame(5, $container->getParameter('job_queue.processing.db_failure_tolerance'));
        self::assertTrue($container->getParameter('job_queue.processing.rerun_on_redelivery'));
    }

    public function testHandlerArgumentsAreBound(): void
    {
        $container = $this->load([]);
        $definition = $container->getDefinition(JobMessageHandler::class);
        $bindings = $definition->getBindings();

        foreach (['$pollIntervalMs', '$outputMaxBytes', '$dbFailureTolerance', '$rerunOnRedelivery'] as $argument) {
            self::assertArrayHasKey($argument, $bindings, $argument . ' is not bound');
        }
    }

    public function testNegativeValuesAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['processing' => ['poll_interval_ms' => -1]]);
    }

    private function load(array $config): ContainerBuilder
    {
        if (!class_exists(XmlFileLoader::class)) {
            self::markTestSkipped('symfony/dependency-injection >= 8 cannot load the bundle\'s config/services.xml');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        $extension = (new JobQueueBundle())->getContainerExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
