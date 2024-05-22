#### v1.5.0
- [Assets] Metadata can be now displayed as a read-only tab when the user is granted `view` permissions to the asset.
  
#### v1.4.0
- [DataObject] Password data type algorithms other than `password_hash` are deprecated since `pimcore/pimcore:^11.2` and will be removed in `pimcore/pimcore:^12`.

#### v1.2.0
 - Bumped `pimcore/pimcore` minimum requirement to `^11.1.0`
 - DataObject used to automatically reload version after save, but now it's triggered only on successfull save. The reload can be forced by setting `forceReloadVersionsAfterSave` to `true` in a `postSaveObject` event listener.
 - [User -> Settings] When resetting password, setting the new password same as the old one would throw an error.

#### v1.1.0
 - `Pimcore\Bundle\AdminBundle\Service\ElementService` is marked as internal.
 - Deprecated `DocumentTreeConfigTrait`
 - Added `pimcore.events.prepareAffectedNodes` js event to extend node refresh on tree update. It can be used to 
   extends the affected nodes array on `pimcore.elementservice.getAffectedNodes`.
 - Added `pimcore_editor_tabbar` css class to element tab bars.
