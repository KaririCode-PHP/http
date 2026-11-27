<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Unit\Message;

use KaririCode\Http\Exception\HttpCode;
use KaririCode\Http\Exception\InvalidMessageException;
use KaririCode\Http\Exception\StreamException;
use KaririCode\Http\Exception\UploadedFileException;
use KaririCode\Http\Message\Silently;
use KaririCode\Http\Message\Stream;
use KaririCode\Http\Message\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

#[CoversClass(UploadedFile::class)]
#[CoversClass(UploadedFileException::class)]
#[CoversClass(Silently::class)]
final class UploadedFileMovesTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kariricode-http-upload-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    // ── $_FILES trees ────────────────────────────────────────────────────

    #[Test]
    public function aSingleUploadBecomesOneFile(): void
    {
        $tree = UploadedFile::treeFromGlobals(['avatar' => ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => '/tmp/php1', 'error' => UPLOAD_ERR_OK, 'size' => 120]]);

        $file = $tree['avatar'];
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('me.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame(120, $file->getSize());
    }

    #[Test]
    public function aMultipleUploadBecomesAListPerField(): void
    {
        $tree = UploadedFile::treeFromGlobals(['docs' => [
            'name' => ['a.txt', 'b.txt'],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/php1', '/tmp/php2'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL],
            'size' => [1, 2],
        ]]);

        self::assertIsArray($tree['docs']);
        self::assertCount(2, $tree['docs']);
        self::assertSame('b.txt', self::file($tree['docs'][1])->getClientFilename());
        self::assertSame(UPLOAD_ERR_PARTIAL, self::file($tree['docs'][1])->getError());
        self::assertSame(1, self::file($tree['docs'][0])->getSize());
    }

    #[Test]
    public function nestedFieldNamesBecomeNestedArrays(): void
    {
        $tree = UploadedFile::treeFromGlobals(['user' => [
            'name' => ['photos' => ['front' => 'f.jpg']],
            'type' => ['photos' => ['front' => 'image/jpeg']],
            'tmp_name' => ['photos' => ['front' => '/tmp/php9']],
            'error' => ['photos' => ['front' => UPLOAD_ERR_OK]],
            'size' => ['photos' => ['front' => 9]],
        ]]);

        self::assertIsArray($tree['user']);
        self::assertIsArray($tree['user']['photos']);
        self::assertSame('f.jpg', self::file($tree['user']['photos']['front'])->getClientFilename());
        self::assertSame(9, self::file($tree['user']['photos']['front'])->getSize());
    }

    #[Test]
    public function missingOrMistypedFieldsAreNotTrusted(): void
    {
        $tree = UploadedFile::treeFromGlobals(['a' => ['tmp_name' => '/tmp/php1'], 'b' => ['tmp_name' => 42, 'error' => '0', 'size' => '7', 'name' => ['x'], 'type' => false]]);

        self::assertSame(UPLOAD_ERR_NO_FILE, self::file($tree['a'])->getError(), 'no error field means no file');
        self::assertNull(self::file($tree['a'])->getSize());
        self::assertNull(self::file($tree['a'])->getClientFilename());
        self::assertSame(UPLOAD_ERR_NO_FILE, self::file($tree['b'])->getError(), 'a string error code is not trusted');
        self::assertNull(self::file($tree['b'])->getSize());
        self::assertNull(self::file($tree['b'])->getClientFilename());
        self::assertNull(self::file($tree['b'])->getClientMediaType());
    }

    #[Test]
    public function uploadedFileObjectsAndGroupsPassThrough(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1);

        $tree = UploadedFile::treeFromGlobals(['ready' => $file, 'group' => ['one' => $file]]);

        self::assertSame($file, $tree['ready']);
        self::assertSame(['one' => $file], $tree['group']);
    }

    #[Test]
    public function aLeafThatIsNoUploadIsRefused(): void
    {
        try {
            UploadedFile::treeFromGlobals(['bad' => 'text']);
            self::fail('a string leaf was accepted');
        } catch (InvalidMessageException $e) {
            self::assertSame(HttpCode::InvalidUploadedFiles, $e->errorCode);
        }
    }

    // ── Streams and moves ────────────────────────────────────────────────

    #[Test]
    public function aFileOnDiskIsOpenedAsAStream(): void
    {
        $path = $this->directory . '/upload';
        file_put_contents($path, 'on disk');

        self::assertSame('on disk', (string) (new UploadedFile($path, 7))->getStream());
    }

    #[Test]
    public function aMissingFileOnDiskCannotBeOpened(): void
    {
        try {
            (new UploadedFile($this->directory . '/missing', 0))->getStream();
            self::fail('a missing file was opened');
        } catch (StreamException $e) {
            self::assertSame(HttpCode::StreamOpenFailed, $e->errorCode);
            self::assertStringNotContainsString($this->directory, $e->getMessage(), 'no server path in the message');
        }
    }

    #[Test]
    public function aStreamIsCopiedWholeEvenWhenItWasRead(): void
    {
        $content = str_repeat('0123456789', 2000);
        $source = Stream::fromString($content);
        $source->read(100);
        $file = new UploadedFile($source, strlen($content));

        $file->moveTo($this->directory . '/copy');

        self::assertSame($content, file_get_contents($this->directory . '/copy'));
        self::assertMoveFails(HttpCode::UploadAlreadyMoved, $file, $this->directory . '/again');
        self::assertFileDoesNotExist($this->directory . '/again');

        try {
            $file->getStream();
            self::fail('a moved file still gave its stream');
        } catch (UploadedFileException $e) {
            self::assertSame(HttpCode::UploadAlreadyMoved, $e->errorCode);
        }
    }

    #[Test]
    public function aTemporaryFileIsRenamedUnderTheCli(): void
    {
        $temporary = $this->directory . '/php-tmp';
        file_put_contents($temporary, 'payload');

        (new UploadedFile($temporary, 7))->moveTo($this->directory . '/stored');

        self::assertFileDoesNotExist($temporary);
        self::assertSame('payload', file_get_contents($this->directory . '/stored'));
    }

    #[Test]
    public function aMoveThatFailsSaysSoAndCanBeRetried(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1);
        $missingTemporary = new UploadedFile($this->directory . '/gone', 1);

        self::assertMoveFails(HttpCode::UploadMoveFailed, $file, $this->directory . '/no/such/directory/file');
        self::assertMoveFails(HttpCode::UploadMoveFailed, $missingTemporary, $this->directory . '/target');

        $file->moveTo($this->directory . '/retry');
        self::assertSame('x', file_get_contents($this->directory . '/retry'), 'a failed move does not count as moved');
    }

    #[Test]
    public function aShortWriteIsAFailedMove(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('/dev/full is a Linux device');
        }

        self::assertMoveFails(HttpCode::UploadMoveFailed, new UploadedFile(Stream::fromString(str_repeat('x', 10000)), 10000), '/dev/full');
    }

    #[Test]
    public function theTargetPathMustBeAPath(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1);

        foreach (['', "target\0.php"] as $path) {
            try {
                $file->moveTo($path);
                self::fail('an empty or NUL path was accepted');
            } catch (InvalidMessageException $e) {
                self::assertSame(HttpCode::InvalidTargetPath, $e->errorCode);
            }
        }
    }

    #[Test]
    public function aFailedUploadHasNoStreamAndCannotMove(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1, UPLOAD_ERR_INI_SIZE);

        self::assertMoveFails(HttpCode::UploadError, $file, $this->directory . '/x');

        try {
            $file->getStream();
            self::fail('a failed upload gave a stream');
        } catch (UploadedFileException $e) {
            self::assertSame(HttpCode::UploadError, $e->errorCode);
            self::assertStringContainsString('UPLOAD_ERR_INI_SIZE', $e->getMessage());
        }

        try {
            (new UploadedFile('', null, 99))->getStream();
            self::fail('an unknown error gave a stream');
        } catch (UploadedFileException $e) {
            self::assertStringContainsString('an unknown error', $e->getMessage());
        }
    }

    private static function file(mixed $node): UploadedFileInterface
    {
        self::assertInstanceOf(UploadedFileInterface::class, $node);

        return $node;
    }

    private static function assertMoveFails(HttpCode $code, UploadedFile $file, string $target): void
    {
        try {
            $file->moveTo($target);
            self::fail('the move succeeded');
        } catch (UploadedFileException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    #[Test]
    public function aTreeEntryReadsItsTemporaryFile(): void
    {
        $temporary = $this->directory . '/php-upload';
        file_put_contents($temporary, 'uploaded bytes');

        $tree = UploadedFile::treeFromGlobals(['doc' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $temporary, 'error' => UPLOAD_ERR_OK, 'size' => 14]]);

        self::assertSame('uploaded bytes', (string) self::file($tree['doc'])->getStream());
    }
}
