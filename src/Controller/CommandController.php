<?php

namespace TomAtom\JobQueueBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
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
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

#[IsGranted(JobQueuePermissions::ROLE_COMMAND_SCHEDULE)]
#[Route(path: '/command')]
class CommandController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route(path: '/schedule', name: 'command_schedule')]
    #[Route(path: '/schedule/{id<\d+>}', name: 'command_schedule_edit')]
    public function schedule(KernelInterface $kernel, Request $request, ?JobRecurring $recurringJob = null): Response
    {
        return $this->render('@JobQueue/command/schedule.html.twig', [
            'commands' => $this->getApplicationCommands($kernel),
            'listId' => $request->query->get('listId'),
            'listName' => $request->query->get('listName'),
            'recurringJob' => $recurringJob,
        ]);
    }

    #[Route(path: '/schedule-init', name: 'command_schedule_init')]
    public function scheduleInit(Request $request, CommandJobFactory $commandJobFactory, TranslatorInterface $translator): Response
    {
        $listId = $request->query->get('listId');
        $listName = $request->query->get('listName');
        $commandScheduleRequest = $request->get('command-schedule');
        $commandName = $commandScheduleRequest['command'] ?? null;
        $runMethod = $commandScheduleRequest['method'] ?? null;
        if (empty($commandName) || empty($runMethod)) {
            // Redirect back to the command schedule if somehow missing some of required values
            $this->addFlash('danger', $translator->trans('command.schedule.job.error.name'));
            return $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
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

        try {
            // Recurring jobs to schedule
            $recurring = $runMethod === 'recurring';
            if ($recurring) {
                // Get recurring time
                $recurringFrequency = $commandScheduleRequest['cron'] ?? null;
                if (empty($recurringFrequency)) {
                    // Redirect back to the command schedule if missing cron date/time value
                    $this->addFlash('danger', $translator->trans('command.schedule.job.error.name'));
                    return $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
                }

                // Get if we were editing existing one and if is active
                $editId = $commandScheduleRequest['editId'] ?? null;
                $recurringActive = isset($commandScheduleRequest['active']);

                if (!empty($editId)) {
                    $recurringJob = $this->entityManager->getRepository(JobRecurring::class)->find($editId);
                    $commandJobFactory->updateRecurringCommandJob($recurringJob, $commandName, $params, $recurringFrequency, $recurringActive);
                } else {
                    $commandJobFactory->createRecurringCommandJob($commandName, $params, $recurringFrequency, $recurringActive);
                }

                $this->addFlash('success', $translator->trans('job.creation.success'));

                if ($this->isGranted(JobQueuePermissions::ROLE_JOB_LIST)) {
                    return $this->redirectToRoute('job_queue_recurring_list');
                } else {
                    return !empty($editId)
                        ? $this->redirectToRoute('command_schedule_edit', ['id' => $editId])
                        : $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
                }
            }

            // Create the command job to run if recurring is false
            $job = $commandJobFactory->createCommandJob($commandName, $params, $listId, $listName);
        } catch (OptimisticLockException|ORMException|CommandJobException $e) {
            // Redirect back to the command schedule
            $this->addFlash('danger', $translator->trans('job.creation.error') . ' - ' . $e->getMessage() . '.');
            return !empty($editId)
                ? $this->redirectToRoute('command_schedule_edit', ['id' => $editId])
                : $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
        }

        $this->addFlash('success', $translator->trans('job.creation.success'));

        // Redirect to the command job detail or list if is granted
        if ($this->isGranted(JobQueuePermissions::ROLE_JOB_READ)) {
            return $this->redirectToRoute('job_queue_detail', ['id' => $job->getId(), 'listId' => $listId, 'listName' => $listName]);
        } elseif ($this->isGranted(JobQueuePermissions::ROLE_JOB_LIST)) {
            return $this->redirectToRoute('job_queue_list', ['id' => $listId, 'name' => $listName]);
        }

        // Return back to schedule otherwise
        return $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
    }

    /**
     * Get all app commands to run except for symfony _complete and completion ones
     * @param KernelInterface $kernel
     * @return array
     */
    private function getApplicationCommands(KernelInterface $kernel): array
    {
        $application = new Application($kernel);
        $commands = $application->all();
        unset($commands['_complete'], $commands['completion']);
        ksort($commands);
        return $commands;
    }
}
