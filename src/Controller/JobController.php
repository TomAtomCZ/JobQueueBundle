<?php

namespace TomAtom\JobQueueBundle\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
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
            $entity = $this->entityManager->getRepository($job->getRelatedEntityClassName())->find($job->getRelatedEntityId());
        }

        return $this->render('@JobQueue/job/detail.html.twig', [
            'job' => $job,
            'relatedEntity' => $entity ?? null
        ]);
    }

    /**
     * @param Request $request
     * @param int|null $id - Related entity id
     * @param string|null $name - Related entity class name (self::class)
     * @return Response
     */
    #[IsGranted(JobQueuePermissions::ROLE_JOB_LIST)]
    #[Route(path: '/list/{id?}/{name?}', name: 'job_queue_list')]
    public function list(Request $request, ?int $id = null, ?string $name = null): Response
    {
        if (!empty($name) && !empty($id)) {
            $entity = $this->entityManager->getRepository($name)->find($id);
        }

        $jobs = $this->entityManager->getRepository(Job::class)
            ->createQueryBuilder('j');

        if (!empty($id)) {
            $jobs = $jobs->where('j.relatedEntityId = :id')
                ->setParameter('id', $id);
        }

        $jobs = $jobs->orderBy('j.createdAt', 'DESC');

        $pagination = $this->paginator->paginate(
            $jobs->getQuery(),
            $request->query->getInt('page', 1),
            50
        );
        $jobs = $pagination->getItems();

        return $this->render('@JobQueue/job/list.html.twig', [
            'jobs' => $jobs,
            'relatedEntityId' => $id,
            'relatedEntity' => $entity ?? null,
            'pagination' => $pagination
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
            } catch (ORMException $e) {
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
    public function deleteRecurring(?JobRecurring $job, Request $request): Response
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
        } catch (ORMException $e) {
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
            } catch (ORMException $e) {
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
}
