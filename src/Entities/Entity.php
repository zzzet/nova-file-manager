<?php

declare(strict_types=1);

namespace Oneduo\NovaFileManager\Entities;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Laravel\Nova\Http\Requests\NovaRequest;
use League\Flysystem\UnableToRetrieveMetadata;
use Oneduo\NovaFileManager\Contracts\Entities\Entity as EntityContract;
use Oneduo\NovaFileManager\Contracts\Services\FileManagerContract;

abstract class Entity implements Arrayable, EntityContract
{
    protected array $data = [];

    public function __construct(
        public FileManagerContract $manager,
        public string $path,
        public string $disk,
    ) {
    }

    /**
     * Static helper
     *
     * @param  \Oneduo\NovaFileManager\Contracts\Services\FileManagerContract  $manager
     * @param  string  $path
     * @param  string  $disk
     * @return static
     */
    public static function make(FileManagerContract $manager, string $path, string $disk): static
    {
        return new static($manager, $path, $disk);
    }

    /**
     * Return the entity's data as array
     *
     * @return array
     */
    public function toArray(): array
    {
        if (empty($this->data)) {
            $shouldAnalyze = config('nova-file-manager.file_analysis.enabled');

            if ($this->manager->filesystem()->exists($this->path)) {
                $this->data = array_merge(
                    [
                        'id' => $this->id(),
                        'disk' => $this->disk,
                        'name' => $this->name(),
                        'path' => $this->path,
                        'size' => $this->size(),
                        'extension' => $this->extension(),
                        'mime' => $this->mime(),
                        'url' => $this->url(),
                        'lastModifiedAt' => $this->lastModifiedAt(),
                        'type' => $this->type(),
                        'exists' => true,
                    ],
                    [
                        'meta' => $shouldAnalyze ? $this->meta() : [],
                    ],
                );
            } else {
                $this->data = array_merge([
                    'id' => $this->id(),
                    'disk' => $this->disk,
                    'path' => $this->path,
                    'exists' => false,
                ]);
            }
        }

        return $this->data;
    }

    /**
     * Generate a unique identifier for the entity
     *
     * @return string
     */
    public function id(): string
    {
        return sha1($this->manager->filesystem()->path($this->path));
    }

    /**
     * Get the name of the entity
     *
     * @return string
     */
    public function name(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    /**
     * Compute the size of the entity
     *
     * @return int|string
     */
    public function size(): int|string
    {
        $value = $this->manager->filesystem()->size($this->path);

        if (!config('nova-file-manager.human_readable_size')) {
            return $value;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $value > 1024; $i++) {
            $value /= 1024;
        }

        return round($value, 2).' '.$units[$i];
    }

    /**
     * Get the file extension of the entity
     *
     * @return string
     */
    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * Get the mime type of the entity
     *
     * @return string
     */
    public function mime(): string
    {
        try {
            $type = $this->manager->filesystem()->mimeType($this->path);

            if ($type === false) {
                throw UnableToRetrieveMetadata::mimeType($this->path);
            }

            return $type;
        } catch (UnableToRetrieveMetadata $e) {
            report($e);

            return 'application/octet-stream';
        }
    }

    /**
     * Build an url for the entity based on the disk
     *
     * @return string
     */
    public function url(): string
    {
        // if a custom url builder is defined, we use it to return the url
        if ($this->manager->hasUrlResolver()) {
            return call_user_func(
                $this->manager->getUrlResolver(),
                app(NovaRequest::class),
                $this->path,
                $this->disk,
                $this->manager->filesystem(),
            );
        }

        $supportsSignedUrls = $this->manager->filesystem() instanceof FilesystemAdapter;

        // signed urls are only supported on S3 disks
        if ($supportsSignedUrls && config('nova-file-manager.url_signing.enabled')) {
            return $this->manager->filesystem()->temporaryUrl(
                $this->path,
                $this->signedExpirationTime(),
            );
        }

        // we fallback to the regular url builder
        return $this->manager->filesystem()->url($this->path);
    }

    /**
     * Get the expiration time from the user defined config
     *
     * @return \Illuminate\Support\Carbon
     */
    public function signedExpirationTime(): Carbon
    {
        return now()->add(
            config('nova-file-manager.url_signing.unit'),
            config('nova-file-manager.url_signing.value'),
        );
    }

    /**
     * Get the last modified time of the entity as string
     *
     * @return string
     */
    public function lastModifiedAt(): string
    {
        if (!config('nova-file-manager.human_readable_datetime')) {
            return $this->lastModifiedAtTimestamp()->toDateTimeString();
        }

        return $this->lastModifiedAtTimestamp()->diffForHumans();
    }

    /**
     * Get the last modified time of the entity as a Carbon instance
     *
     * @return \Illuminate\Support\Carbon
     */
    public function lastModifiedAtTimestamp(): Carbon
    {
        return Carbon::createFromTimestamp($this->manager->filesystem()->lastModified($this->path));
    }

    /**
     * Define the type of the entity
     *
     * @return string
     */
    public function type(): string
    {
        $mime = str($this->mime());

        return match ((string) $mime->before('/')) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'text' => 'text',
            'application' => (string) $mime->afterLast('/'),
            default => 'unknown',
        };
    }

    abstract public function meta(): array;
}
