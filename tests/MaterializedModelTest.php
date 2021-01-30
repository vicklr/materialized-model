<?php
namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Vicklr\MaterializedModel\Events\MaterializedModelMovedEvent;
use Vicklr\MaterializedModel\Exceptions\MoveNotPossibleException;
use Vicklr\MaterializedModel\HierarchyCollection;

class MaterializedModelTest extends TestCase
{
    protected Folder $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = Folder::create(['name' => 'Root folder']);
    }

    /** @test */
    public function it_can_add_a_child()
    {
        $this->root->children()->create(['name' => 'Child folder']);
        $this->assertEquals(1, $this->root->children->count());
        $this->assertEquals('Child folder', $this->root->children->first()->name);
    }

    /** @test **/
    public function it_can_fetch_the_root()
    {
        $fetchedRoot = Folder::root();

        $this->assertTrue($this->root->is($fetchedRoot));
    }

    /** @test **/
    public function it_can_fetch_all_roots_as_a_hierarchy_collection()
    {
        $newRoot = Folder::create(['name' => 'Second Root']);
        $this->root->children()->create(['name' => 'Child folder']);

        $roots = Folder::roots()->get();

        $this->assertCount(2, $roots);
        $this->assertTrue($roots->contains($this->root));
        $this->assertTrue($roots->contains($newRoot));
        $this->assertInstanceOf(HierarchyCollection::class, $roots);
    }

    /** @test **/
    public function it_sets_path_and_depth_on_save()
    {
        $id = \DB::table('folders')->insertGetId(['name' => 'Child folder', 'parent_id' => $this->root->id, 'path' => '', 'depth' => 0]);
        $child = Folder::findOrFail($id);
        $this->assertEmpty($child->path);

        $child->save();
        $child->refresh();

        $this->assertNotEmpty($child->path);
        $this->assertEquals("/{$this->root->id}/", $child->path);
        $this->assertEquals(1, $child->getDepth());
    }

    /** @test **/
    public function it_can_rebuild_the_hierarchy()
    {
        // Simulate a corrupted hierarchy
        $id = \DB::table('folders')->insertGetId(['name' => 'Child folder', 'parent_id' => $this->root->id, 'path' => '', 'depth' => 0]);
        $child = Folder::findOrFail($id);
        $this->assertEmpty($child->path);

        $this->root->rebuild();
        $child->refresh();

        $this->assertNotEmpty($child->path);
        $this->assertEquals("/{$this->root->id}/", $child->path);
        $this->assertEquals(1, $child->getDepth());
    }

    /** @test **/
    public function it_rebuilds_the_hierarchy_below_when_changing_parents()
    {
        $newRoot = Folder::create(['name' => 'Second Root']);
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $subfolder->parent_id = $newRoot->id;
        $subfolder->save();
        $child->refresh();

        $this->assertNotEmpty($child->path);
        $this->assertEquals("/{$newRoot->id}/{$subfolder->id}/", $child->path);
        $this->assertEquals(2, $child->getDepth());
    }

    /** @test **/
    public function it_can_fetch_all_roots_except_a_given_node()
    {
        $newRoot = Folder::create(['name' => 'Second Root']);

        $roots = Folder::roots()->withoutNode($newRoot)->get();

        $this->assertCount(1, $roots);
        $this->assertTrue($roots->contains($this->root));
        $this->assertFalse($roots->contains($newRoot));
    }

    /** @test **/
    public function it_can_fetch_the_root_of_a_child()
    {
        Folder::create(['name' => 'Second Root']);
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $childRoot = $child->getRoot();

        $this->assertTrue($this->root->is($childRoot));
    }

    /** @test **/
    public function it_can_make_a_child_a_root()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);
        $this->assertTrue($child->isChild());

        $child->makeRoot();
        $child->refresh();

        $this->assertTrue($child->isRoot());
    }

    /** @test **/
    public function it_can_move_a_node_to_another_parent()
    {
        $newRoot = Folder::create(['name' => 'Second Root']);
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $child->makeChildOf($newRoot);
        $child->refresh();

        $this->assertNotEmpty($child->path);
        $this->assertTrue($newRoot->is($child->parent));
        $this->assertEquals("/{$newRoot->id}/", $child->path);
        $this->assertEquals(1, $child->getDepth());
    }

    /** @test **/
    public function it_can_move_a_node_to_become_a_sibling()
    {
        $folder = Folder::create(['name' => 'Future sibling']);
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $folder->makeSiblingOf($child);
        $folder->refresh();

        $this->assertTrue($folder->parent->is($child->parent));
        $this->assertEquals(2, $folder->getDepth());
    }

    /** @test **/
    public function it_can_move_a_node_to_become_a_child_by_id()
    {
        $folder = Folder::create(['name' => 'Future child']);
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $folder->makeChildOf($subfolder->id);
        $folder->refresh();

        $this->assertTrue($folder->parent->is($subfolder));
        $this->assertEquals(2, $folder->getDepth());
    }

    /** @test **/
    public function it_dispatches_an_event_when_moving_to_a_new_parent()
    {
        Event::fake();

        $newRoot = Folder::create(['name' => 'Second Root']);
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $child->makeChildOf($newRoot);
        $child->refresh();

        Event::assertDispatched(
            MaterializedModelMovedEvent::class,
            fn(MaterializedModelMovedEvent $event) => ($this->root->is($event->previousParent)) && $child->is($event->model)
        );
    }

    /** @test **/
    public function it_dispatches_an_event_when_moving_a_root_to_a_new_parent()
    {
        Event::fake();

        $folder = Folder::create(['name' => 'Second Root']);

        $folder->makeChildOf($this->root);
        $folder->refresh();

        Event::assertDispatched(
            MaterializedModelMovedEvent::class,
            fn(MaterializedModelMovedEvent $event) => is_null($event->previousParent) && $folder->is($event->model)
        );
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_a_child_by_nonexisting_id()
    {
        $folder = Folder::create(['name' => 'Future child']);
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->expectException(ModelNotFoundException::class);

        $folder->makeChildOf($subfolder->id + 50);
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_a_child_of_its_own_child()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->expectException(MoveNotPossibleException::class);

        $this->root->makeChildOf($subfolder);
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_a_child_of_itself()
    {
        $this->expectException(MoveNotPossibleException::class);

        $this->root->makeChildOf($this->root);
    }
}
