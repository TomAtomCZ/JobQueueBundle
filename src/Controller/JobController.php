<?php

namespace TomAtom\JobQueueBundle\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;

#[Route(path: '/job')]
class JobController extends AbstractController
{
    private EntityManager $entityManager;
    private TranslatorInterface $translator;

    public function __construct(EntityManagerInterface $entityManager, TranslatorInterface $translator)
    {
        $this->entityManager = $entityManager;
        $this->translator = $translator;
    }

    /**
     * @param int $id
     * @return Response
     * @throws EntityNotFoundException
     */
    #[IsGranted(JobQueuePermissions::ROLE_JOB_READ)]
    #[Route(path: '/{id<\d+>}', name: 'job_queue_detail')]
    public function detail(int $id): Response
    {
        /** @var Job $job */
        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['id' => $id]);

        if (empty($job)) {
            throw new EntityNotFoundException();
        }

        $entity = null;
        if (!empty($job->getRelatedEntityClassName()) && !empty($job->getRelatedEntityId())) {
            $entity = $this->entityManager->getRepository($job->getRelatedEntityClassName())->find($job->getRelatedEntityId());
        }

        return $this->render('@JobQueue/job/detail.html.twig', [
            'job' => $job,
            'relatedEntity' => $entity
        ]);
    }

    /**
     * @param int|null $id - Related entity id
     * @param string|null $name - Related entity class name (self::class)
     * @return Response
     */
    #[IsGranted(JobQueuePermissions::ROLE_JOB_LIST)]
    #[Route(path: '/list/{id?}/{name?}', name: 'job_queue_list')]
    public function list(?int $id = null, string $name = null): Response
    {
        $entity = null;
        if (!empty($name) && !empty($id)) {
            $entity = $this->entityManager->getRepository($name)->find($id);
        }

        $jobs = $this->entityManager
            ->createQueryBuilder()
            ->select('j')
            ->from(Job::class, 'j');

        if (!empty($id)) {
            $jobs = $jobs->where('j.relatedEntityId = :id')
                ->setParameter('id', $id);
        }

        $jobs = $jobs->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('@JobQueue/job/list.html.twig', [
            'jobs' => $jobs,
            'relatedEntityId' => $id,
            'relatedEntity' => $entity,
        ]);
    }

    #[IsGranted('ROLE_JQB_JOB_DELETE')]
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
}
