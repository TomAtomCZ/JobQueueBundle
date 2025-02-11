<?php

namespace TomAtom\JobQueueBundle\Controller;

use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use Knp\Component\Pager\PaginatorInterface;
use Spiriit\Bundle\FormFilterBundle\Filter\FilterBuilderUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Form\JobFilterType;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

#[Route(path: '/job')]
class JobController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface    $translator,
        private readonly PaginatorInterface     $paginator
    )
    {
    }

    /**
     * @param Job|null $job
     * @param Request $request
     * @return Response
     */
    #[IsGranted(JobQueuePermissions::ROLE_JOB_READ)]
    #[Route(path: '/{id<\d+>}', name: 'job_queue_detail')]
    public function detail(?Job $job, Request $request): Response
    {
        if (empty($job)) {
            // Redirect to list if job doesn't exist
            $this->addFlash('warning', $this->translator->trans('job.detail.error.not_found'));
            return $this->redirectToRoute('job_queue_list', [
                'id' => $request->query->get('listId'),
                'name' => $request->query->get('listName')
            ]);
        }

        if (!empty($job->getRelatedEntityClassName()) && !empty($job->getRelatedEntityId())) {
            // Get related entity if job has one
            $entity = $this->entityManager->getRepository($job->getRelatedEntityClassName(true))->find($job->getRelatedEntityId());
            if (empty($entity)) {
                $this->addFlash('warning', $this->translator->trans('job.detail.error.related_entity_not_found'));
            }
        }

        return $this->render('@JobQueue/job/detail.html.twig', [
            'job' => $job,
            'relatedEntity' => $entity ?? null,
            'relatedEntityId' => $job->getRelatedEntityId(),
            'relatedEntityName' => $job->getRelatedEntityClassName()
        ]);
    }

    /**
     * @param Request $request
     * @param FilterBuilderUpdater $filterQueryUpdater
     * @param int|null $id - Related entity id
     * @param string|null $name - Related entity class name (self::class)
     * @return Response
     */
    #[IsGranted(JobQueuePermissions::ROLE_JOB_LIST)]
    #[Route(path: '/list/{name}/{id}', name: 'job_queue_list', defaults: ['name' => null, 'id' => null])]
    public function list(Request $request, FilterBuilderUpdater $filterQueryUpdater, ?string $name = null, ?int $id = null): Response
    {
        if (!empty($name) && !empty($id)) {
            $entity = $this->entityManager->getRepository(str_starts_with($name, 'App\\Entity\\') ? $name : 'App\\Entity\\' . $name)->find($id);
            if (empty($entity)) {
                $this->addFlash('warning', $this->translator->trans('job.detail.error.related_entity_not_found'));
            }
        }

        $jobsQuery = $this->entityManager->getRepository(Job::class)
            ->createQueryBuilder('j');

        if (!empty($name)) {
            $jobsQuery = $jobsQuery->andWhere('j.relatedEntityClassName = :name')
                ->setParameter('name', $name);
        }
        if (!empty($id)) {
            $jobsQuery = $jobsQuery->andWhere('j.relatedEntityId = :id')
                ->setParameter('id', $id);
        }

        $jobsQuery = $jobsQuery->orderBy('j.createdAt', 'DESC');

        // Get filters with created at transform
        $filters = $request->query->all()['job_filter'] ?? [];

        if (isset($filters['createdAt'])) {
            foreach (['left_datetime', 'right_datetime'] as $key) {
                if (!empty($filters['createdAt'][$key]) && is_string($filters['createdAt'][$key])) {
                    try {
                        $filters['createdAt'][$key] = new DateTime($filters['createdAt'][$key]);
                    } catch (Exception $e) {
                        $this->addFlash('warning', $e->getMessage());
                        $filters['createdAt'][$key] = null;
                    }
                } elseif (empty($filters['createdAt'][$key])) {
                    $filters['createdAt'][$key] = null;
                }
            }
        }

        $filterForm = $this->createForm(JobFilterType::class, $filters);
        $filterForm->handleRequest($request);
        $filterQueryUpdater->addFilterConditions($filterForm, $jobsQuery);

        $pagination = $this->paginator->paginate(
            $jobsQuery->getQuery(),
            $request->query->getInt('page', 1),
            50
        );
        $jobs = $pagination->getItems();

        return $this->render('@JobQueue/job/list.html.twig', [
            'jobs' => $jobs,
            'jobFilterForm' => $filterForm->createView(),
            'pagination' => $pagination,
            'relatedEntity' => $entity ?? null,
            'relatedEntityId' => $id,
            'relatedEntityName' => $name
        ]);
    }

    #[IsGranted(JobQueuePermissions::ROLE_JOB_LIST)]
    #[Route(path: '/recurring/list', name: 'job_queue_recurring_list')]
    public function recurringList(Request $request): Response
    {
        $jobs = $this->entityManager->getRepository(JobRecurring::class)
            ->createQueryBuilder('jr')
            ->orderBy('jr.createdAt', 'DESC');

        $pagination = $this->paginator->paginate(
            $jobs->getQuery(),
            $request->query->getInt('page', 1),
            50
        );
        $jobs = $pagination->getItems();

        return $this->render('@JobQueue/job/recurring_list.html.twig', [
            'jobs' => $jobs,
            'pagination' => $pagination
        ]);
    }

    #[IsGranted(JobQueuePermissions::ROLE_JOB_DELETE)]
    #[Route(path: '/delete/{id<\d+>}', name: 'job_queue_delete')]
    public function delete(?Job $job, Request $request): Response
    {
        if (empty($job)) {
            $this->addFlash('warning', $this->translator->trans('job.detail.error.not_found'));
            return $this->redirectToRoute('job_queue_list', [
                'id' => $request->query->get('listId'),
                'name' => $request->query->get('listName')
            ]);
        }

        if ($job->isDeletable()) {
            // Try to delete job if job is not running
            try {
                $this->entityManager->remove($job);
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('job.deletion.success'));
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('job.deletion.error') . $e->getMessage());
            }
        } else {
            $this->addFlash('warning', $this->translator->trans('job.deletion.not_deletable'));
        }

        return $this->redirectToRoute('job_queue_list', [
            'id' => $request->query->get('listId'),
            'name' => $request->query->get('listName')
        ]);
    }

    #[IsGranted(JobQueuePermissions::ROLE_JOB_DELETE)]
    #[Route(path: '/delete-recurring/{id<\d+>}', name: 'job_queue_delete_recurring')]
    public function deleteRecurring(?JobRecurring $job): Response
    {
        if (empty($job)) {
            $this->addFlash('warning', $this->translator->trans('job.detail.error.not_found'));
            return $this->redirectToRoute('job_queue_recurring_list');
        }

        // Try to delete recurring job
        try {
            $this->entityManager->remove($job);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('job.deletion.success'));
        } catch (Exception $e) {
            $this->addFlash('danger', $this->translator->trans('job.deletion.error') . $e->getMessage());
        }

        return $this->redirectToRoute('job_queue_recurring_list');
    }

    #[IsGranted(JobQueuePermissions::ROLE_JOB_CANCEL)]
    #[Route(path: '/cancel/{id<\d+>}', name: 'job_queue_cancel')]
    public function cancel(?Job $job, Request $request): Response
    {
        if (empty($job)) {
            $this->addFlash('warning', $this->translator->trans('job.detail.error.not_found'));
            return $this->redirectToRoute('job_queue_list', [
                'id' => $request->query->get('listId'),
                'name' => $request->query->get('listName')
            ]);
        }

        if ($job->isCancellable()) {
            // Try to cancel job if job is running
            try {
                $job->setCancelledAt(new DateTimeImmutable());
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('job.cancellation.success'));
            } catch (Exception $e) {
                $this->addFlash('danger', $this->translator->trans('job.cancellation.error') . $e->getMessage());
            }
        } else {
            $this->addFlash('warning', $this->translator->trans('job.cancellation.not_cancellable'));
        }

        return $this->redirectToRoute('job_queue_detail', [
            'id' => $job->getId(),
            'listId' => $request->query->get('listId'),
            'listName' => $request->query->get('listName')
        ]);
    }

    #[IsGranted(JobQueuePermissions::ROLE_JOB_CREATE)]
    #[Route(path: '/create-from-parent/{id<\d+>}', name: 'job_queue_create_from_parent')]
    public function createFromParent(?Job $parentJob, Request $request, CommandJobFactory $commandJobFactory): Response
    {
        $listId = $request->query->get('listId');
        $listName = $request->query->get('listName');

        if (empty($parentJob)) {
            $this->addFlash('warning', $this->translator->trans('job.detail.error.not_found'));
            return $this->redirectToRoute('job_queue_list', [
                'id' => $listId,
                'name' => $listName
            ]);
        }

        // Try to create the command job
        try {
            $job = $commandJobFactory->createCommandJob($parentJob->getCommand(), $parentJob->getCommandParams(), $listId, $listName, $parentJob, $parentJob->getJobRecurringParent());
            $this->addFlash('success', $this->translator->trans('job.creation.success'));
        } catch (OptimisticLockException|ORMException|CommandJobException $e) {
            // Redirect back to the command schedule
            $this->addFlash('danger', $this->translator->trans('job.creation.error') . ' - ' . $e->getMessage() . '.');
            return $this->redirectToRoute('command_schedule', ['listId' => $listId, 'listName' => $listName]);
        }

        return $this->redirectToRoute('job_queue_detail', [
            'id' => $job->getId(),
            'listId' => $listId,
            'listName' => $listName
        ]);
    }

    #[Route(path: '/ajax/update-output/{id<\d+>}', name: 'job_queue_ajax_update_output')]
    public function ajaxUpdateOutput(Job $job, Request $request): Response
    {
        // Get the current length of the output on frontend and the job current one
        $lastLength = (int)$request->query->get('length', 0);
        $output = $job->getOutput();
        $totalLength = mb_strlen($output, 'UTF-8');

        // Only return new part of the job output that isn't shown on the frontend
        $updatedOutput = '';
        if ($totalLength > $lastLength) {
            $updatedOutput = mb_substr($output, $lastLength, null, 'UTF-8');
        }

        // Return output and length with json encoding options to prevent encoding issues
        $response = new JsonResponse([
            'output' => $updatedOutput,
            'length' => $totalLength,
            'finished' => !$job->isRunning() && !$job->isPlanned(),
        ]);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $response;
    }
}
