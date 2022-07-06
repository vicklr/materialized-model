# Changelog

All notable changes to `materialized-model` will be documented in this file.

## 2.0.0
- Refactored numerical automatically ordered paths into its own trait that extends the regular paths trait
- Added infection configuration and tests to ensure every case is tested thoroughly
- Removed setLevel on HasMaterializedPaths trait that were not really intended to be used and could be removed
- Changed public setDepth and setPath methods to be protected as they are not intended to be used outside implementing classes
- Changed public reorderChildren method to be protected (and on the HasOrderedMaterializedPaths trait) as it was not intended to be used outside implementing classes

## 1.0.2
- Added documentation on HierarchyCollection
- Corrected bugs in HierarchyCollection methods for fetching selves with descendants or ancestors

##  1.0.1
- Corrected documentation on the use of HasMaterializedPaths traits and MaterializedModel parent class

##  1.0.0
- Initial release
