/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

pimcore.registerNS("pimcore.element.tag.imagecropper");
/**
 * @private
 */
pimcore.element.tag.imagecropper = Class.create({
    initialize: function (imageId, data, saveCallback, config) {
        this.editWindow = null;
        this.imageId = imageId;
        this.data = data;
        this.modal = true;
        this.saveCallback = saveCallback;

        this.assetData = null;
        this.assetWidth = null;
        this.assetHeight = null;
        this.thumbnailData = null;
        this.thumbnailWidth = null;
        this.thumbnailHeight = null;
        this.promiseImage = null;
        this.promiseThumbnail = null;
        this.hasThumbnail = false;

        this.ratioX = null;
        this.ratioY = null;
        this.preserveRatio = false;

        this.alertMessage = '';

        this.allowedMethods = [
            'scaleByHeight',
            'scaleByWidth',
            'frame',
            'resize',
            'contain',
            'crop',
            'cover'
        ];

        // Has thumbnail, load asset and thumbnail data
        if (this.imageId && this.data.thumbnail) {
            this.promiseImage = this.loadAsset(this.imageId);
            this.promiseThumbnail = this.loadThumbnailData(this.data.thumbnail);
            this.hasThumbnail = true;
        }

        // Set ratio from config
        if(typeof config == "object") {
            if(config["ratioX"] && config["ratioY"]) {
                this.ratioX = config["ratioX"];
                this.ratioY = config["ratioY"];
                this.preserveRatio = true;
                this.hasThumbnail = false;
            }
        }
    },

    open: function (modal){
        // Set modal
        if(typeof modal != "undefined") {
            this.modal = modal;
        }

        if(this.hasThumbnail) {
            // Wait for thumbnail and asset data to be loaded
            Promise.all([this.promiseImage, this.promiseThumbnail]).then(this.createCropperWindow.bind(this));
        } else {
            this.createCropperWindow();
        }
    },

    createCropperWindow: function(){
        let button = {};
        const validImage = (typeof this.imageId != "undefined" && this.imageId !== null),
          imageUrl = Routing.generate('pimcore_admin_asset_getimagethumbnail', {id: this.imageId, width: 800, height: 600, contain: true});

        // Has thumbnail, set ratio (after thumbnail and asset data is loaded)
        if (this.hasThumbnail) {
            if ((this.thumbnailWidth && this.thumbnailHeight)) {
                this.ratioX = this.thumbnailWidth / this.thumbnailHeight;
                this.ratioY = 1;
                this.preserveRatio = true;
            } else {
                this.ratioX = this.assetWidth;
                this.ratioY = this.assetHeight;
                this.preserveRatio = false;
            }
        }

        if (validImage) {
            button = {
                xtype: "button",
                iconCls: "pimcore_icon_apply",
                text: t("save"),
                handler: function () {
                    const originalWidth = this.editWindow.body.getWidth();
                    const originalHeight = this.editWindow.body.getHeight();
                    const sel = Ext.get("selector");
                    this.data = {
                        cropWidth: sel.getWidth() * 100 / originalWidth,
                        cropHeight: sel.getHeight() * 100 / originalHeight,
                        cropTop: sel.getTop(true) * 100 / originalHeight,
                        cropLeft: sel.getLeft(true) * 100 / originalWidth,
                        cropPercent: true
                    };

                    if(typeof this.saveCallback == "function") {
                        this.saveCallback(this.data);
                    }

                    this.editWindow.close();
                }.bind(this)
            }
        }

        this.editWindow = new Ext.Window({
            width: 800,
            height: 600,
            modal: this.modal,
            resizable: false,
            bodyStyle: "background: url('/bundles/pimcoreadmin/img/tree-preview-transparent-background.png');",
            bbar: [
                {
                    xtype: 'tbtext',
                    id: 'alertMessage',
                    text: this.alertMessage,
                    style: {
                        'margin-right': '10px',
                        'color': '#FF0000',
                        'font-weight': 'bold'
                    }
                },
                "->",
                button],
            html: validImage ? '<img id="selectorImage" src="' + imageUrl + '" />' : '<span style="padding:10px;">' + t("no_data_to_display") + '</span>',
        });

        if(validImage) {
            this.editWindow.add({
                xtype: 'component',
                id: "selector",
                resizable: {
                    target: "selector",
                    pinned: true,
                    width: 100,
                    height: (100 / (this.ratioX * this.ratioY)) || 100,
                    preserveRatio: this.preserveRatio,
                    dynamic: true,
                    handles: 'all',
                },
                style: "cursor:move; position: absolute; top: 10px; left: 10px;z-index:9000;",
                draggable: true,
                listeners: {
                    resize: this.checkSize.bind(this),
                    move: this.checkSize.bind(this),
                }
            });

            this.editWindow.on("afterrender", this.setWindowSizes.bind(this));
            this.editWindow.show();
        }
    },

    checkSize: function()  {
        const sel = Ext.get("selector");
        const image = Ext.get("selectorImage");

        if(image && image.getWidth() > 30) {
            const imageFactor = this.assetWidth / image.getWidth();

            let sizeError = false;

            for (let i = 0; i < 2; i++) {
                // Has thumbnail
                if (this.hasThumbnail) {
                    if (this.thumbnailHeight && this.thumbnailHeight > this.assetHeight) {
                        // Is asset smaller than thumbnail, set size to asset size
                        this.alertMessage = 'crop_error_image_too_small'
                        sizeError = true;
                    } else if (this.thumbnailWidth && this.thumbnailWidth > this.assetWidth) {
                        // Is asset smaller than thumbnail, set size to asset size
                        this.alertMessage = 'crop_error_image_too_small'
                        sizeError = true;
                    }

                    // Selection width is smaller than the thumbnail settings
                    if (this.thumbnailWidth && (sel.getWidth() < (this.thumbnailWidth / imageFactor))) {
                        sizeError = true;
                        this.alertMessage = 'crop_error_selection_smaller_than_thumbnail'
                    }

                    // Selection height is smaller than the thumbnail settings
                    if (this.thumbnailHeight && (sel.getHeight() < (this.thumbnailHeight / imageFactor))) {
                        sizeError = true;
                        this.alertMessage = 'crop_error_selection_smaller_than_thumbnail'
                    }

                    // Max width and fix height for dimension
                    if (sel.getWidth() >= image.getWidth()) {
                        sel.setStyle("width", image.getWidth() + "px");

                        if(this.thumbnailHeight && this.thumbnailWidth) {
                            sel.setStyle("height", (image.getWidth() / this.ratioX * this.ratioY) + "px");
                        }
                    }

                    // Max height & fix width for dimension
                    if (sel.getHeight() >= image.getHeight()) {
                        sel.setStyle("height", image.getHeight() + "px");

                        if(this.thumbnailHeight && this.thumbnailWidth) {
                            sel.setStyle("width", (image.getHeight() / this.ratioY * this.ratioX) + "px");
                        }
                    }
                } else {
                    // check the ratio if given
                    if (this.ratioX && this.ratioY) {
                        if (sel.getHeight() > image.getHeight()) {
                            sel.setStyle("height", (sel.getWidth() * (this.ratioY / this.ratioX)) + "px");
                        } else if (sel.getWidth() > image.getWidth()) {
                            sel.setStyle("width", (sel.getHeight() * (this.ratioX / this.ratioY)) + "px");
                        }
                    }

                    // Max width
                    if (sel.getWidth() > image.getWidth()) {
                        sel.setStyle("width", image.getWidth() + "px");
                    }

                    // Max height
                    if (sel.getHeight() > image.getHeight()) {
                        sel.setStyle("height", image.getHeight() + "px");
                    }
                }

                // Limit top
                if (sel.getTop(true) < 0) {
                    sel.setStyle("top", "0");
                }

                // Limit left
                if (sel.getLeft(true) < 0) {
                    sel.setStyle("left", "0");
                }

                // Limit Bottom
                if (image.getHeight() && (sel.getTop(true) + sel.getHeight()) > image.getHeight()) {
                    sel.setStyle("top", (image.getHeight() - sel.getHeight() + "px"));
                }

                // Limit Right
                if ((sel.getLeft(true) + sel.getWidth()) > image.getWidth()) {
                    sel.setStyle("left", (image.getWidth() - sel.getWidth()) + "px");
                }
            }

            if(sizeError) {
                sel.addCls("x-resizable-handle-error");
                Ext.get("alertMessage").update(t(this.alertMessage));
            } else {
                sel.removeCls("x-resizable-handle-error");
                Ext.get("alertMessage").update('');
            }
        }
    },

    // Set window size
    setWindowSizes: function() {
        this.editWindowInitCount = 0;
        const editWindowInterval = window.setInterval(() => {
            const image = Ext.get("selectorImage");
            if(this.editWindow.body && image && (image.getWidth() > 30)) {
                clearInterval(editWindowInterval);

                // Set window size
                const winBodyInnerSize = this.editWindow.body.getSize();
                const winOuterSize = this.editWindow.getSize();
                const paddingWidth = winOuterSize["width"] - winBodyInnerSize["width"];
                const paddingHeight = winOuterSize["height"] - winBodyInnerSize["height"];
                this.editWindow.setSize(image.getWidth() + paddingWidth, image.getHeight() + paddingHeight);

                if(this.data && this.data["cropPercent"]) {
                    // Set selector size and position from saved data
                    const sel = Ext.get("selector").applyStyles({
                        width: (image.getWidth() * (this.data.cropWidth / 100)) + "px",
                        height: (image.getHeight() * (this.data.cropHeight / 100)) + "px",
                        top: (image.getHeight() * (this.data.cropTop / 100)) + "px",
                        left: (image.getWidth() * (this.data.cropLeft / 100)) + "px"
                    });

                    // Is thumbnail changed or old data, fix proportions
                    if (this.hasThumbnail
                      && this.thumbnailWidth && this.thumbnailHeight
                      && (sel.getWidth()/ sel.getHeight()) !== (this.ratioX / this.ratioY))
                    {
                        sel.setStyle("height", (sel.getWidth() / this.ratioX * this.ratioY) + "px");
                    }
                }

                this.checkSize();
                return;
            }else if (this.editWindowInitCount > 60) {
                // If more than 30 secs cancel and close the window
                clearInterval(editWindowInterval);
                this.editWindow.close();
            }

            this.editWindowInitCount++;
        }, 500);
    },

    // Load original asset data
    loadAsset: function(imageId) {
        return Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_asset_getdatabyid'),
            params: {
                id: imageId
            },
            success: function (response) {
                this.assetData = Ext.decode(response.responseText);
                if (this.assetData.imageInfo && this.assetData.imageInfo.dimensions) {
                    this.assetWidth = this.assetData.imageInfo.dimensions.width;
                    this.assetHeight = this.assetData.imageInfo.dimensions.height;
                }
            }.bind(this)
        });
    },

    // Load thumbnail data
    loadThumbnailData: function(thumbnailName) {
        return Ext.Ajax.request({
            url: Routing.generate('pimcore_admin_settings_thumbnailget'),
            params: {
                name: thumbnailName
            },
            success: function (response) {
                this.thumbnailData = Ext.decode(response.responseText);
                if (this.thumbnailData.items) {
                    Ext.each(this.thumbnailData.items, (item) => {
                        if (this.allowedMethods.includes(item.method) && item.arguments.width
                          && (!this.thumbnailWidth || this.thumbnailWidth > item.arguments.width)) {
                            this.thumbnailWidth = item.arguments.width;
                        }

                        if (this.allowedMethods.includes(item.method) && item.arguments.height
                          && (!this.thumbnailHeight || this.thumbnailHeight > item.arguments.height)) {
                            this.thumbnailHeight = item.arguments.height;
                        }
                    }, this);
                }
            }.bind(this)
        });
    },
});
