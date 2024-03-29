<?php
namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Vicklr\MaterializedModel\Events\MaterializedModelMovedEvent;
use Vicklr\MaterializedModel\Exceptions\MoveNotPossibleException;
use Vicklr\MaterializedModel\HierarchyCollection;
use Vicklr\MaterializedModel\Test\Model\Folder;

class MaterializedModelTest extends TestCase
{
    protected Folder $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = Folder::create(['name' => 'Root folder']);
    }

    /** @test **/
    public function it_can_fetch_column_names()
    {
        $this->assertEquals('name', $this->root->getOrderColumnName());
        $this->assertEquals('parent_id', $this->root->getParentColumnName());
        $this->assertEquals('depth', $this->root->getDepthColumnName());
        $this->assertEquals('path', $this->root->getPathColumnName());
    }

    /** @test **/
    public function it_can_fetch_qualified_column_names()
    {
        $this->assertEquals('folders.name', $this->root->getQualifiedOrderColumnName());
        $this->assertEquals('folders.parent_id', $this->root->getQualifiedParentColumnName());
        $this->assertEquals('folders.depth', $this->root->getQualifiedDepthColumnName());
        $this->assertEquals('folders.path', $this->root->getQualifiedPathColumnName());
        $this->assertEquals('folders.created_at', $this->root->getQualifiedCreatedAtColumn());
        $this->assertEquals('folders.updated_at', $this->root->getQualifiedUpdatedAtColumn());
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
    public function it_can_query_descendants()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $this->assertTrue($child->is($this->root->descendants()->first()));
        $this->assertCount(1, $this->root->descendants()->get());
    }

    /** @test **/
    public function it_can_query_descendants_and_self()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $this->assertTrue($child->is($this->root->descendantsAndSelf()->first()));
        $this->assertCount(2, $this->root->descendantsAndSelf()->get());
    }

    /** @test **/
    public function it_can_fetch_descendants_with_reduced_properties()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $descendant = $this->root->getDescendants(1, ['id'])->first();

        $this->assertEquals($child->id, $descendant->id);
        $this->assertNotEquals($child->name, $descendant->name);
    }

    /** @test **/
    public function it_can_fetch_descendants_and_self()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $descendantsAndSelf = $this->root->getDescendantsAndSelf();

        $this->assertTrue($child->is($descendantsAndSelf->first()));
        $this->assertTrue($this->root->is($descendantsAndSelf->last()));
        $this->assertCount(2, $descendantsAndSelf);
    }

    /** @test **/
    public function it_can_fetch_descendants_and_self_with_reduced_properties()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $descendants = $this->root->getDescendantsAndSelf(1, ['id']);

        $this->assertEquals($child->id, $descendants->first()->id);
        $this->assertNotEquals($child->name, $descendants->first()->name);
    }

    /** @test **/
    public function it_sets_path_and_depth_on_save()
    {
        $id = \DB::table('folders')->insertGetId(['name' => 'Child folder', 'parent_id' => $this->root->id, 'path' => '', 'depth' => 0]);
        $child = Folder::findOrFail($id);
        $this->assertEmpty($child->path);
        $this->assertEquals(0, $child->getDepth());

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

        /** @var Folder $folder */
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
    public function it_cannot_move_a_node_to_become_sibling_of_its_own_child()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->expectException(MoveNotPossibleException::class);

        $this->root->makeSiblingOf($subfolder);
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_next_sibling_of_its_own_child()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->expectException(MoveNotPossibleException::class);

        $this->root->makeNextSiblingOf($subfolder);
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_previous_sibling_of_its_own_child()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->expectException(MoveNotPossibleException::class);

        $this->root->makePreviousSiblingOf($subfolder);
    }

    /** @test **/
    public function it_cannot_move_a_node_to_become_a_child_of_itself()
    {
        $this->expectException(MoveNotPossibleException::class);

        $this->root->makeChildOf($this->root);
    }

    /** @test **/
    public function it_can_get_the_root_of_an_unsaved_node()
    {
        $subfolder = $this->root->children()->make(['name' => 'Subfolder']);
        $newRoot = Folder::make(['name' => 'New Root']);

        $this->assertTrue($this->root->is($subfolder->getRoot()));
        $this->assertEquals($newRoot, $newRoot->getRoot());
    }

    /** @test **/
    public function it_can_get_descendants()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $descendants = $this->root->getDescendants();
        $this->assertCount(2, $descendants);
        $this->assertTrue($child->is($descendants->first()));
        $this->assertTrue($subfolder->is($descendants->last()));
    }

    /** @test **/
    public function it_can_get_descendants_and_self()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $descendants = $this->root->getDescendantsAndSelf();
        $this->assertCount(3, $descendants);
        $this->assertContains($child->id, $descendants->pluck('id'));
        $this->assertContains($subfolder->id, $descendants->pluck('id'));
        $this->assertContains($this->root->id, $descendants->pluck('id'));
    }

    /** @test **/
    public function it_can_limit_the_depth()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $descendants = $this->root->descendants()->limitDepth(1)->get();
        $this->assertCount(1, $descendants);
        $this->assertTrue($subfolder->is($descendants->first()));

        $descendants = $this->root->descendants()->limitDepth(2)->get();
        $this->assertCount(2, $descendants);
        $this->assertTrue($child->is($descendants->first()));
        $this->assertTrue($subfolder->is($descendants->get(1)));
    }

    /** @test **/
    public function it_can_get_ancestors()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $child = $subfolder->children()->create(['name' => 'Child folder']);

        $ancestors = $child->getAncestors();
        $this->assertCount(2, $ancestors);
        $this->assertTrue($this->root->is($ancestors->first()));
        $this->assertTrue($subfolder->is($ancestors->last()));
    }

    /** @test **/
    public function it_can_get_ancestors_and_self()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $ancestors = $subfolder->getAncestorsAndSelf();
        $this->assertCount(2, $ancestors);
        $this->assertTrue($this->root->is($ancestors->first()));
        $this->assertTrue($subfolder->is($ancestors->last()));
    }

    /** @test **/
    public function it_can_query_ancestors()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $this->assertTrue($this->root->is($child->ancestors()->first()));
        $this->assertCount(1, $child->ancestors()->get());
    }

    /** @test **/
    public function it_can_query_ancestors_and_self()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);

        $this->assertTrue($child->is($child->ancestorsAndSelf()->first()));
        $this->assertCount(2, $child->ancestorsAndSelf()->get());
    }

    /** @test **/
    public function it_can_get_siblings()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $sibling = $this->root->children()->create(['name' => 'Sibling']);

        $siblings = $subfolder->getSiblings();
        $this->assertCount(1, $siblings);
        $this->assertTrue($sibling->is($siblings->first()));
    }

    /** @test **/
    public function it_can_get_next_sibling()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $sibling = $this->root->children()->create(['name' => 'Subfolder Sibling']);

        $this->assertTrue($sibling->is($subfolder->getNextSibling()));
    }

    /** @test **/
    public function it_can_get_previous_sibling()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $sibling = $this->root->children()->create(['name' => 'Previous Sibling']);

        $this->assertTrue($sibling->is($subfolder->getPreviousSibling()));
    }

    /** @test **/
    public function it_can_get_siblings_and_self()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $sibling = $this->root->children()->create(['name' => 'Sibling']);

        $siblings = $subfolder->getSiblingsAndSelf();
        $this->assertCount(2, $siblings);
        $this->assertTrue($sibling->is($siblings->first()));
        $this->assertTrue($subfolder->is($siblings->last()));
    }

    /** @test **/
    public function it_can_query_siblings()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);
        $sibling = $this->root->children()->create(['name' => 'Sibling']);

        $this->assertTrue($sibling->is($child->siblings()->first()));
        $this->assertCount(1, $child->siblings()->get());
    }

    /** @test **/
    public function it_can_query_siblings_and_self()
    {
        $child = $this->root->children()->create(['name' => 'Child folder']);
        $this->root->children()->create(['name' => 'Sibling']);

        $this->assertTrue($child->is($child->siblingsAndSelf()->first()));
        $this->assertCount(2, $child->siblingsAndSelf()->get());
    }

    /** @test **/
    public function it_can_test_ancestry()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->assertTrue($this->root->isAncestorOf($subfolder));
        $this->assertTrue($subfolder->isSelfOrAncestorOf($subfolder));
        $this->assertFalse($subfolder->isAncestorOf($this->root));
        $this->assertFalse($subfolder->isSelfOrAncestorOf($this->root));
    }

    /** @test **/
    public function it_can_check_descendants()
    {
        $subfolder = $this->root->children()->create(['name' => 'Subfolder']);

        $this->assertFalse($this->root->isDescendantOf($subfolder));
        $this->assertTrue($subfolder->isSelfOrDescendantOf($subfolder));
        $this->assertTrue($subfolder->isDescendantOf($this->root));
        $this->assertTrue($subfolder->isSelfOrDescendantOf($this->root));
    }
}
