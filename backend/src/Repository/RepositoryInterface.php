<?php

namespace App\Repository;

interface RepositoryInterface
{
    public function findAll(): array;

    public function findById(int $id): mixed;

    public function deleteById(int $id): bool;
}
