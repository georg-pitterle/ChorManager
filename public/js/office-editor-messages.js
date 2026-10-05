/**
 * Reine Nachrichtenlogik des Office-Editors - ohne DOM, damit sie unter `node --test`
 * prüfbar ist (tests/js/office-editor-messages.test.mjs). Im Browser hängt sie an
 * window.OfficeEditorMessages, in Node an module.exports.
 */
(function (global) {
    'use strict';

    /**
     * Was eine PostMessage von Collabora für die Seite bedeutet: 'ready', 'close'
     * oder null. Nachrichten anderer Herkunft werden nie ausgewertet.
     */
    function actionFor(origin, data, officeOrigin) {
        if (!officeOrigin || origin !== officeOrigin) {
            return null;
        }

        var message = data;
        if (typeof data === 'string') {
            try {
                message = JSON.parse(data);
            } catch (error) {
                return null;
            }
        }
        if (!message || typeof message.MessageId !== 'string') {
            return null;
        }

        if (message.MessageId === 'App_LoadingStatus' && message.Values && message.Values.Status === 'Frame_Ready') {
            return 'ready';
        }
        if (message.MessageId === 'UI_Close') {
            return 'close';
        }

        return null;
    }

    function hostMessage(messageId) {
        return JSON.stringify({ MessageId: messageId, SendTime: Date.now(), Values: {} });
    }

    var api = {
        actionFor: actionFor,
        hostMessage: hostMessage,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.OfficeEditorMessages = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
