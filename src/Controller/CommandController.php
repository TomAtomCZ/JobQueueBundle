<?php

namespace TomAtom\JobQueueBundle\Controller;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

#[IsGranted('ROLE_JQB_JOB_CREATE')]
#[Route(path: '/command')]
class CommandController extends AbstractController
{
    #[Route(path: '/schedule', name: 'command_schedule')]
    public function schedule(KernelInterface $kernel): Response
    {
        // Get all app commands to run except for symfony _complete and completion ones
        $application = new Application($kernel);
        $commands = $application->all();
        unset($commands['_complete'], $commands['completion']);
        ksort($commands);
        return $this->render('@JobQueue/command/schedule.html.twig', [
            'commands' => $commands
        ]);
    }

    #[Route(path: '/schedule-run', name: 'command_schedule_run')]
    public function scheduleRun(Request $request, CommandJobFactory $commandJobFactory, TranslatorInterface $translator): Response
    {
        $commandScheduleRequest = $request->get('command-schedule');
        $commandName = $commandScheduleRequest['command'];
        if (empty($commandScheduleRequest['command'])) {
            // Redirect back to the command schedule if somehow missing command name
            $this->addFlash('danger', $translator->trans('command.schedule.job.error.name'));
            return $this->redirectToRoute('command_schedule');
        }

        // Get the command's params
        $params = strlen($commandScheduleRequest['params']) > 0 ? $commandScheduleRequest['params'] : [];
        if (!empty($params)) {
            $params = trim($params);
            $params = explode(' ', $params);
            foreach ($params as $key => $param) {
                if ($param === '' || $param === null) {
                    // Unset non-valid params
                    unset($params[$key]);
                }
            }
        }

        // Try to create the command job
        try {
            $job = $commandJobFactory->createCommandJob($commandName, $params);
        } catch (OptimisticLockException|ORMException|CommandJobException $e) {
            // Redirect back to the command schedule
            $this->addFlash('danger', $translator->trans('job.creation.error') . ' - ' . $e->getMessage() . '.');
            return $this->redirectToRoute('command_schedule');
        }

        // Redirect to the command job detail
        $this->addFlash('success', $translator->trans('job.creation.success'));
        return $this->redirectToRoute('job_queue_detail', ['id' => $job->getId()]);
    }
}
