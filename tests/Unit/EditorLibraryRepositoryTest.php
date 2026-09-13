<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\Editor\EditorOptions;
use App\Repositories\EditorLibraryRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EditorLibraryFixture;

final class EditorLibraryRepositoryTest extends TestCase
{
    use EditorLibraryFixture;

    public function testTemplatePersistsNormalizedSnapshotAndForeignOwnerCannotReadChangeOrRemove(): void
    {
        $pdo = $this->libraryDatabase();
        $repo = new EditorLibraryRepository($pdo);
        $id = $repo->saveOwned(1, null, 'Meu estilo', 'clean', EditorOptions::fromArray(['style' => 'minimal']), '9:16');
        $fresh = new EditorLibraryRepository($pdo);
        self::assertSame('Meu estilo', $fresh->findOwned($id, 1)['name']);
        self::assertNull($fresh->findOwned($id, 2));
        self::assertFalse($fresh->removeOwned($id, 2));
        try { $repo->saveOwned(2, $id, 'Invasão', 'clean', EditorOptions::fromArray([]), '1:1'); self::fail('Foreign update accepted'); }
        catch (\OutOfBoundsException) {}
        self::assertSame('minimal', $fresh->snapshot($id, 1)['options']['style']);
        self::assertSame(['options', 'aspect_ratio'], array_keys($fresh->snapshot($id, 1)));
        self::assertTrue($fresh->removeOwned($id, 1));
        self::assertNull($fresh->findOwned($id, 1));
    }

    public function testTemplateCapAllowsUpdateButRejectsFiftyFirstInsert(): void
    {
        $repo = new EditorLibraryRepository($this->libraryDatabase());
        for ($i = 0; $i < 50; $i++) $id = $repo->saveOwned(1, null, 'Modelo ' . $i, 'custom', EditorOptions::fromArray([]), '16:9');
        $repo->saveOwned(1, $id, 'Alterado', 'clean', EditorOptions::fromArray([]), '1:1');
        self::assertCount(50, $repo->listOwned(1));
        $this->expectException(\DomainException::class);
        $repo->saveOwned(1, null, 'Extra', 'custom', EditorOptions::fromArray([]), '9:16');
    }

    public function testKitPersistsOwnedFavoritesAndRejectsForeignTemplate(): void
    {
        $pdo = $this->libraryDatabase(); $repo = new EditorLibraryRepository($pdo);
        $id = $repo->saveOwned(1, null, 'Favorito', 'clean', EditorOptions::fromArray([]), '9:16');
        $repo->saveKit(1, EditorOptions::fromArray(['title' => 'Marca']), '1:1', [$id]);
        self::assertSame([$id], (new EditorLibraryRepository($pdo))->kitForUser(1)['favorites']);
        self::assertSame('Marca', $repo->kitForUser(1)['options']['title']);
        $this->expectException(\OutOfBoundsException::class);
        $repo->saveKit(2, EditorOptions::fromArray([]), '9:16', [$id]);
    }

    public function testRemovingTemplatePrunesFavoriteWithoutChangingStoredOptions(): void
    {
        $repo = new EditorLibraryRepository($this->libraryDatabase());
        $id = $repo->saveOwned(1, null, 'Favorito', 'clean', EditorOptions::fromArray([]), '9:16');
        $repo->saveKit(1, EditorOptions::fromArray(['watermark' => 'Minha marca']), '9:16', [$id]);
        $repo->removeOwned($id, 1);
        self::assertSame([], $repo->kitForUser(1)['favorites']);
        self::assertSame('Minha marca', $repo->kitForUser(1)['options']['watermark']);
    }

    public function testRejectsLongName(): void
    {
        $repo = new EditorLibraryRepository($this->libraryDatabase());
        $this->expectException(\InvalidArgumentException::class);
        $repo->saveOwned(1, null, str_repeat('á', 81), 'custom', EditorOptions::fromArray([]), '9:16');
    }
}
