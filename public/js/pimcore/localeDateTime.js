/**
* This source file is available under the terms of the
* Pimcore Open Core License (POCL)
* Full copyright and license information is available in
* LICENSE.md which is distributed with this source code.
*
*  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.com)
*  @license    Pimcore Open Core License (POCL)
*/

pimcore.registerNS("pimcore.localeDateTime");

pimcore.localeDateTime = {
    shortDate: 'Y-m-d',
    shortTime: 'H:i', // Short time is without seconds
    mediumTime: 'H:i:s',
    systemShortDate: 'Y-m-d',
    systemShortTime: 'H:i',
    systemMediumTime: 'H:i:s',

    getShortDateFormat: function () {
        return this.shortDate;
    },
    getShortTimeFormat: function () {
        return this.shortTime;
    },
    getMediumTimeFormat: function () {
        return this.mediumTime;
    },
    getShortDateTimeFormat: function () {
        return this.shortDate + ' ' + this.shortTime;
    },
    getDateTimeFormat: function () {
        return this.shortDate + ' ' + this.mediumTime;
    },

    // Set the default date and time format based on the given locale and override forms/fields globally
    setDefaultDateTime: function (locale) {
        if (locale) {
            this.shortDate = this.convertDateFormatFromIntl(locale);
            this.shortTime = this.convertTimeFroamtFromIntl(locale, 'short');
            this.mediumTime = this.convertTimeFroamtFromIntl(locale, 'medium');
        }

        if (Ext.util && Ext.util.Format) {
            Ext.apply(Ext.util.Format, {
                dateFormat: this.getShortDateFormat()
            })
        }
        Ext.override(Ext.form.field.Date, {
            format: this.getShortDateFormat(),
        });

        Ext.override(Ext.grid.column.Date, {
            format: this.getShortDateFormat(),
        });

        Ext.override(Ext.form.field.Time, {
            format: this.getShortTimeFormat(),
        });
    },

    // Returns the EXT JS date format equivalent based on the localized date by Intl.DateTimeFormat
    // It checks wheter it is leading zero or 4 digits years by guessing it by checking the output of a dummy date
    convertDateFormatFromIntl: function (locale) {
        const dateFormatter = new Intl.DateTimeFormat(locale, {dateStyle: "short"});
        const localizedDate = dateFormatter.format(new Date('2021-06-03'));
        let dayFormat = 'j'; //no leading zero
        let monthFormat = 'n'; //no leading zero
        let yearFormat = 'y'; // 2 digits year

        if (localizedDate.includes('2021')) {
            yearFormat = 'Y';
        }
        if (localizedDate.includes('03')) {
            dayFormat = 'd';
        }
        if (localizedDate.includes('06')) {
            monthFormat = 'm';
        }

        const getPatternForPart = (part) => {
            switch (part.type) {
                case 'day':
                    return dayFormat;
                case 'month':
                    return monthFormat;
                case 'year':
                    return yearFormat;
                case 'literal':
                    return part.value;
                default:
                    console.log('Unsupported date part', part);
                    return '';
            }
        };
        return dateFormatter.formatToParts()
            .map(getPatternForPart)
            .join('');
    },

    // Returns the EXT JS time format equivalent based on the localized date by Intl.DateTimeFormat
    // It checks wheter it is 0-12 AM/PM, 0-23. Minutes and seconds are internationally with a leading zero as 00-59.
    convertTimeFroamtFromIntl: function (locale, timeStyle) {
        const dummyDate = new Date('2020-01-01 09:30:59');
        const dateFormatter = new Intl.DateTimeFormat(locale, {timeStyle: timeStyle});
        const localizedDateTime = dateFormatter.format(dummyDate);

        let dayPeriodFormat = '';
        if (localizedDateTime.includes('am') || localizedDateTime.includes('a.m.')) { //lowecased eg. am/pm
            dayPeriodFormat = 'a';
        } else if (localizedDateTime.includes('AM') || localizedDateTime.includes('A.M.')) {
            dayPeriodFormat = 'A';
        }

        let hourFormat = '';
        if (localizedDateTime.includes('09')) {
            hourFormat = 'H';
            if (dayPeriodFormat) {
                hourFormat = 'h';
            }
        } else if (localizedDateTime.includes('9')) {
            hourFormat = 'G';
            if (dayPeriodFormat) {
                hourFormat = 'g';
            }
        }

        const getPatternForPart = (part) => {
            switch (part.type) {
                case 'hour':
                    return hourFormat;
                case 'minute':
                    return 'i';
                case 'second':
                    return 's';
                case 'dayPeriod':
                    return dayPeriodFormat;
                case 'literal':
                    return part.value;
                default:
                    console.log('Unsupported date part', part);
                    return '';
            }
        };

        return dateFormatter.formatToParts()
            .map(getPatternForPart)
            .join('');
    }
};
