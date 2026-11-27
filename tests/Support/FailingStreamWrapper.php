<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

/**
 * A stream wrapper whose reads and writes fail the way a broken socket or
 * a full disk does: fread() and fwrite() return false.
 */
final class FailingStreamWrapper
{
    private const string SCHEME = 'kariricode-failing';

    /** @var resource|null set by PHP for every wrapper instance */
    public mixed $context = null;

    /**
     * @return resource
     */
    public static function open(): mixed
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $resource = fopen(self::SCHEME . '://stream', 'r+');

        return is_resource($resource) ? $resource : throw new \RuntimeException('The failing stream did not open.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): false
    {
        return false;
    }

    public function stream_write(string $data): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
