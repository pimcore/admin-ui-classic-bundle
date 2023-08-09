#### v1.1.0
 - `Pimcore\Bundle\AdminBundle\Service\ElementService` is marked as internal.
 - Deprecated `DocumentTreeConfigTrait`
 - Added `pimcore.events.prepareAffectedNodes` js event to extend node refresh on tree update. It can be used to 
   extends the affected nodes array on `pimcore.elementservice.getAffectedNodes`.
 - Added `pimcore_editor_tabbar` css class to element tab bars.
