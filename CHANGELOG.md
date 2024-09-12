#### v1.6.0
- Show tree search even if all child elements fit on one page (according to `tree_paging_limit`) - if there are at least 30 children in total
- Prevent accidental deletion of folder if items get selected in grid and then the "Delete folder" button gets clicked. Instead: If grid items are selected, delete button asks if the selected items should be deleted. If no grid items are selected, the folder gets deleted (after confirmation).

#### v1.5.0
- [Assets] Metadata can be now displayed as a read-only tab when the user is granted `view` permissions to the asset.
- [Composer] Added `phpoffice/phpspreadsheet` requirement (which got moved out from `pimcore/pimcore`) and extended support to `v2`.
- [Date & date/time fields] Date & date/time fields are now configured with `date` and `datetime` column type by default.
- [Date/time fields] Date/time fields now support the usage without timezone support.
- [Icons] Overhauled Icon library and icon dropdown selector in class definition editor.
- [System Settings] Removed "Default-Language in Admin-Interface" setting.
- [Security] Add CSP configuration option `frame-ancestors` (default: `self`).

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
