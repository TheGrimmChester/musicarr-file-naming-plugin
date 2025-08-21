<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Musicarr\FileNamingPlugin\Entity\FileNamingPattern;

/**
 * @extends ServiceEntityRepository<FileNamingPattern>
 *
 * @method FileNamingPattern|null find($id, $lockMode = null, $lockVersion = null)
 * @method FileNamingPattern|null findOneBy(array $criteria, array $orderBy = null)
 * @method FileNamingPattern[]    findAll()
 * @method FileNamingPattern[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FileNamingPatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileNamingPattern::class);
    }

    public function save(FileNamingPattern $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FileNamingPattern $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouve le pattern actif par défaut.
     */
    public function findActivePattern(): ?FileNamingPattern
    {
        return $this->findOneBy(['isActive' => true]);
    }

    /**
     * Trouve tous les patterns actifs.
     */
    public function findActivePatterns(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }
}
