# A solution for parent-child tree structures in Laravel

[![Latest Stable Version](https://poser.pugx.org/vicklr/materialized-model/v/stable?format=flat-square)](https://packagist.org/packages/vicklr/materialized-model)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/spatie/laravel-backup/run-tests?label=tests)
[![Total Downloads](https://img.shields.io/packagist/dt/vicklr/materialized-model.svg?style=flat-square)](https://packagist.org/packages/vicklr/materialized-model)

This Laravel package adds hierarchical functionality to your models.

# Materialized Model

Materized Model is an implementation of the [Materialized Paths](https://docs.mongodb.com/manual/tutorial/model-tree-structures-with-materialized-paths) pattern for [Laravel's](http://laravel.com/) Eloquent ORM.

## Documentation

* [About Materialized Paths](#about)
* [Installation](#installation)
* [Getting started](#getting-started)
* [Usage](#usage)
* [Further information](#further-information)
* [Contributing](#contributing)

<a name="about"></a>
## About Materialized Paths

The Materialized Paths pattern is a way to have a tree hierarchy of nodes
In addition to the node data, it also stores the id(s) of the node’s ancestors or path as a string. 
Although the Materialized Paths pattern requires additional steps of working with strings and regular expressions, 
the pattern also provides more flexibility in working with the path, such as finding nodes by partial paths.

For example, you can fetch all descendants of a node in a
single query, no matter how deep the tree. The drawback is that insertions/moves/deletes
require additional operations, but that is handled behind the scenes by this package.

Materialized Paths are appropriate for ordered trees (e.g. menus, commercial categories, folder structures)
and big trees that must be queried efficiently (e.g. threaded posts).

<a name="installation"></a>
## Installation

Materialized Model works with Laravel 7 onwards. You can add it to your `composer.json` file
with:

    "vicklr/materialized-model": "~1.0"

Run `composer install` to install it.

<a name="getting-started"></a>
## Getting started

After the package is correctly installed, it can be applied to your models.

* Add the Vicklr/MaterializedModel/Traits/HasMaterializedPaths trait to a class that extends Illuminate\Database\Eloquent\Model
* OR extend the Vicklr/MaterializedModel/MaterializedModel class if you need to modify column names or disable automatic ordering

### Model configuration

In order to work with Materialized Model, you must ensure that your model class uses
`Vicklr\MaterializedModel\Traits\HasMaterializedPaths`.

This is the easiest it can get:

```php
use Vicklr\MaterializedModel\Traits\HasMaterializedPaths;
use Illuminate\Database\Eloquent\Model;

class Category extends Model 
{
  use HasMaterializedPaths;
}
```

This is a *slightly* more complex example where we have the column names customized. In order to do so
we need to inherit from a base class that uses the trait - such a base class is included in the package,
but you can supply your own, as long as it uses the trait as described above:

```php
use Vicklr\MaterializedModel\MaterializedModel;
class Dictionary extends MaterializedModel 
{
  protected $table = 'dictionary';

  // 'parent_id' column name
  protected string $parentColumn = 'parent_id';

  // 'depth' column name
  protected string $depthColumn = 'depth';
  
  // 'path' column name
  protected string $pathColumn = 'path';
  
  // 'order' column name
  protected string $orderColumn = 'weight';

  // guard attributes from mass-assignment
  protected $guarded = array('id', 'parent_id', 'depth', 'path', 'weight');

}
```

Remember that, obviously, the column names must match those in the database table.

### Migration configuration

You must ensure that the database table that supports your Materialized Models has the
following columns:

* `parent_id`: a reference to the parent (int)
* `depth`: depth or nesting level (int)
* `path`: ancestor path (string)
* `ordering`: sort order (string or int)

For that, we have two helper macros on the Blueprint: materializedFields() and materializedOrdering()

The materializedFields() helper will set up the necessary fields for the hierarchy, while
materializedOrdering adds the numerical ordering field. If you do not want numerical ordering, define
the field yourself and remember to set it as orderColumn on the model

Here is a sample migration file:

```php
class CreateCategoriesTable extends Migration {

  public function up() {
    Schema::create('categories', function(Blueprint $table) {
      $table->id();

      $table->materializedFields($parent_name = 'parent_id', $path_name = 'path', $depth_name = 'depth', $primary_name = 'id');
      $table->materializedOrdering($order_name = 'weight');
    });
  }

  public function down() {
    Schema::drop('categories');
  }

}
```

You may freely modify the column names, but remember to also change them in the model.

<a name="usage"></a>
## Usage

After you've configured your model and run the migration, you are now ready
to use MaterializedModel with your model. Below are some examples.

* [Creating a root node](#creating-root-node)
* [Inserting nodes](#inserting-nodes)
* [Deleting nodes](#deleting-nodes)
* [Getting the nesting level of a node](#node-level)
* [Moving nodes around](#moving-nodes)
* [Asking questions to your nodes](#node-questions)
* [Relations](#node-relations)
* [Root scopes](#node-basic-scopes)
* [Accessing the ancestry/descendancy chain](#node-chains)
* [Limiting levels of children returned](#limiting-depth)
* [Custom sorting column](#custom-sorting-column)
* [Tree hierarchy](#hierarchy-tree)
* [Model event: `MaterializedModelMovedEvent`](#node-model-events)
* [Soft deletes](#soft-deletes)
* [Misc/Utility functions](#misc-utilities)

<a name="creating-root-node"></a>
### Creating a root node

By default, all nodes are created as roots:

```php
$root = Category::create(['name' => 'Root category']);
```

Alternatively, you may find yourself in the need of *converting* an existing node
into a *root node*:

```php
$node->makeRoot();
```

You may also nullify it's `parent_id` column to accomplish the same behaviour:

```php
// This works the same as makeRoot()
$node->parent_id = null;
$node->save();
```

<a name="inserting-nodes"></a>
### Inserting nodes

```php
// Directly with a relation
$child1 = $root->children()->create(['name' => 'Child 1']);

// with the `makeChildOf` method
$child2 = Category::create(['name' => 'Child 2']);
$child2->makeChildOf($root);
```

<a name="deleting-nodes"></a>
### Deleting nodes

```php
$child1->delete();
```

Descendants of deleted nodes will also be deleted due to a foreign key constraint in the database. 
Please note that, for now, `deleting` and `deleted` model events for the descendants will NOT be fired.

<a name="node-level"></a>
### Getting the nesting level of a node

The `getLevel()` method will return current nesting level, or depth, of a node. 
It makes a count query to the database, but could otherwise also be read by the depth field

```php
$node->getLevel(); // 0 when root
```

<a name="moving-nodes"></a>
### Moving nodes around

Materialized Model provides several methods for moving nodes around:

* `makeNextSiblingOf($otherNode)`: Make the node the next sibling of ...
* `makePreviousSiblingOf($otherNode)`: Make the node the previous sibling of ...
* `makeSiblingOf($otherNode)`: Alias of makeNextSiblingOf() ...
* `makeChildOf($otherNode)`: Make the node a child of ...
* `makeRoot()`: Make current node a root node.

For example:

```php
$root = Creatures::create(['name' => 'The Root of All Evil']);

$dragons = Creatures::create(['name' => 'Here Be Dragons']);
$dragons->makeChildOf($root);

$monsters = new Creatures(['name' => 'Horrible Monsters']);
$monsters->save();

$monsters->makeSiblingOf($dragons);

```

<a name="node-questions"></a>
### Asking questions to your nodes

You can ask some questions to your Materialized Model nodes:

* `isRoot()`: Returns true if this is a root node.
* `isChild()`: Returns true if this is a child node.
* `isDescendantOf($other)`: Returns true if node is a descendant of the other.
* `isSelfOrDescendantOf($other)`: Returns true if node is self or a descendant.
* `isAncestorOf($other)`: Returns true if node is an ancestor of the other.
* `isSelfOrAncestorOf($other)`: Returns true if node is self or an ancestor.

Using the nodes from the previous example:

```php
$demons->isRoot(); // => false

$demons->isDescendantOf($root); // => true
```

<a name="node-relations"></a>
### Relations

Materialized Model provides two self-referential Eloquent relations for your nodes: `parent`
and `children`.

```php
$parent = $node->parent()->get();

$children = $node->children()->get();
```

<a name="node-basic-scopes"></a>
### Root scopes

Materialized Model provides some very basic query scopes for accessing the root nodes:

```php
// Query scope which targets all root nodes
Category::roots();

```

You may also be interested in only the first root:

```php
$firstRootNode = Category::root();
```

<a name="node-chains"></a>
### Accessing the ancestry/descendancy chain

There are several methods which Materialized Model offers to access the ancestry/descendancy
chain of a node in the tree. The main thing to keep in mind is that
they are provided in two ways:

First as **query scopes**, returning an `Illuminate\Database\Eloquent\Builder`
instance to continue to query further. To get *actual* results from these,
remember to call `get()` or `first()`.

* `ancestorsAndSelf()`: Targets all the ancestor chain nodes including the current one.
* `ancestors()`: Query the ancestor chain nodes excluding the current one.
* `siblingsAndSelf()`: Instance scope which targets all children of the parent, including self.
* `siblings()`: Instance scope targeting all children of the parent, except self.
* `descendantsAndSelf()`: Scope targeting itself and all of its nested children.
* `descendants()`: Set of all children & nested children.

Second, as **methods** which return actual instances (inside a `Collection`
object where appropiate):

* `getRoot()`: Returns the root node starting at the current node.
* `getAncestorsAndSelf()`: Retrieve all of the ancestor chain including the current node.
* `getAncestors()`: Get all of the ancestor chain from the database excluding the current node.
* `getSiblingsAndSelf()`: Get all children of the parent, including self.
* `getSiblings()`: Return all children of the parent, except self.
* `getNextSibling()`: Return the sibling (if any) that has the same parent and is next in the ordering
* `getPreviousSibling()`: Return the sibling (if any) that has the same parent and is just before the current node in the ordering
* `getDescendantsAndSelf()`: Retrieve all nested children and self.
* `getDescendants()`: Retrieve all of its children & nested children.

Here's a simple example for iterating a node's descendants (provided a name
attribute is available):

```php
$node = Category::where('name', '=', 'Books')->first();

$node->getDescendantsAndSelf()->each(function($descendant) {
  echo "{$descendant->name}";
});
```

<a name="limiting-depth"></a>
### Limiting the levels of children returned

In some situations where the hierarchy depth is huge it might be desirable to limit the number of levels of children returned (depth). 
You can do this in Materialized Model by using the `limitDepth` query scope.

The following snippet will get the current node's descendants up to a maximum
of 5 depth levels below it:

```php
$node->descendants()->limitDepth(5)->get();
```

Similarly, you can limit the descendancy levels with both the `getDescendants` and `getDescendantsAndSelf` methods by supplying the desired depth limit as the first argument:

```php
// This will work without depth limiting
// 1. As usual
$node->getDescendants();
// 2. Selecting only some attributes
$other->getDescendants(array('id', 'parent_id', 'name'));
...
// With depth limiting
// 1. A maximum of 5 levels of children will be returned
$node->getDescendants(5);
// 2. A max. of 5 levels of children will be returned selecting only some attrs
$other->getDescendants(5, array('id', 'parent_id', 'name'));
```

<a name="custom-sorting-column"></a>
### Custom sorting column

In Materialized Model all results are returned sorted by the ordering column, you specify in your model

```php
protected $orderColumn = 'name';
```

<a name="hierarchy-tree"></a>
### Dumping the hierarchy tree

Materialized Model includes the HierarchyCollection that extends the default 
`Eloquent\Collection` class and provides the `toHierarchy` method to it which 
returns a nested collection representing the queried tree.

Retrieving a complete tree hierarchy into a regular `Collection` object with
its children *properly nested* is as simple as:

```php
$tree = Category::where('name', '=', 'Books')->first()->getDescendantsAndSelf()->toHierarchy();
```

#### Tree operations on a collection

Materialized Model's HierarchyCollection can be instantiated with a collection of nodes
and operations can be run against them, as long as the class name is set on the HierarchyCollection

```php
$nodes = ... // Collection of nodes retrieved from the database, by ids or some other means
$ancestors = (new HierarchyCollection($nodes))->setClassName(Category::class)->getAncestors();
// $ancestors will now contain all ancestors of all the nodes in the collection
```

The following operations are available on the HierarchyCollection:

* `getAncestorsAndSelves()`: Retrieve all of their ancestors including the current nodes.
* `getAncestors()`: Retrieve all of their ancestors.
* `getDescendantsAndSelves()`: Retrieve all nested children including the current nodes.
* `getDescendants()`: Retrieve all of their children & nested children.

<a name="node-model-events"></a>
### Model events: MaterializedModelMovedEvent

Materialized Model models dispatches a MaterializedModelMovedEvent whenever a model has moved in the hierarchy.

This event can be acted on by a Listener that can retrieve the moved model and its previous parent, if applicable, from the event.

<a name="rebuilding"></a>
### Tree rebuilding

Materialized Model supports rebuilding (or recalculating the paths) of a model and its children via the
`rebuild()` method.

This method will re-index all your `path` and `depth` column values,
inspecting your tree only from the parent <-> children relation
standpoint. Which means that you only need a correctly filled `parent_id` column
and Materialized Model will try its best to recompute the rest.

This can prove quite useful when something has gone horribly wrong with the index
values or it may come quite handy when *converting* from another implementation
(which would probably have a `parent_id` column).

Simple example usage, given a `Category` node class:

```php
Category::roots()->each->rebuild();
```

<a name="soft-deletes"></a>
### Soft deletes

Materialized Model does not handle soft deletes specifically, although it _should_ function as long as the parent of a restored node is not soft deleted.

<a name="misc-utilities"></a>
### Misc/Utility functions

#### Node extraction query scopes

Materialized Model provides some query scopes which may be used to extract (remove) selected nodes
from the current results set.

* `withoutNode(node)`: Excludes the specified node from the current results set.
* `withoutNodes(nodes)`: Excludes the specified collection of nodes from the current result set.
* `withoutSelf()`: Excludes itself from the current results set.

```php
$node = Category::where('name', '=', 'Some category I do not want to see.')->first();

$root = Category::where('name', '=', 'Old boooks')->first();
var_dump($root->descendantsAndSelf()->withoutNode($node)->get());
... // <- This result set will not contain $node
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

<a name="contributing"></a>
## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@vicklr.com instead of using the issue tracker.

## Credits

- [Estanislau Trepat](https://github.com/etrepat) for his Baum package
- [Freek Van der Herten](https://github.com/freekmurze) and [Spatie](https://spatie.be) for inspiration to the documentation

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
