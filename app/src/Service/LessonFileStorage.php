<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class LessonFileStorage
{
    public function __construct(#[Autowire('%kernel.project_dir%/storage/lesson-files')] private string $directory) {}

    public function store(UploadedFile $file): string
    {
        $name = bin2hex(random_bytes(24)).'.bin';
        $file->move($this->directory, $name);

        return $name;
    }

    public function path(string $name): string
    {
        if (!preg_match('/^[a-f0-9]{48}\.bin$/D', $name)) throw new \InvalidArgumentException('Invalid storage name.');
        return $this->directory.'/'.$name;
    }

    public function remove(?string $name): void
    {
        if ($name && is_file($path = $this->path($name))) unlink($path);
    }
}
