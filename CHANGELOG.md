#### v1.2.0
 - [UI][tree][permission] When only granting Asset/Object/Document permissions without defining any Workspaces, the tree sidebar will display with the corresponding header and home icon, instead of an empty sidebar.

#### v1.1.0
 - `Pimcore\Bundle\AdminBundle\Service\ElementService` is marked as internal.
 - Deprecated `DocumentTreeConfigTrait`
 - Added `pimcore.events.prepareAffectedNodes` js event to extend node refresh on tree update. It can be used to 
   extends the affected nodes array on `pimcore.elementservice.getAffectedNodes`.
 - Added `pimcore_editor_tabbar` css class to element tab bars.
