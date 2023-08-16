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
    #[Route(path: '/{id}', name: 'job_queue_detail')]
    public function detail($id): Response
    {
        /** @var Job $job */
        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['id' => $id]);

        return $this->render('job/detail.html.twig', [
            'job' => $job
        ]);
    }

    /**
     * @param int|null $id - Related entity id
     * @param string|null $name - Related entity class name (self::class)
     * @return Response
     * @throws NotSupported
     */
    #[Route(path: '/list/{id}/{name}', name: 'job_queue_list')]
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

        return $this->render('job/list.html.twig', [
            'jobs' => $jobs,
            'relatedEntityId' => $id,
            'relatedEntity' => $entity,
        ]);
    }
}
