<?php
namespace Vicklr\MaterializedModel\Test;

use Vicklr\MaterializedModel\Test\Model\Folder;

class HierarchyCollectionTest extends TestCase
{
    public Folder $root;
    public Folder $subfolder;
    public Folder $child;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = Folder::create(['name' => 'Root folder']);
        $this->subfolder = $this->root->children()->create(['name' => 'Subfolder']);
        $this->child = $this->subfolder->children()->create(['name' => 'Child folder']);
        $secondRoot = Folder::create(['name' => 'Second Root']);
        $secondRoot->children()->create(['name' => 'Second subfolder']);
    }

    /** @test **/
    public function it_builds_a_hierarchy()
    {
        $hierarchy = Folder::all()->toHierarchy();

        $this->assertCount(2, $hierarchy);
        $this->assertCount(1, $hierarchy->first()->children);
        $this->assertCount(1, $hierarchy->first()->children->first()->children);
        $this->assertEquals('Child folder', $hierarchy->first()->children->first()->children->first()->name);
    }

    /** @test **/
    public function it_can_get_ancestors()
    {
        $hierarchy = Folder::whereId($this->child->id)->get()->getAncestors();

        $this->assertCount(2, $hierarchy);
        $this->assertContains($this->root->id, $hierarchy->pluck('id'));
        $this->assertContains($this->subfolder->id, $hierarchy->pluck('id'));
    }

    /** @test **/
    public function it_can_get_ancestors_and_selves()
    {
        $hierarchy = Folder::whereId($this->child->id)->get()->getAncestorsAndSelves();

        $this->assertCount(3, $hierarchy);
        $this->assertContains($this->root->id, $hierarchy->pluck('id'));
        $this->assertContains($this->subfolder->id, $hierarchy->pluck('id'));
        $this->assertContains($this->child->id, $hierarchy->pluck('id'));
    }

    /** @test **/
    public function it_can_get_descendants()
    {
        $hierarchy = Folder::whereId($this->subfolder->id)->get()->getDescendants();

        $this->assertCount(1, $hierarchy);
        $this->assertContains($this->child->id, $hierarchy->pluck('id'));
    }

    /** @test **/
    public function it_can_get_descendants_and_selves()
    {
        $hierarchy = Folder::whereId($this->subfolder->id)->get()->getDescendantsAndSelves();

        $this->assertCount(2, $hierarchy);
        $this->assertContains($this->subfolder->id, $hierarchy->pluck('id'));
        $this->assertContains($this->child->id, $hierarchy->pluck('id'));
    }
}
