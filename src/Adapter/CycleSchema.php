<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/database
 * https://github.com/php-puff/database/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Database\Adapter;

use Cycle\Annotated;
use Cycle\Database\DatabaseManager;
use Cycle\Schema;
use Spiral\Tokenizer\Config\TokenizerConfig;
use Spiral\Tokenizer\Tokenizer;

final class CycleSchema
{
    /** @var array<string, array<int, mixed>>|null */
    private ?array $schema = null;

    public function __construct(
        private readonly string $entityDirectory,
        private readonly string $cacheFile,
    ) {
    }

    /** @return array<string, array<int, mixed>> */
    public function load(DatabaseManager $database): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $checksum = $this->checksum();
        $cached = $this->readCache();
        if (($cached['checksum'] ?? null) === $checksum && \is_array($cached['schema'] ?? null)) {
            /** @var array<string, array<int, mixed>> $schema */
            $schema = $cached['schema'];
            return $this->schema = $schema;
        }
        $schema = $this->compile($database);
        $this->writeCache($checksum, $schema);
        return $this->schema = $schema;
    }

    /** @return array<string, array<int, mixed>> */
    private function compile(DatabaseManager $database): array
    {
        if (!\is_dir($this->entityDirectory)) {
            return [];
        }
        $locator = (new Tokenizer(new TokenizerConfig([
            'directories' => [$this->entityDirectory],
            'exclude' => [],
        ])))->classLocator();
        return (new Schema\Compiler())->compile(new Schema\Registry($database), [
            new Schema\Generator\ResetTables(),
            new Annotated\Embeddings(new Annotated\Locator\TokenizerEmbeddingLocator($locator)),
            new Annotated\Entities(new Annotated\Locator\TokenizerEntityLocator($locator)),
            new Annotated\TableInheritance(),
            new Annotated\MergeColumns(),
            new Schema\Generator\GenerateRelations(),
            new Schema\Generator\GenerateModifiers(),
            new Schema\Generator\ValidateEntities(),
            new Schema\Generator\RenderTables(),
            new Schema\Generator\RenderRelations(),
            new Schema\Generator\RenderModifiers(),
            new Schema\Generator\ForeignKeys(),
            new Annotated\MergeIndexes(),
            new Schema\Generator\GenerateTypecast(),
        ]);
    }

    private function checksum(): string
    {
        $files = [];
        if (\is_dir($this->entityDirectory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $this->entityDirectory,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        \sort($files);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[] = $file . ':' . (\hash_file('sha256', $file) ?: '');
        }
        return \hash('sha256', \implode("\n", $hashes));
    }

    /** @return array<string, mixed> */
    private function readCache(): array
    {
        if (!\is_file($this->cacheFile)) {
            return [];
        }
        $data = require $this->cacheFile;
        return \is_array($data) ? $data : [];
    }

    /** @param array<string, array<int, mixed>> $schema */
    private function writeCache(string $checksum, array $schema): void
    {
        $directory = \dirname($this->cacheFile);
        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Unable to create Cycle schema cache directory [{$directory}].");
        }
        $source = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . \var_export(['checksum' => $checksum, 'schema' => $schema], true)
            . ";\n";
        $temporary = $this->cacheFile . '.' . \getmypid() . '.tmp';
        if (\file_put_contents($temporary, $source, LOCK_EX) === false || !@\rename($temporary, $this->cacheFile)) {
            @\unlink($temporary);
            throw new \RuntimeException("Unable to write Cycle schema cache [{$this->cacheFile}].");
        }
    }
}
