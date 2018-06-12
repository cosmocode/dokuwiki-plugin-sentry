/**
 * Object for logging to sentry
 */
var SentryPlugin = (function () {

    /**
     * Log a javascript error to the server
     *
     * @see https://docs.sentry.io/clientdev/attributes/ for for supported attributes in data
     * @param {Error} e The error to log
     * @param {object} data Any additional data to pass in the sentry event
     */
    var logSentryException = function (e, data) {

        data.request = {
            url: window.location.href,
            headers: {
                referer: document.referrer
            }
        };

        jQuery.post(DOKU_BASE + 'lib/exe/ajax.php', {
            'call': 'plugin_sentry',
            'name': e.name,
            'message': e.message,
            'stack': e.stack,
            'id': JSINFO.id,
            'additionalData': data
        });
    };

    // register as global error handler
    var originalErrorHandler = window.onerror;
    window.onerror = function (msg, url, lineNo, columnNo, error) {
        logSentryException(error, {});
        originalErrorHandler(error);
    };

    // wrap around DokuWiki's plugin error handling
    var originalLogError = window.logError;
    window.logError = function (e, file) {
        logSentryException(e, {culprit: file});
        originalLogError(e, file);
    };

    // exports
    return {
        logSentryException: logSentryException
    }
})();
