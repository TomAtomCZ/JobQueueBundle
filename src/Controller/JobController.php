<?php

namespace TomAtom\JobQueueBundle\Controller;

use TomAtom\JobQueueBundle\Entity\Job;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\NotSupported;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/job')]
class JobController extends AbstractController
{
    private EntityManager $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @param $id
     * @return Response
     * @throws NotSupported
     */
    #[Route(path: '/{id}', name: 'job_detail')]
    public function detail($id): Response
    {
        /** @var Job $job */
        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['id' => $id]);

        return $this->render('job/detail.html.twig', [
            'job' => $job
        ]);
    }

    /**
     * @param int|null $relatedEntityId
     * @return Response
     */
    #[Route(path: '/list/{id}', name: 'job_list')]
    public function list(?int $relatedEntityId = null): Response
    {
        $jobs = $this->entityManager
            ->createQueryBuilder()
            ->select('j')
            ->from(Job::class, 'j');

        if (!empty($relatedEntityId)) {
            $jobs->where('j.entityId = :entityId')
                ->setParameter('entityId', $relatedEntityId);
        }

        $jobs->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('job/list.html.twig', [
            'jobs' => $jobs,
            'relatedEntityId' => $relatedEntityId,
        ]);
    }
}
