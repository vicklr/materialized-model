<?php
namespace Vicklr\MaterializedModel\Test;

class OrderedMaterializedModelTest extends TestCase
{
    protected Menu $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = Menu::create(['name' => 'Root folder']);
    }

    /** @test */
    public function it_can_add_children_to_menu_with_existing_children()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = Menu::create(['name' => 'Another Child folder']);

        $secondChild->makeChildOf($this->root);
        $firstChild->refresh();
        $secondChild->refresh();

        $this->assertEquals(1, $firstChild->getOrder());
        $this->assertEquals(2, $secondChild->getOrder());
    }

    /** @test */
    public function it_can_add_sibling_before_existing_child()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);

        $thirdChild = Menu::create(['name' => 'Yet another Child folder']);
        $thirdChild->makePreviousSiblingOf($secondChild);

        $firstChild->refresh();
        $secondChild->refresh();
        $thirdChild->refresh();

        $this->assertEquals(1, $firstChild->getOrder());
        $this->assertEquals(3, $secondChild->getOrder());
        $this->assertEquals(2, $thirdChild->getOrder());
    }

    /** @test */
    public function it_can_add_sibling_after_existing_child()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);

        $thirdChild = Menu::create(['name' => 'Yet another Child folder']);
        $thirdChild->makeNextSiblingOf($firstChild);

        $firstChild->refresh();
        $secondChild->refresh();
        $thirdChild->refresh();

        $this->assertEquals(1, $firstChild->getOrder());
        $this->assertEquals(3, $secondChild->getOrder());
        $this->assertEquals(2, $thirdChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_before_a_previous_sibling()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);

        $secondChild->makePreviousSiblingOf($firstChild);

        $firstChild->refresh();
        $this->assertEquals(2, $firstChild->getOrder());
        $this->assertEquals(1, $secondChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_after_a_previous_sibling()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);
        $thirdChild = $this->root->children()->create(['name' => 'Yet another Child folder']);

        $thirdChild->makeNextSiblingOf($firstChild);

        $firstChild->refresh();
        $secondChild->refresh();
        $this->assertEquals(1, $firstChild->getOrder());
        $this->assertEquals(3, $secondChild->getOrder());
        $this->assertEquals(2, $thirdChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_before_a_later_sibling()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);
        $thirdChild = $this->root->children()->create(['name' => 'Yet another Child folder']);

        $firstChild->makePreviousSiblingOf($thirdChild);
        $secondChild->refresh();
        $thirdChild->refresh();

        $this->assertEquals(2, $firstChild->getOrder());
        $this->assertEquals(1, $secondChild->getOrder());
        $this->assertEquals(3, $thirdChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_after_a_later_sibling()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);
        $thirdChild = $this->root->children()->create(['name' => 'Yet another Child folder']);

        $firstChild->makeNextSiblingOf($secondChild);
        $secondChild->refresh();
        $thirdChild->refresh();

        $this->assertEquals(2, $firstChild->getOrder());
        $this->assertEquals(1, $secondChild->getOrder());
        $this->assertEquals(3, $thirdChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_before_a_child_node_with_another_parent()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);

        $secondRoot = Menu::create(['name' => 'Second Root']);
        $thirdChild = $secondRoot->children()->create(['name' => 'Yet another Child folder']);
        $fourthChild = $secondRoot->children()->create(['name' => 'Child folder']);

        $firstChild->makePreviousSiblingOf($fourthChild);
        $firstChild->refresh();
        $secondChild->refresh();
        $thirdChild->refresh();
        $fourthChild->refresh();

        $this->assertEquals(2, $firstChild->getOrder());
        $this->assertEquals(1, $secondChild->getOrder());
        $this->assertEquals(1, $thirdChild->getOrder());
        $this->assertEquals(3, $fourthChild->getOrder());
    }

    /** @test **/
    public function it_moves_a_node_after_a_child_node_with_another_parent()
    {
        $firstChild = $this->root->children()->create(['name' => 'Child folder']);
        $secondChild = $this->root->children()->create(['name' => 'Another Child folder']);

        $secondRoot = Menu::create(['name' => 'Second Root']);
        $thirdChild = $secondRoot->children()->create(['name' => 'Yet another Child folder']);
        $fourthChild = $secondRoot->children()->create(['name' => 'Child folder']);

        $firstChild->makeNextSiblingOf($thirdChild);
        $firstChild->refresh();
        $secondChild->refresh();
        $thirdChild->refresh();
        $fourthChild->refresh();

        $this->assertEquals(2, $firstChild->getOrder());
        $this->assertEquals(1, $secondChild->getOrder());
        $this->assertEquals(1, $thirdChild->getOrder());
        $this->assertEquals(3, $fourthChild->getOrder());
    }
}
