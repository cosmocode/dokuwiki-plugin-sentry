/**
 * Log a javascript error to the server
 *
 * @param {Error} e
 * @param {object} data
 */
function logSentryException(e, data) {
    jQuery.post(DOKU_BASE + 'lib/exe/ajax.php',{
        'call': 'plugin_sentry',
        'name': e.name,
        'message': e.message,
        'stack': e.stack,
        'id': JSINFO.id,
        'additionalData': data,
    });
}

const originalErrorHandler = window.onerror;
window.onerror = function(msg, url, lineNo, columnNo, error) {
    logSentryException(error, {});
    originalErrorHandler(error);
};
