define([
    'mage/storage'
], function (storage) {
    'use strict'
    return function (config, element) {
        storage.get('/rest/' + config.storeCode + '/V1/rvvup/clearpay')
            .done(function(res) {
                if (res === true) {
                    document.getElementById('clearpay-summary').removeAttribute('style');
                }
            })
    }
});
